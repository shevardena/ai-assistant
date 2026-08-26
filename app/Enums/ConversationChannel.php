<?php

namespace App\Enums;

enum ConversationChannel: string
{
    case Website = 'website';
    case WhatsApp = 'whatsapp';
    case Instagram = 'instagram';
    case FacebookMessenger = 'facebook_messenger';
    case Telegram = 'telegram';
    case Sms = 'sms';
    case Email = 'email';
}
