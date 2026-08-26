<?php

namespace App\Services\Channels;

use App\Data\ChannelInboundMessage;
use App\Data\ChannelOutboundMessage;
use App\Enums\ConversationChannel;
use App\Models\ChannelConnection;
use App\Services\Channels\Contracts\ChannelAdapter;
use Illuminate\Support\Str;

abstract class AbstractMetaTextChannelAdapter implements ChannelAdapter
{
    protected const MAX_TEXT_LENGTH = 2000;

    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly MetaWebhookSignatureValidator $signatures,
    ) {}

    /** @param array<string, mixed> $payload */
    public function receive(array $payload): ?ChannelInboundMessage
    {
        $message = $payload['message'] ?? null;

        if (! is_array($message)) {
            return null;
        }

        $messageId = $this->stringValue(data_get($message, 'message.mid'))
            ?? $this->stringValue(data_get($message, 'message.id'))
            ?? $this->stringValue(data_get($message, 'mid'))
            ?? $this->stringValue(data_get($message, 'id'));
        $sender = $this->stringValue(data_get($message, 'sender.id'));
        $text = $this->stringValue(data_get($message, 'message.text'))
            ?? $this->stringValue(data_get($message, 'text.body'));

        if ($messageId === null || $sender === null || $text === null || trim($text) === '') {
            return null;
        }

        return new ChannelInboundMessage(
            channel: $this->channel(),
            externalConversationId: $sender,
            externalUserId: $sender,
            text: Str::limit(trim($text), static::MAX_TEXT_LENGTH, ''),
            metadata: [
                'provider_timestamp' => $this->stringValue(data_get($message, 'timestamp')),
            ],
            externalMessageId: $messageId,
        );
    }

    public function send(ChannelConnection $connection, ChannelOutboundMessage $message): ChannelDeliveryResult
    {
        $credential = $connection->credential;
        $recipient = $message->externalUserId;
        $resourceReference = $connection->provider_channel_reference;

        if ($connection->channel !== $this->channel()
            || $credential === null
            || $credential->provider !== 'meta'
            || (int) $credential->team_id !== (int) $connection->team_id
            || (int) $credential->channel_connection_id !== (int) $connection->id
            || $credential->encrypted_access_token === ''
            || $recipient === null || $recipient === ''
            || $resourceReference === null || $resourceReference === '') {
            return ChannelDeliveryResult::failure('meta_unavailable');
        }

        $lastReference = null;

        foreach ($this->splitText($message->text) as $text) {
            $result = $this->graph->post(
                $resourceReference,
                $credential->encrypted_access_token,
                $this->payload($recipient, $text),
                ['message_id', 'messages.0.id'],
            );

            if (! $result->successful) {
                return ChannelDeliveryResult::failure($result->errorCode ?? 'meta_delivery_failed');
            }

            $lastReference = $result->providerMessageReference ?? $lastReference;
        }

        return ChannelDeliveryResult::success($lastReference);
    }

    public function validSignature(ChannelConnection $connection, string $rawBody, ?string $signature): bool
    {
        return $this->signatures->valid($connection, $rawBody, $signature);
    }

    abstract protected function channel(): ConversationChannel;

    /** @return array<string, mixed> */
    abstract protected function payload(string $recipient, string $text): array;

    /** @return list<string> */
    private function splitText(string $text): array
    {
        $text = trim($text);

        if (Str::length($text) <= static::MAX_TEXT_LENGTH) {
            return [$text];
        }

        $parts = [];

        while (Str::length($text) > static::MAX_TEXT_LENGTH) {
            $part = Str::substr($text, 0, static::MAX_TEXT_LENGTH);
            $breakAt = mb_strrpos($part, ' ');

            if ($breakAt === false || $breakAt < (static::MAX_TEXT_LENGTH - 500)) {
                $breakAt = static::MAX_TEXT_LENGTH;
            }

            $parts[] = trim(Str::substr($text, 0, $breakAt));
            $text = ltrim(Str::substr($text, $breakAt));
        }

        if ($text !== '') {
            $parts[] = $text;
        }

        return $parts;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
