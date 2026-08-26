<?php

namespace App\Services\Channels;

final class TelegramProviderErrorNormalizer
{
    /** @param array<string, mixed>|null $payload */
    public function normalize(int $status, ?array $payload): string
    {
        $providerCode = (int) data_get($payload, 'error_code', 0);
        $description = strtolower((string) data_get($payload, 'description', ''));

        return match (true) {
            $status === 401 || $providerCode === 401 => 'telegram_auth_failed',
            $status === 429 || $providerCode === 429 => 'telegram_rate_limited',
            str_contains($description, 'blocked') => 'telegram_recipient_unavailable',
            str_contains($description, 'chat not found') => 'telegram_invalid_chat',
            $status === 400 || $providerCode === 400 => 'telegram_message_rejected',
            $status >= 500 => 'telegram_unavailable',
            default => 'telegram_delivery_failed',
        };
    }
}
