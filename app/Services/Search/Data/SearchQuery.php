<?php

namespace App\Services\Search\Data;

final readonly class SearchQuery
{
    /**
     * @param  list<SearchFilter>  $filters
     * @param  list<SearchSort>  $sorts
     */
    public function __construct(
        public int $datasetId,
        public ?string $text = null,
        public array $filters = [],
        public array $sorts = [],
        public int $limit = 20,
    ) {}
}
