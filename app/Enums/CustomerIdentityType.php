<?php

namespace App\Enums;

enum CustomerIdentityType: string
{
    case Email = 'email';
    case Phone = 'phone';
    case ChannelUser = 'channel_user';
}
