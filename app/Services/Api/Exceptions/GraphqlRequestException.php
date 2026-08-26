<?php

namespace App\Services\Api\Exceptions;

use RuntimeException;

final class GraphqlRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $errorType,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
