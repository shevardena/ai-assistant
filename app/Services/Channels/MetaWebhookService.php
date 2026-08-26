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

final class MetaWebhookService
{
    public function __construct(
        private readonly InstagramChannelAdapter $instagram,
        private readonly FacebookMessengerChannelAdapter $facebookMessenger,
        private readonly ConversationService $conversations,
        private readonly ChannelDeliveryService $delivery,
        private readonly ActionConfirmationService $confirmations,
    ) {}

    public function verify(string $verifyToken, string $challenge): ?string
    {
        $credential = ChannelCredential::query()
            ->where('provider', 'meta')
            ->where('verify_token_hash', hash('sha256', $verifyToken))
            ->whereHas('channelConnection', function ($query): void {
                $query->whereIn('channel', [
                    ConversationChannel::Instagram->value,
                    ConversationChannel::FacebookMessenger->value,
                ])->where('status', '!=', 'disabled');
            })
            ->first();

        return $credential instanceof ChannelCredential ? $challenge : null;
    }

    public function handle(Request $request): void
    {
        $payload = $request->json()->all();
        $channel = $this->channelForObject($payload['object'] ?? null);

        if ($channel === null) {
            return;
        }

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $connection = $this->connectionFor($channel, $entry);

            if (! $connection instanceof ChannelConnection || $connection->status->value === 'disabled') {
                continue;
            }

            if (! $this->adapterFor($channel)->validSignature(
                $connection,
                $request->getContent(),
                $request->header('X-Hub-Signature-256'),
            )) {
                abort(403);
            }

            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                $this->processMessage($connection, $event);
            }
        }
    }

    private function processMessage(ChannelConnection $connection, mixed $event): void
    {
        if (! is_array($event)) {
            return;
        }

        $messageId = $this->messageId($event);

        if ($messageId === null || ! $this->claim($connection, $messageId)) {
            return;
        }

        $inbound = $this->adapterFor($connection->channel)->receive(['message' => $event]);

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
                logger()->warning('Meta outbound delivery failed.', [
                    'channel' => $connection->channel->value,
                    'connection_id' => $connection->public_id,
                    'error_code' => $result->errorCode,
                ]);
            }
        } catch (PlanLimitExceededException|AiException) {
            $result = $this->adapterFor($connection->channel)->send($connection, new ChannelOutboundMessage(
                channel: $connection->channel,
                text: 'This assistant is temporarily unavailable. Please try again later.',
                externalUserId: $inbound->externalUserId,
            ));

            if (! $result->successful) {
                logger()->warning('Meta quota fallback delivery failed.', [
                    'channel' => $connection->channel->value,
                    'connection_id' => $connection->public_id,
                    'error_code' => $result->errorCode,
                ]);
            }
        }

        $this->markProcessed($connection, $messageId);
    }

    /** @param array<string, mixed> $entry */
    private function connectionFor(ConversationChannel $channel, array $entry): ?ChannelConnection
    {
        $references = array_values(array_filter([
            $this->stringValue($entry['id'] ?? null),
            $this->stringValue(data_get($entry, 'messaging.0.recipient.id')),
        ]));

        if ($references === []) {
            return null;
        }

        return ChannelConnection::query()
            ->where('channel', $channel->value)
            ->where(function ($query) use ($references): void {
                $query->whereIn('provider_channel_reference', $references)
                    ->orWhereIn('provider_account_reference', $references);
            })
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

    private function channelForObject(mixed $object): ?ConversationChannel
    {
        return match ($object) {
            'instagram' => ConversationChannel::Instagram,
            'page' => ConversationChannel::FacebookMessenger,
            default => null,
        };
    }

    private function adapterFor(ConversationChannel $channel): AbstractMetaTextChannelAdapter
    {
        return match ($channel) {
            ConversationChannel::Instagram => $this->instagram,
            ConversationChannel::FacebookMessenger => $this->facebookMessenger,
            default => throw new \LogicException('Unsupported Meta messaging channel.'),
        };
    }

    /** @param array<string, mixed> $event */
    private function messageId(array $event): ?string
    {
        return $this->stringValue(data_get($event, 'message.mid'))
            ?? $this->stringValue(data_get($event, 'message.id'));
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

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
