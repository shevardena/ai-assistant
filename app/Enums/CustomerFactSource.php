<?php

namespace App\Enums;

enum CustomerFactSource: string
{
    case Manual = 'manual';
    case Conversation = 'conversation';
    case Lead = 'lead';
    case Appointment = 'appointment';
    case SupportTicket = 'support_ticket';
    case Imported = 'imported';
}
