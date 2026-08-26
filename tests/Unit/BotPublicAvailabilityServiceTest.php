<?php

use App\Enums\BotStatus;
use App\Models\Bot;
use App\Services\Widget\BotPublicAvailabilityService;

test('only ready and legacy published bots are online publicly', function () {
    $service = app(BotPublicAvailabilityService::class);

    foreach ([BotStatus::Ready, BotStatus::Published] as $status) {
        $bot = new Bot;
        $bot->status = $status->value;

        expect($service->status($bot))->toBe('online')
            ->and($service->isOnline($bot))->toBeTrue();
    }

    foreach ([BotStatus::Draft, BotStatus::Disabled] as $status) {
        $bot = new Bot;
        $bot->status = $status->value;

        expect($service->status($bot))->toBe('offline')
            ->and($service->isOnline($bot))->toBeFalse();
    }
});
