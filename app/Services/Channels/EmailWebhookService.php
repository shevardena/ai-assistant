<?php

namespace App\Services\Channels;

use App\Data\ChannelInboundMessage;
use App\Data\ChannelOutboundMessage;
use App\Enums\ConversationChannel;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Bot;
use App\Models\ChannelConnection;
use App\Models\ChannelMessageReceipt;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiException;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Conversations\ActionConfirmationService;
use App\Services\Conversations\ConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class EmailWebhookService
{
    public function __construct(
        private readonly PostmarkEmailChannelAdapter $email,
        private readonly ConversationService $conversations,
        private readonly ChannelDeliveryService $delivery,
        private readonly ActionConfirmationService $confirmations,
    ) {}

    public function handle(Request $request, string $publicId): void
    {
        $connection = ChannelConnection::query()
            ->where('public_id', $publicId)
            ->where('channel', ConversationChannel::Email->value)
            ->with(['credential', 'bot'])
            ->firstOrFail();

        if ($connection->status->value === 'disabled') {
            return;
        }

        abort_unless($this->email->validWebhook($request, $connection), 403);

        $payload = $request->json()->all();
        $inbound = $this->email->receive($payload);
        $inboundAddress = $this->email->configuredInboundAddress($connection);

        if (! $inbound instanceof ChannelInboundMessage
            || $inboundAddress === null
            || ! $this->email->hasConfiguredRecipient($payload, $inboundAddress)) {
            return;
        }

        if ($inbound->metadata['automated'] === true
            || strtolower($inbound->externalUserId) === strtolower((string) data_get($connection->configuration, 'from_address'))) {
            return;
        }

        $messageId = $inbound->externalMessageId;

        if ($messageId === null || ! $this->claim($connection, $messageId)) {
            return;
        }

        $bot = $connection->bot;

        try {
            $conversation = $this->conversations->findOrCreateChannelConversation($connection, $inbound);
            $this->rememberThread($conversation, $inbound);

            if ($inbound->metadata['attachment_only'] === true) {
                $message = $this->fallbackMessage($conversation, $inbound->text);
                $result = $this->delivery->sendAssistantMessage($conversation, $message);
            } elseif ($this->isConfirmationReply($inbound->text)) {
                $message = $this->confirmationReply($bot, $conversation, $inbound);
                $result = $this->delivery->sendAssistantMessage($conversation, $message);
            } else {
                $reply = $this->conversations->sendInboundMessage($bot, $conversation, $inbound);
                $result = $this->delivery->sendAssistantMessage($reply->conversation, $reply->assistantMessage);
            }

            if (! $result->successful) {
                logger()->warning('Email outbound delivery failed.', [
                    'channel' => ConversationChannel::Email->value,
                    'connection_id' => $connection->public_id,
                    'error_code' => $result->errorCode,
                ]);
            }
        } catch (PlanLimitExceededException|AiException) {
            $result = $this->email->send($connection, new ChannelOutboundMessage(
                channel: ConversationChannel::Email,
                text: 'This assistant is temporarily unavailable. Please try again later.',
                externalUserId: $inbound->externalUserId,
                metadata: $this->outboundMetadata($conversation ?? null),
            ));

            if (! $result->successful) {
                logger()->warning('Email quota fallback delivery failed.', [
                    'channel' => ConversationChannel::Email->value,
                    'connection_id' => $connection->public_id,
                    'error_code' => $result->errorCode,
                ]);
            }
        }

        $this->markProcessed($connection, $messageId);
    }

    private function claim(ChannelConnection $connection, string $messageId): bool
    {
        return ChannelMessageReceipt::query()->insertOrIgnore([
            'team_id' => $connection->team_id,
            'channel_connection_id' => $connection->id,
            'external_message_reference' => $messageId,
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    private function markProcessed(ChannelConnection $connection, string $messageId): void
    {
        ChannelMessageReceipt::query()
            ->where('channel_connection_id', $connection->id)
            ->where('external_message_reference', $messageId)
            ->update(['status' => 'processed', 'processed_at' => now()]);
    }

    private function rememberThread(Conversation $conversation, ChannelInboundMessage $inbound): void
    {
        $rawMetadata = $conversation->getAttribute('metadata');
        $metadata = is_array($rawMetadata) ? $rawMetadata : [];
        $references = array_values(array_unique(array_filter([
            $inbound->externalConversationId,
            data_get($inbound->metadata, 'email_message_id'),
            data_get($inbound->metadata, 'email_in_reply_to'),
            ...((array) data_get($inbound->metadata, 'email_references', [])),
            ...((array) data_get($metadata, 'email_thread_references', [])),
        ], static fn (mixed $reference): bool => is_string($reference) && $reference !== '')));

        $conversation->update([
            'metadata' => [
                ...$metadata,
                'email_subject' => data_get($inbound->metadata, 'email_subject'),
                'email_last_inbound_message_id' => data_get($inbound->metadata, 'email_message_id'),
                'email_thread_references' => array_slice($references, 0, 30),
            ],
        ]);
    }

    private function isConfirmationReply(string $text): bool
    {
        return in_array(Str::upper(trim($text)), ['YES', 'NO'], true);
    }

    private function confirmationReply(Bot $bot, Conversation $conversation, ChannelInboundMessage $inbound): Message
    {
        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'type' => 'text',
            'content' => $inbound->text,
            'channel_connection_id' => $conversation->channel_connection_id,
            'external_message_reference' => $inbound->externalMessageId,
            'metadata' => $inbound->metadata,
        ]);
        $conversation->update(['last_message_at' => now()]);
        $pending = $conversation->toolRuns()->where('status', 'pending_confirmation')->latest('id')->first();

        if ($pending === null) {
            return $this->fallbackMessage($conversation, 'Please reply to a current confirmation request with YES or NO.');
        }

        $result = Str::upper(trim($inbound->text)) === 'YES'
            ? $this->confirmations->confirm($bot, ToolExecutionContext::forBot($bot, $conversation, $userMessage), $pending->action_reference)
            : $this->confirmations->cancel($bot, ToolExecutionContext::forBot($bot, $conversation, $userMessage), $pending->action_reference);
        $content = (string) ($result->data['message'] ?? ($result->data['ok'] ?? false ? 'The action was completed.' : 'The action could not be completed.'));

        return $conversation->messages()->create([
            'role' => 'assistant',
            'type' => 'text',
            'content' => $content,
            'channel_connection_id' => $conversation->channel_connection_id,
            'metadata' => ['blocks' => $result->blocks],
        ]);
    }

    private function fallbackMessage(Conversation $conversation, string $content): Message
    {
        return $conversation->messages()->create([
            'role' => 'assistant',
            'type' => 'text',
            'content' => $content,
            'channel_connection_id' => $conversation->channel_connection_id,
        ]);
    }

    /** @return array<string, mixed> */
    private function outboundMetadata(?Conversation $conversation): array
    {
        if (! $conversation instanceof Conversation) {
            return [];
        }

        return [
            'email_subject' => data_get($conversation->metadata, 'email_subject'),
            'email_in_reply_to' => data_get($conversation->metadata, 'email_last_inbound_message_id'),
            'email_references' => data_get($conversation->metadata, 'email_thread_references', []),
        ];
    }
}
