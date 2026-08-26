<?php

namespace App\Enums;

enum WorkspaceProvisioningStatus: string
{
    case Pending = 'pending';
    case CheckoutCreated = 'checkout_created';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
