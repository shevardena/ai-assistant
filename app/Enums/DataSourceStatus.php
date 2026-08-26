<?php

namespace App\Enums;

enum DataSourceStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Syncing = 'syncing';
    case Error = 'error';

    /**
     * Legacy state retained for existing records.
     */
    case Disabled = 'disabled';
}
