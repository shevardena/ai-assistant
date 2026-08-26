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

final class SmsWebhookService
{
    public function __construct(
        private readonly TwilioSmsChannelAdapter $sms,
        private readonly ConversationService $conversations,
        private readonly ChannelDeliveryService $delivery,
        private readonly ActionConfirmationService $confirmations,
    ) {}

    public function handle(Request $request, string $publicId): void
    {
        $connection = ChannelConnection::query()
            ->where('public_id', $publicId)
            ->where('channel', ConversationChannel::Sms->value)
            ->with(['credential', 'bot'])
            ->firstOrFail();

        if ($connection->status->value === 'disabled') {
            return;
        }

        abort_unless($this->sms->validSignature($request, $connection), 403);

        $payload = $request->request->all();
        $inbound = $this->sms->receive($payload);

        if (! $inbound instanceof ChannelInboundMessage
            || $this->sms->configuredPhoneNumber($connection) !== ($payload['To'] ?? null)) {
            return;
        }

        $messageId = $inbound->externalMessageId;

        if ($messageId === null || ! $this->claim($connection, $messageId)) {
            return;
        }

        if ($this->isOptOut($inbound->text)) {
            $this->markProcessed($connection, $messageId);

            return;
        }

        $bot = $connection->bot;

        try {
            $conversation = $this->conversations->findOrCreateChannelConversation($connection, $inbound);

            if ($this->isConfirmationReply($inbound->text)) {
                $assistantMessage = $this->confirmationReply($bot, $conversation, $inbound);
                $result = $this->delivery->sendAssistantMessage($conversation, $assistantMessage);
            } else {
                $reply = $this->conversations->sendInboundMessage($bot, $conversation, $inbound);
                $result = $this->delivery->sendAssistantMessage($reply->conversation, $reply->assistantMessage);
            }

            if (! $result->successful) {
                logger()->warning('SMS outbound delivery failed.', [
                    'channel' => ConversationChannel::Sms->value,
                    'connection_id' => $connection->public_id,
                    'error_code' => $result->errorCode,
                ]);
            }
        } catch (PlanLimitExceededException|AiException) {
            $result = $this->sms->send($connection, new ChannelOutboundMessage(
                channel: ConversationChannel::Sms,
                text: 'This assistant is temporarily unavailable. Please try again later.',
                externalUserId: $inbound->externalUserId,
            ));

            if (! $result->successful) {
                logger()->warning('SMS quota fallback delivery failed.', [
                    'channel' => ConversationChannel::Sms->value,
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
            ->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);
    }

    private function isConfirmationReply(string $text): bool
    {
        return in_array(Str::upper(trim($text)), ['YES', 'NO'], true);
    }

    private function isOptOut(string $text): bool
    {
        return in_array(Str::upper(trim($text)), [
            'STOP',
            'UNSUBSCRIBE',
            'CANCEL',
            'END',
            'QUIT',
        ], true);
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
}
