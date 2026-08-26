<?php

use App\Enums\ChannelCapability;
use App\Enums\ConversationChannel;
use App\Services\Channels\ChannelRegistry;

test('channel registry exposes unique trusted definitions', function () {
    $definitions = app(ChannelRegistry::class)->all();
    $keys = array_map(static fn ($definition): string => $definition->key->value, $definitions);

    expect($keys)->toHaveCount(count(array_unique($keys)))
        ->and($keys)->toContain(
            ConversationChannel::Website->value,
            ConversationChannel::WhatsApp->value,
            ConversationChannel::Instagram->value,
            ConversationChannel::FacebookMessenger->value,
            ConversationChannel::Telegram->value,
            ConversationChannel::Sms->value,
            ConversationChannel::Email->value,
        );
});

test('website and Meta messaging channels are implemented with trusted capabilities', function () {
    $registry = app(ChannelRegistry::class);
    $website = $registry->find(ConversationChannel::Website);

    expect($website)->not->toBeNull()
        ->and($website->implemented)->toBeTrue()
        ->and($website->capabilities)->each->toBeInstanceOf(ChannelCapability::class)
        ->and($registry->find(ConversationChannel::WhatsApp)->implemented)->toBeTrue()
        ->and($registry->find(ConversationChannel::Instagram)->implemented)->toBeTrue()
        ->and($registry->find(ConversationChannel::FacebookMessenger)->implemented)->toBeTrue()
        ->and($registry->find(ConversationChannel::Telegram)->implemented)->toBeTrue()
        ->and($registry->find(ConversationChannel::Sms)->implemented)->toBeTrue()
        ->and($registry->find(ConversationChannel::Email)->implemented)->toBeTrue()
        ->and($registry->implemented())->toHaveCount(7);
});
