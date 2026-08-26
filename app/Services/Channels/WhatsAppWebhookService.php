<?php

namespace App\Services\Channels;

use App\Data\ChannelInboundMessage;
use App\Data\ChannelOutboundMessage;
use App\Enums\ConversationChannel;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Bot;
use App\Models\ChannelConnection;
use App\Models\ChannelCredential;
use App\Models\ChannelMessageReceipt;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiException;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Conversations\ActionConfirmationService;
use App\Services\Conversations\ConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class WhatsAppWebhookService
{
    public function __construct(
        private readonly WhatsAppChannelAdapter $adapter,
        private readonly ConversationService $conversations,
        private readonly ChannelDeliveryService $delivery,
        private readonly ActionConfirmationService $confirmations,
    ) {}

    public function verify(string $verifyToken, string $challenge): ?string
    {
        $credential = ChannelCredential::query()
            ->where('provider', 'whatsapp')
            ->where('verify_token_hash', hash('sha256', $verifyToken))
            ->whereHas('channelConnection', function ($query): void {
                $query->where('channel', ConversationChannel::WhatsApp->value)
                    ->where('status', '!=', 'disabled');
            })
            ->first();

        return $credential instanceof ChannelCredential ? $challenge : null;
    }

    public function handle(Request $request): void
    {
        $payload = $request->json()->all();

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $value = is_array($change) ? ($change['value'] ?? []) : [];

                if (! is_array($value)) {
                    continue;
                }

                $connection = $this->connectionFor($value['metadata']['phone_number_id'] ?? null);

                if (! $connection instanceof ChannelConnection) {
                    continue;
                }

                if ($connection->status->value === 'disabled') {
                    continue;
                }

                if (! $this->adapter->validSignature($connection, $request->getContent(), $request->header('X-Hub-Signature-256'))) {
                    abort(403);
                }

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $this->processMessage($connection, $message);
                }
            }
        }
    }

    private function processMessage(ChannelConnection $connection, mixed $message): void
    {
        if (! is_array($message)) {
            return;
        }

        $messageId = is_scalar($message['id'] ?? null) ? (string) $message['id'] : null;

        if ($messageId === null || $messageId === '') {
            return;
        }

        if (! $this->claim($connection, $messageId)) {
            return;
        }

        $inbound = $this->adapter->receive(['message' => $message]);

        if (! $inbound instanceof ChannelInboundMessage) {
            $this->markProcessed($connection, $messageId);

            return;
        }

        $bot = $connection->bot;

        try {
            $conversation = $this->conversations->findOrCreateChannelConversation($connection, $inbound);

            if ($this->isConfirmationReply($inbound->text)) {
                $reply = $this->confirmationReply($bot, $conversation, $inbound);
                $result = $this->delivery->sendAssistantMessage($conversation, $reply);
            } else {
                $reply = $this->conversations->sendInboundMessage($bot, $conversation, $inbound);
                $result = $this->delivery->sendAssistantMessage($reply->conversation, $reply->assistantMessage);
            }

            if (! $result->successful) {
                logger()->warning('WhatsApp outbound delivery failed.', [
                    'channel' => ConversationChannel::WhatsApp->value,
                    'connection_id' => $connection->public_id,
                    'error_code' => $result->errorCode,
                ]);
            }
        } catch (PlanLimitExceededException|AiException) {
            $result = $this->adapter->send($connection, new ChannelOutboundMessage(
                channel: ConversationChannel::WhatsApp,
                text: 'This assistant is temporarily unavailable. Please try again later.',
                externalUserId: $inbound->externalUserId,
            ));

            if (! $result->successful) {
                logger()->warning('WhatsApp quota fallback delivery failed.', [
                    'channel' => ConversationChannel::WhatsApp->value,
                    'connection_id' => $connection->public_id,
                    'error_code' => $result->errorCode,
                ]);
            }
        }

        $this->markProcessed($connection, $messageId);
    }

    private function connectionFor(mixed $phoneNumberId): ?ChannelConnection
    {
        if (! is_scalar($phoneNumberId) || (string) $phoneNumberId === '') {
            return null;
        }

        return ChannelConnection::query()
            ->where('channel', ConversationChannel::WhatsApp->value)
            ->where('provider_channel_reference', (string) $phoneNumberId)
            ->with(['credential', 'bot'])
            ->first();
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
            ->update([
                'status' => 'processed',
                'processed_at' => now(),
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
            'metadata' => [
                'blocks' => $result->blocks,
            ],
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
}
