<?php

namespace App\Enums;

enum RuntimeMode: string
{
    case Normal = 'normal';
    case Preview = 'preview';
    case Test = 'test';
}
