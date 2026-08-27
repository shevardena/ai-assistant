<?php

namespace App\Services\Widget;

use App\Enums\BotStatus;
use App\Models\Bot;
use App\Services\Api\LiveOperationCapabilityService;

class BotPublicAvailabilityService
{
    public function __construct(
        private readonly LiveOperationCapabilityService $liveOperations,
    ) {}

    /**
     * Return the minimum safe public runtime state without invoking the AI
     * runtime, creating records, or consuming usage.
     *
     * @return 'online'|'offline'
     */
    public function status(Bot $bot): string
    {
        return $this->isOnline($bot) ? 'online' : 'offline';
    }

    public function isOnline(Bot $bot): bool
    {
        if (in_array((string) $bot->status, [
            BotStatus::Ready->value,
            BotStatus::Published->value,
        ], true)) {
            return true;
        }

        return $this->liveOperations->has($bot, 'search_catalog');
    }
}
