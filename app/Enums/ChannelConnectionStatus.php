<?php

namespace App\Enums;

enum ChannelConnectionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Error = 'error';
    case Disabled = 'disabled';
}
