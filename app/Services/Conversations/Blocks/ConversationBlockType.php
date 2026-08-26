<?php

namespace App\Services\Conversations\Blocks;

enum ConversationBlockType: string
{
    case ProductCards = 'product_cards';
    case Comparison = 'comparison';
    case OrderStatus = 'order_status';
    case Tracking = 'tracking';
    case Locations = 'locations';
    case Confirmation = 'confirmation';
    case Form = 'form';
    case AppointmentSlots = 'appointment_slots';
}
