<?php

namespace App\Services\Channels;

final class SmsProviderErrorNormalizer
{
    /** @param array<string, mixed>|null $payload */
    public function normalize(int $status, ?array $payload): string
    {
        $providerCode = (string) data_get($payload, 'code', '');

        return match (true) {
            in_array($providerCode, ['20003', '20404'], true) || in_array($status, [401, 403], true) => 'sms_auth_failed',
            $status === 408 => 'sms_timeout',
            $status === 429 => 'sms_rate_limited',
            $providerCode === '21610' => 'sms_recipient_opted_out',
            in_array($providerCode, ['21211', '21614'], true) => 'sms_invalid_recipient',
            in_array($providerCode, ['21212', '21606'], true) => 'sms_invalid_sender',
            $status >= 500 => 'sms_provider_unavailable',
            $status === 400 => 'sms_message_rejected',
            default => 'sms_delivery_failed',
        };
    }
}
