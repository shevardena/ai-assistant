<?php

namespace App\Enums;

enum BotStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';

    /**
     * Legacy runtime state retained for existing records.
     */
    case Published = 'published';

    /**
     * Legacy runtime state retained for existing records.
     */
    case Disabled = 'disabled';
}
