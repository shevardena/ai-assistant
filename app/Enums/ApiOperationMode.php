<?php

namespace App\Enums;

enum ApiOperationMode: string
{
    case Read = 'read';
    case Write = 'write';
}
