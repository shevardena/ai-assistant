<?php

namespace App\Enums;

enum TemplateSupportStatus: string
{
    case Supported = 'supported';
    case RequiresApi = 'requires_api';
    case FutureCustom = 'future_custom';
}
