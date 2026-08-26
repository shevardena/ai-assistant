<?php

namespace App\Services\Conversations\Blocks;

enum ConfirmationBlockStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
