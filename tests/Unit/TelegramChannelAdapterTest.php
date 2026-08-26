<?php

use App\Enums\ConversationChannel;
use App\Services\Channels\TelegramBotApiClient;
use App\Services\Channels\TelegramChannelAdapter;
use App\Services\Channels\TelegramProviderErrorNormalizer;

test('Telegram adapter normalizes private text updates and ignores group chats', function () {
    $adapter = new TelegramChannelAdapter(new TelegramBotApiClient(new TelegramProviderErrorNormalizer));

    $message = $adapter->receive([
        'update_id' => 7001,
        'message' => [
            'message_id' => 42,
            'from' => [
                'id' => 9001,
                'first_name' => 'Jane',
                'username' => 'jane',
            ],
            'chat' => ['id' => 9001, 'type' => 'private'],
            'date' => 1787480000,
            'text' => '/start',
        ],
    ]);

    expect($message?->channel)->toBe(ConversationChannel::Telegram)
        ->and($message?->externalConversationId)->toBe('9001')
        ->and($message?->externalUserId)->toBe('9001')
        ->and($message?->externalMessageId)->toBe('7001')
        ->and($message?->text)->toBe('/start')
        ->and($message?->metadata['display_name'])->toBe('Jane')
        ->and($adapter->receive([
            'update_id' => 7002,
            'message' => [
                'message_id' => 43,
                'from' => ['id' => 9001],
                'chat' => ['id' => -100, 'type' => 'group'],
                'text' => 'ignored',
            ],
        ]))->toBeNull();
});
