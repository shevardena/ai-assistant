<?php

namespace App\Services\Channels;

final class MetaProviderErrorNormalizer
{
    /** @param  array<string, mixed>|null  $payload */
    public function normalize(int $status, ?array $payload): string
    {
        $providerCode = (string) data_get($payload, 'error.code', '');

        return match (true) {
            $status === 401 || $status === 403 || $providerCode === '190' => 'meta_auth_failed',
            $status === 408 || $status === 429 => $status === 429 ? 'meta_rate_limited' : 'meta_timeout',
            in_array($providerCode, ['100', '131026'], true) => 'meta_invalid_recipient',
            $status === 400 => 'meta_message_rejected',
            $status >= 500 => 'meta_unavailable',
            default => 'meta_delivery_failed',
        };
    }
}
