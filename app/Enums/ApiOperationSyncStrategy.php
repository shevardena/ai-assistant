<?php

namespace App\Enums;

enum ApiOperationSyncStrategy: string
{
    case FullSnapshot = 'full_snapshot';
    case UpdatedSince = 'updated_since';
    case Cursor = 'cursor';
}
