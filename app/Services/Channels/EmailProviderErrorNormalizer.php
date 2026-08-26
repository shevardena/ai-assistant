<?php

namespace App\Services\Channels;

final class EmailProviderErrorNormalizer
{
    /** @param array<string, mixed>|null $payload */
    public function normalize(int $status, ?array $payload): string
    {
        $providerCode = (string) data_get($payload, 'ErrorCode', '');

        return match (true) {
            in_array($status, [401, 403], true) => 'email_auth_failed',
            $status === 408 => 'email_timeout',
            $status === 429 => 'email_rate_limited',
            in_array($status, [400, 422], true) || $providerCode === '300' => 'email_message_rejected',
            $status >= 500 => 'email_provider_unavailable',
            default => 'email_delivery_failed',
        };
    }
}
