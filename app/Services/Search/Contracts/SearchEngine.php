<?php

namespace App\Services\Search\Contracts;

use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Data\SearchResult;

interface SearchEngine
{
    public function search(SearchQuery $query): SearchResult;
}
