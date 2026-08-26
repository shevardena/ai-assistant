<?php

namespace App\Services\Channels;

use App\Data\ChannelInboundMessage;
use App\Data\ChannelOutboundMessage;
use App\Enums\ConversationChannel;
use App\Models\ChannelConnection;
use App\Services\Channels\Contracts\ChannelAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class WhatsAppChannelAdapter implements ChannelAdapter
{
    private const MAX_TEXT_LENGTH = 4096;

    public function __construct(
        private readonly ?HttpFactory $httpFactory = null,
        private readonly ?string $graphUrl = null,
        private readonly ?string $graphVersion = null,
        private readonly ?int $timeout = null,
        private readonly ?int $connectTimeout = null,
        private readonly ?MetaWebhookSignatureValidator $signatures = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public function receive(array $payload): ?ChannelInboundMessage
    {
        $message = $payload['message'] ?? null;

        if (! is_array($message) || ($message['type'] ?? null) !== 'text') {
            return null;
        }

        $messageId = $this->stringValue($message['id'] ?? null);
        $from = $this->stringValue($message['from'] ?? null);
        $text = $this->stringValue(data_get($message, 'text.body'));

        if ($messageId === null || $from === null || $text === null || trim($text) === '') {
            return null;
        }

        return new ChannelInboundMessage(
            channel: ConversationChannel::WhatsApp,
            externalConversationId: $from,
            externalUserId: $from,
            text: Str::limit(trim($text), self::MAX_TEXT_LENGTH, ''),
            metadata: [
                'provider_timestamp' => $this->stringValue($message['timestamp'] ?? null),
            ],
            externalMessageId: $messageId,
        );
    }

    public function send(ChannelConnection $connection, ChannelOutboundMessage $message): ChannelDeliveryResult
    {
        $credential = $connection->credential;
        $recipient = $message->externalUserId;
        $phoneNumberId = $connection->provider_channel_reference;

        if ($credential === null || (int) $credential->team_id !== (int) $connection->team_id
            || (int) $credential->channel_connection_id !== (int) $connection->id
            || $credential->encrypted_access_token === ''
            || $recipient === null || $recipient === '' || $phoneNumberId === null || $phoneNumberId === '') {
            return ChannelDeliveryResult::failure('whatsapp_unavailable');
        }

        $lastReference = null;

        try {
            foreach ($this->splitText($message->text) as $text) {
                $response = $this->request($credential->encrypted_access_token)
                    ->acceptJson()
                    ->timeout($this->timeout ?? (int) config('services.whatsapp.timeout', 8))
                    ->connectTimeout($this->connectTimeout ?? (int) config('services.whatsapp.connect_timeout', 3))
                    ->post($this->messagesUrl($phoneNumberId), [
                        'messaging_product' => 'whatsapp',
                        'recipient_type' => 'individual',
                        'to' => $recipient,
                        'type' => 'text',
                        'text' => [
                            'preview_url' => false,
                            'body' => $text,
                        ],
                    ]);

                if (! $response->successful()) {
                    return ChannelDeliveryResult::failure($this->errorCode($response->status(), $response->json()));
                }

                $lastReference = $this->stringValue(data_get($response->json(), 'messages.0.id'));
            }
        } catch (ConnectionException) {
            return ChannelDeliveryResult::failure('whatsapp_timeout');
        } catch (RequestException) {
            return ChannelDeliveryResult::failure('whatsapp_unavailable');
        }

        return ChannelDeliveryResult::success($lastReference);
    }

    public function validSignature(ChannelConnection $connection, string $rawBody, ?string $signature): bool
    {
        return ($this->signatures ?? app(MetaWebhookSignatureValidator::class))
            ->valid($connection, $rawBody, $signature);
    }

    private function messagesUrl(string $phoneNumberId): string
    {
        return rtrim($this->graphUrl ?? (string) config('services.whatsapp.graph_url'), '/')
            .'/'.trim($this->graphVersion ?? (string) config('services.whatsapp.graph_version'), '/')
            .'/'.rawurlencode($phoneNumberId).'/messages';
    }

    private function request(string $token): PendingRequest
    {
        return $this->httpFactory?->withToken($token) ?? Http::withToken($token);
    }

    /** @return list<string> */
    private function splitText(string $text): array
    {
        $text = trim($text);

        if (Str::length($text) <= self::MAX_TEXT_LENGTH) {
            return [$text];
        }

        $parts = [];

        while (Str::length($text) > self::MAX_TEXT_LENGTH) {
            $part = Str::substr($text, 0, self::MAX_TEXT_LENGTH);
            $breakAt = mb_strrpos($part, ' ');

            if ($breakAt === false || $breakAt < (self::MAX_TEXT_LENGTH - 500)) {
                $breakAt = self::MAX_TEXT_LENGTH;
            }

            $parts[] = trim(Str::substr($text, 0, $breakAt));
            $text = ltrim(Str::substr($text, $breakAt));
        }

        if ($text !== '') {
            $parts[] = $text;
        }

        return $parts;
    }

    /** @param array<string, mixed>|null $payload */
    private function errorCode(int $status, ?array $payload): string
    {
        $providerCode = (string) data_get($payload, 'error.code', '');

        return match (true) {
            $status === 401 || $status === 403 => 'whatsapp_auth_failed',
            $status === 429 => 'whatsapp_rate_limited',
            in_array($providerCode, ['131026', '100'], true) => 'whatsapp_invalid_recipient',
            $status === 400 => 'whatsapp_message_rejected',
            $status >= 500 => 'whatsapp_unavailable',
            default => 'whatsapp_delivery_failed',
        };
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
