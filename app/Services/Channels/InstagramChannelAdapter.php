<?php

namespace App\Services\Channels;

use App\Enums\ConversationChannel;

final class InstagramChannelAdapter extends AbstractMetaTextChannelAdapter
{
    protected function channel(): ConversationChannel
    {
        return ConversationChannel::Instagram;
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
