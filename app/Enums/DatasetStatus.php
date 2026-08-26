<?php

namespace App\Enums;

enum DatasetStatus: string
{
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Processing = 'processing';
    case Error = 'error';

    /**
     * Legacy in-progress state retained for existing records.
     */
    case Indexing = 'indexing';
}
