<?php

namespace App\Services\Search\Data;

use App\Services\Search\Enums\SearchSortDirection;

final readonly class SearchSort
{
    public function __construct(
        public string $field,
        public SearchSortDirection $direction,
    ) {}
}
