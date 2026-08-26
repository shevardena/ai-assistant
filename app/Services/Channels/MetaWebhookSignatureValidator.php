<?php

namespace App\Services\Channels;

use App\Models\ChannelConnection;
use Illuminate\Support\Str;

final class MetaWebhookSignatureValidator
{
    public function valid(ChannelConnection $connection, string $rawBody, ?string $signature): bool
    {
        $credential = $connection->credential;
        $secret = $credential?->encrypted_app_secret;

        if ($credential === null
            || (int) $credential->team_id !== (int) $connection->team_id
            || (int) $credential->channel_connection_id !== (int) $connection->id
            || ! is_string($secret) || $secret === ''
            || ! is_string($signature) || ! Str::startsWith($signature, 'sha256=')) {
            return false;
        }

        return hash_equals(
            hash_hmac('sha256', $rawBody, $secret),
            Str::after($signature, 'sha256='),
        );
    }
}
