<?php

namespace App\Services\Imports\Exceptions;

use RuntimeException;

class RowMappingException extends RuntimeException
{
    /**
     * @param  list<array{field: string, message: string}>  $errors
     */
    public function __construct(
        public readonly array $errors,
        public readonly ?string $externalId = null,
    ) {
        parent::__construct('The source row contains invalid mapped values.');
    }
}
