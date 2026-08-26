<?php

namespace App\Services\Conversations\Blocks;

enum AppointmentSlotsStatus: string
{
    case Pending = 'pending';
    case Selected = 'selected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
