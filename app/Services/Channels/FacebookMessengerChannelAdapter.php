<?php

namespace App\Services\Channels;

use App\Enums\ConversationChannel;

final class FacebookMessengerChannelAdapter extends AbstractMetaTextChannelAdapter
{
    protected function channel(): ConversationChannel
    {
        return ConversationChannel::FacebookMessenger;
    }

    /** @return array<string, mixed> */
    protected function payload(string $recipient, string $text): array
    {
        return [
            'recipient' => ['id' => $recipient],
            'message' => ['text' => $text],
        ];
    }
}
