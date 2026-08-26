<?php

use App\Enums\ConversationChannel;
use App\Services\Channels\WhatsAppChannelAdapter;

test('WhatsApp adapter normalizes inbound text and ignores unsupported message types', function () {
    $adapter = new WhatsAppChannelAdapter;
    $message = $adapter->receive([
        'message' => [
            'id' => 'wamid.1',
            'from' => '995555000000',
            'type' => 'text',
            'text' => ['body' => 'Hello'],
        ],
    ]);

    expect($message)->not->toBeNull()
        ->and($message->channel)->toBe(ConversationChannel::WhatsApp)
        ->and($message->externalUserId)->toBe('995555000000')
        ->and($message->externalMessageId)->toBe('wamid.1')
        ->and($message->text)->toBe('Hello')
        ->and($adapter->receive(['message' => ['id' => 'wamid.2', 'type' => 'image']]))->toBeNull();
});
