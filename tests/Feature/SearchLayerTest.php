<?php

use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\Team;
use App\Services\Search\Data\SearchFilter;
use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Data\SearchSort;
use App\Services\Search\Enums\SearchOperator;
use App\Services\Search\Enums\SearchSortDirection;
use App\Services\Search\Exceptions\InvalidSearchCriteriaException;
use App\Services\Search\SearchService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @return array{0: Team, 1: Dataset}
 */
function searchDatasetFixture(?Team $team = null): array
{
    $team ??= Team::factory()->create();
    $dataset = Dataset::factory()->create(['team_id' => $team->id]);

    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'name',
        'source_path' => '$.name',
        'data_type' => 'string',
        'is_filterable' => true,
        'is_sortable' => true,
        'is_searchable' => true,
        'allowed_operators' => ['eq', 'neq', 'contains'],
    ]);

    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'price',
        'source_path' => '$.price',
        'data_type' => 'decimal',
        'is_filterable' => true,
        'is_sortable' => true,
        'allowed_operators' => ['gte', 'lte', 'eq'],
    ]);

    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'in_stock',
        'source_path' => '$.in_stock',
        'data_type' => 'boolean',
        'is_filterable' => true,
        'is_sortable' => true,
        'allowed_operators' => ['eq', 'neq'],
    ]);

    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'storage_gb',
        'source_path' => '$.storage_gb',
        'data_type' => 'integer',
        'is_filterable' => true,
        'is_sortable' => true,
        'allowed_operators' => ['eq', 'gte', 'lte'],
    ]);

    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'release_date',
        'source_path' => '$.release_date',
        'data_type' => 'date',
        'is_filterable' => true,
        'is_sortable' => true,
        'allowed_operators' => ['gte', 'lte'],
    ]);

    return [$team, $dataset];
}

/**
 * @param  array<string, mixed>  $payload
 */
function searchRecord(Dataset $dataset, string $externalId, array $payload, bool $active = true): DatasetRecord
{
    return DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => $externalId,
        'payload' => $payload,
        'searchable_text' => implode(' ', array_map(
            static fn (mixed $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            $payload,
        )),
        'is_active' => $active,
    ]);
}

test('search returns active records matching text, typed filters, and sort order', function () {
    [$team, $dataset] = searchDatasetFixture();
    searchRecord($dataset, 'one', [
        'name' => 'Apple Pro',
        'price' => '1299.99',
        'in_stock' => true,
        'release_date' => '2025-01-01',
    ]);
    searchRecord($dataset, 'two', [
        'name' => 'Apple Air',
        'price' => '899.99',
        'in_stock' => true,
        'release_date' => '2024-06-01',
    ]);
    searchRecord($dataset, 'three', [
        'name' => 'Apple Archived',
        'price' => '799.99',
        'in_stock' => true,
        'release_date' => '2024-01-01',
    ], active: false);
    searchRecord($dataset, 'four', [
        'name' => 'Lenovo Pro',
        'price' => '1499.99',
        'in_stock' => true,
        'release_date' => '2025-02-01',
    ]);

    $result = app(SearchService::class)->search($team, new SearchQuery(
        datasetId: $dataset->id,
        text: '  apple  ',
        filters: [
            new SearchFilter('in_stock', SearchOperator::Equal, 'true'),
            new SearchFilter('price', SearchOperator::GreaterThanOrEqual, '800'),
        ],
        sorts: [new SearchSort('price', SearchSortDirection::Ascending)],
    ));

    expect($result->total)->toBe(2)
        ->and($result->records)->toHaveCount(2)
        ->and($result->records[0]->external_id)->toBe('two')
        ->and($result->records[1]->external_id)->toBe('one');
});

test('search enforces the team and dataset boundary', function () {
    [$currentTeam, $currentDataset] = searchDatasetFixture();
    [, $otherDataset] = searchDatasetFixture();
    searchRecord($currentDataset, 'current', ['name' => 'Current']);
    searchRecord($otherDataset, 'other', ['name' => 'Other']);

    $service = app(SearchService::class);

    expect($service->search($currentTeam, new SearchQuery($currentDataset->id))->total)->toBe(1);

    expect(fn () => $service->search($currentTeam, new SearchQuery($otherDataset->id)))
        ->toThrow(ModelNotFoundException::class);
});

