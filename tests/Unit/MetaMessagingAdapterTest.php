<?php

use App\Enums\ConversationChannel;
use App\Services\Channels\FacebookMessengerChannelAdapter;
use App\Services\Channels\InstagramChannelAdapter;
use App\Services\Channels\MetaGraphClient;
use App\Services\Channels\MetaProviderErrorNormalizer;
use App\Services\Channels\MetaWebhookSignatureValidator;

test('Instagram adapter normalizes text and ignores unsupported messages', function () {
    $adapter = new InstagramChannelAdapter(
        new MetaGraphClient(new MetaProviderErrorNormalizer),
        new MetaWebhookSignatureValidator,
    );

    $message = $adapter->receive([
        'message' => [
            'sender' => ['id' => 'ig-user-1'],
            'message' => ['mid' => 'ig-mid-1', 'text' => ' Hello Instagram '],
            'timestamp' => '1787480000',
        ],
    ]);

    expect($message?->channel)->toBe(ConversationChannel::Instagram)
        ->and($message?->externalUserId)->toBe('ig-user-1')
        ->and($message?->externalMessageId)->toBe('ig-mid-1')
        ->and($message?->text)->toBe('Hello Instagram')
        ->and($adapter->receive(['message' => ['sender' => ['id' => 'ig-user-1'], 'message' => ['mid' => 'ig-mid-2', 'attachments' => []]]]))->toBeNull();
});

test('Messenger adapter normalizes text messages', function () {
    $adapter = new FacebookMessengerChannelAdapter(
        new MetaGraphClient(new MetaProviderErrorNormalizer),
        new MetaWebhookSignatureValidator,
    );

    $message = $adapter->receive([
        'message' => [
            'sender' => ['id' => 'psid-123'],
            'message' => ['mid' => 'm-mid-1', 'text' => 'Hello Messenger'],
        ],
    ]);

    expect($message?->channel)->toBe(ConversationChannel::FacebookMessenger)
        ->and($message?->externalUserId)->toBe('psid-123')
        ->and($message?->externalMessageId)->toBe('m-mid-1')
        ->and($message?->text)->toBe('Hello Messenger');
});
