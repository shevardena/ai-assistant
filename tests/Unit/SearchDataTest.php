<?php

use App\Services\Search\Data\SearchFilter;
use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Data\SearchResult;
use App\Services\Search\Data\SearchSort;
use App\Services\Search\Enums\SearchOperator;
use App\Services\Search\Enums\SearchSortDirection;
use Tests\TestCase;

uses(TestCase::class);

test('search criteria objects expose typed immutable values', function () {
    $filter = new SearchFilter('price', SearchOperator::LessThanOrEqual, 500);
    $sort = new SearchSort('price', SearchSortDirection::Ascending);
    $query = new SearchQuery(
        datasetId: 12,
        text: 'iphone',
        filters: [$filter],
        sorts: [$sort],
    );

    expect($query->datasetId)->toBe(12)
        ->and($query->text)->toBe('iphone')
        ->and($query->filters)->toBe([$filter])
        ->and($query->sorts)->toBe([$sort])
        ->and($query->limit)->toBe(20)
        ->and(SearchOperator::LessThanOrEqual->value)->toBe('lte')
        ->and(SearchSortDirection::Ascending->value)->toBe('asc');
});

test('search result preserves the engine result count and record list', function () {
    $result = new SearchResult([], 0);

    expect($result->records)->toBe([])
        ->and($result->total)->toBe(0);
});
