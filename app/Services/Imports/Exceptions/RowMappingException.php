<?php

namespace App\Services\Imports\Exceptions;

use RuntimeException;

class RowMappingException extends RuntimeException
{
    /**
     * @param  list<array{
     *     field: string,
     *     stage: string,
     *     source_field: string|null,
     *     mapped_key: string|null,
     *     raw_value: scalar|null,
     *     normalized_value: scalar|null,
     *     error_code: string,
     *     message: string,
     * }>  $errors
     */
    public function __construct(
        public readonly array $errors,
        public readonly ?string $externalId = null,
    ) {
        parent::__construct('The source row contains invalid mapped values.');
    }
}
