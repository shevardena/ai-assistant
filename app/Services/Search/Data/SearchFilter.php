<?php

namespace App\Services\Search\Data;

use App\Services\Search\Enums\SearchOperator;

final readonly class SearchFilter
{
    public function __construct(
        public string $field,
        public SearchOperator $operator,
        public mixed $value,
    ) {}
}
