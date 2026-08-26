<?php

namespace App\Services\Search\Data;

use App\Models\DatasetRecord;

final readonly class SearchResult
{
    /**
     * @param  list<DatasetRecord>  $records
     */
    public function __construct(
        public array $records,
        public int $total,
    ) {}
}
