<?php

namespace App\Services\Imports\Exceptions;

use RuntimeException;
use Throwable;

class ImportException extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly string $stage = 'parser',
        public readonly string $errorCode = 'import_failed',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
