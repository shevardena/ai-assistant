<?php

namespace App\Services\Widget;

use App\Enums\BotStatus;
use App\Models\Bot;

class BotPublicAvailabilityService
{
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
        return in_array((string) $bot->status, [
            BotStatus::Ready->value,
            BotStatus::Published->value,
        ], true);
    }
}