test('search validates integer and date filters using DatasetField types', function () {
    [$team, $dataset] = searchDatasetFixture();
    searchRecord($dataset, 'older', [
        'name' => 'Older Phone',
        'storage_gb' => '256',
        'release_date' => '2024-01-01',
    ]);
    searchRecord($dataset, 'newer', [
        'name' => 'Newer Phone',
        'storage_gb' => 512,
        'release_date' => '2025-01-01',
    ]);

    $result = app(SearchService::class)->search($team, new SearchQuery(
        datasetId: $dataset->id,
        filters: [
            new SearchFilter('storage_gb', SearchOperator::Equal, '512'),
            new SearchFilter('release_date', SearchOperator::GreaterThanOrEqual, '2025-01-01'),
        ],
    ));

    expect($result->total)->toBe(1)
        ->and($result->records[0]->external_id)->toBe('newer');
});

test('search applies the default and custom limits', function () {
    [$team, $dataset] = searchDatasetFixture();

    foreach (range(1, 21) as $number) {
        searchRecord($dataset, "record-{$number}", ['name' => "Record {$number}"]);
    }

    $service = app(SearchService::class);
    $defaultResult = $service->search($team, new SearchQuery($dataset->id));
    $customResult = $service->search($team, new SearchQuery($dataset->id, limit: 5));

    expect($defaultResult->total)->toBe(21)
        ->and($defaultResult->records)->toHaveCount(SearchService::DEFAULT_LIMIT)
        ->and($customResult->total)->toBe(21)
        ->and($customResult->records)->toHaveCount(5);
});

test('search rejects fields and operators that DatasetField does not allow', function () {
    [$team, $dataset] = searchDatasetFixture();
    $service = app(SearchService::class);

    expect(fn () => $service->search($team, new SearchQuery(
        datasetId: $dataset->id,
        filters: [new SearchFilter('missing', SearchOperator::Equal, 'value')],
    )))->toThrow(InvalidSearchCriteriaException::class, 'Unknown search field');

    expect(fn () => $service->search($team, new SearchQuery(
        datasetId: $dataset->id,
        filters: [new SearchFilter('price', SearchOperator::Contains, '12')],
    )))->toThrow(InvalidSearchCriteriaException::class, 'not supported');

    expect(fn () => $service->search($team, new SearchQuery(
        datasetId: $dataset->id,
        filters: [new SearchFilter('in_stock', SearchOperator::GreaterThan, true)],
    )))->toThrow(InvalidSearchCriteriaException::class, 'not supported');

    expect(fn () => $service->search($team, new SearchQuery(
        datasetId: $dataset->id,
        filters: [new SearchFilter('price', SearchOperator::Equal, 'not-a-number')],
    )))->toThrow(InvalidSearchCriteriaException::class, 'Invalid value');

    expect(fn () => $service->search($team, new SearchQuery(
        datasetId: $dataset->id,
        limit: 101,
    )))->toThrow(InvalidSearchCriteriaException::class, 'between 1 and 100');

    expect(fn () => $service->search($team, new SearchQuery(
        datasetId: $dataset->id,
        sorts: [new SearchSort('supplier_secret', SearchSortDirection::Ascending)],
    )))->toThrow(InvalidSearchCriteriaException::class, 'Unknown sort field');
});

test('search rejects a field-shaped SQL injection attempt as an unknown field', function () {
    [$team, $dataset] = searchDatasetFixture();

    expect(fn () => app(SearchService::class)->search($team, new SearchQuery(
        datasetId: $dataset->id,
        filters: [new SearchFilter("price')::numeric OR 1=1 --", SearchOperator::Equal, 1)],
    )))->toThrow(InvalidSearchCriteriaException::class, 'Unknown search field');
});

test('search rejects a dataset selected from another team before querying records', function () {
    [$currentTeam] = searchDatasetFixture();
    $otherTeam = Team::factory()->create();
    $otherDataset = Dataset::factory()->create(['team_id' => $otherTeam->id]);

    expect(fn () => app(SearchService::class)->search(
        $currentTeam,
        new SearchQuery($otherDataset->id),
    ))->toThrow(ModelNotFoundException::class);
});
