<?php

namespace App\Services\Conversations\Blocks;

enum FormBlockStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Cancelled = 'cancelled';
}
