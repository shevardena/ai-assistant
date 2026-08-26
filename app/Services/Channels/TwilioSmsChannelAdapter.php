<?php

namespace App\Services\Channels;

use App\Data\ChannelInboundMessage;
use App\Data\ChannelOutboundMessage;
use App\Enums\ConversationChannel;
use App\Models\ChannelConnection;
use App\Services\Channels\Contracts\ChannelAdapter;
use App\Services\Channels\Contracts\SmsProviderClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class TwilioSmsChannelAdapter implements ChannelAdapter
{
    private const MAX_TEXT_LENGTH = 1500;

    private const MAX_MESSAGES = 3;

    public function __construct(
        private readonly SmsProviderClient $provider,
        private readonly TwilioSignatureValidator $signatures,
    ) {}

    public function validateConnection(string $accountSid, string $authToken, string $phoneNumber): SmsProviderResult
    {
        return $this->provider->validate($accountSid, $authToken, $phoneNumber);
    }

    /** @param array<string, mixed> $payload */
    public function receive(array $payload): ?ChannelInboundMessage
    {
        $messageSid = $this->stringValue($payload['MessageSid'] ?? null);
        $from = $this->stringValue($payload['From'] ?? null);
        $to = $this->stringValue($payload['To'] ?? null);
        $body = $this->stringValue($payload['Body'] ?? null);
        $mediaCount = (int) ($payload['NumMedia'] ?? 0);

        if ($messageSid === null || $from === null || $to === null || $body === null || trim($body) === '') {
            return null;
        }

        return new ChannelInboundMessage(
            channel: ConversationChannel::Sms,
            externalConversationId: $from,
            externalUserId: $from,
            text: Str::limit(trim($body), self::MAX_TEXT_LENGTH, ''),
            metadata: [
                'media_count' => max(0, $mediaCount),
                'provider_channel' => 'twilio',
            ],
            externalMessageId: $messageSid,
        );
    }

    public function send(ChannelConnection $connection, ChannelOutboundMessage $message): ChannelDeliveryResult
    {
        $credential = $connection->credential;
        $from = $this->configuredPhoneNumber($connection);
        $to = $message->externalUserId;

        if ($connection->channel !== ConversationChannel::Sms
            || $credential === null
            || $credential->provider !== 'twilio'
            || (int) $credential->team_id !== (int) $connection->team_id
            || (int) $credential->channel_connection_id !== (int) $connection->id
            || $credential->encrypted_access_token === ''
            || $credential->encrypted_verify_token === ''
            || $from === null
            || $to === null
            || $to === '') {
            return ChannelDeliveryResult::failure('sms_provider_unavailable');
        }

        $lastReference = null;

        foreach ($this->splitText($message->text) as $text) {
            $result = $this->provider->send(
                accountSid: $credential->encrypted_verify_token,
                authToken: $credential->encrypted_access_token,
                from: $from,
                to: $to,
                body: $text,
            );

            if (! $result->successful) {
                return ChannelDeliveryResult::failure($result->errorCode ?? 'sms_delivery_failed');
            }

            $lastReference = $result->providerMessageReference ?? $lastReference;
        }

        return ChannelDeliveryResult::success($lastReference);
    }

    public function validSignature(Request $request, ChannelConnection $connection): bool
    {
        $credential = $connection->credential;

        if ($credential === null
            || $credential->provider !== 'twilio'
            || (int) $credential->team_id !== (int) $connection->team_id
            || (int) $credential->channel_connection_id !== (int) $connection->id) {
            return false;
        }

        return $this->signatures->valid($request, $credential->encrypted_access_token);
    }

    public function configuredPhoneNumber(ChannelConnection $connection): ?string
    {
        $phoneNumber = data_get($connection->configuration, 'phone_number');

        return is_string($phoneNumber) && $phoneNumber !== '' ? $phoneNumber : null;
    }

    /** @return list<string> */
    private function splitText(string $text): array
    {
        $text = Str::limit(trim($text), self::MAX_TEXT_LENGTH * self::MAX_MESSAGES, '…');

        if (Str::length($text) <= self::MAX_TEXT_LENGTH) {
            return [$text];
        }

        $parts = [];

        while (Str::length($text) > self::MAX_TEXT_LENGTH && count($parts) < self::MAX_MESSAGES - 1) {
            $part = Str::substr($text, 0, self::MAX_TEXT_LENGTH);
            $breakAt = mb_strrpos($part, ' ');

            if ($breakAt === false || $breakAt < self::MAX_TEXT_LENGTH - 300) {
                $breakAt = self::MAX_TEXT_LENGTH;
            }

            $parts[] = trim(Str::substr($text, 0, $breakAt));
            $text = ltrim(Str::substr($text, $breakAt));
        }

        if ($text !== '') {
            $parts[] = Str::limit($text, self::MAX_TEXT_LENGTH, '…');
        }

        return $parts;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
