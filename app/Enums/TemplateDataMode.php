<?php

namespace App\Enums;

enum TemplateDataMode: string
{
    case Synced = 'synced';
    case Live = 'live';
    case Hybrid = 'hybrid';
}
