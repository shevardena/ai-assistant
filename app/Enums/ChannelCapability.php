<?php

namespace App\Enums;

enum ChannelCapability: string
{
    case Text = 'text';
    case Buttons = 'buttons';
    case Images = 'images';
    case ProductCards = 'product_cards';
    case Forms = 'forms';
    case FileAttachments = 'file_attachments';
    case TypingIndicator = 'typing_indicator';
    case Comparison = 'comparison';
    case OrderStatus = 'order_status';
    case Tracking = 'tracking';
    case Locations = 'locations';
    case Confirmation = 'confirmation';
    case AppointmentSlots = 'appointment_slots';
}
