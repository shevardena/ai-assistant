<?php

use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Services\Search\Data\SearchFilter;
use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Data\SearchSort;
use App\Services\Search\Enums\SearchOperator;
use App\Services\Search\Enums\SearchSortDirection;
use App\Services\Typesense\TypesenseClient;
use App\Services\Typesense\TypesenseCollectionManager;
use App\Services\Typesense\TypesenseDocumentMapper;
use App\Services\Typesense\TypesenseQueryTranslator;
use App\Services\Typesense\TypesenseSchemaBuilder;
use Illuminate\Database\Eloquent\Collection;

function typesenseTestField(string $key, string $dataType, bool $searchable = false, bool $filterable = false, bool $sortable = false): DatasetField
{
    return (new DatasetField)->forceFill([
        'key' => $key,
        'data_type' => $dataType,
        'is_searchable' => $searchable,
        'is_filterable' => $filterable,
        'is_sortable' => $sortable,
    ]);
}

test('builds a Typesense schema from DatasetField types and capabilities', function () {
    $fields = new Collection([
        typesenseTestField('name', 'string', searchable: true, filterable: true),
        typesenseTestField('price', 'decimal', filterable: true, sortable: true),
        typesenseTestField('available', 'boolean', filterable: true),
        typesenseTestField('release_date', 'date', filterable: true, sortable: true),
        typesenseTestField('website', 'url', searchable: true),
    ]);

    $schema = (new TypesenseSchemaBuilder)->build('dataset_12', $fields);
    $schemaFields = collect($schema['fields'])->keyBy('name');

    expect($schema['name'])->toBe('dataset_12')
        ->and($schemaFields->get('name'))->toMatchArray([
            'name' => 'name',
            'type' => 'string',
            'optional' => true,
            'facet' => true,
        ])
        ->and($schemaFields->get('price'))->toMatchArray([
            'type' => 'float',
            'facet' => true,
            'sort' => true,
        ])
        ->and($schemaFields->get('available')['type'])->toBe('bool')
        ->and($schemaFields->get('release_date')['type'])->toBe('int64')
        ->and($schemaFields->get('website')['type'])->toBe('string');
});

test('maps only configured fields and converts temporal values for Typesense', function () {
    $fields = new Collection([
        typesenseTestField('name', 'string'),
        typesenseTestField('price', 'decimal'),
        typesenseTestField('release_date', 'date'),
        typesenseTestField('published_at', 'datetime'),
    ]);
    $record = (new DatasetRecord)->forceFill([
        'id' => 7,
        'external_id' => 'sku-7',
        'payload' => [
            'name' => 'Phone',
            'price' => 20,
            'release_date' => '2026-08-20',
            'published_at' => '2026-08-20T12:30:00+00:00',
            'secret' => 'do-not-index',
        ],
        'searchable_text' => 'Phone 20',
    ]);

    $document = (new TypesenseDocumentMapper)->map($record, $fields);

    expect($document)->toMatchArray([
        'id' => '7',
        'external_id' => 'sku-7',
        'dataset_record_id' => 7,
        'name' => 'Phone',
        'price' => 20.0,
        'release_date' => 1787184000,
        'published_at' => 1787229000,
        'searchable_text' => 'Phone 20',
    ])->not->toHaveKey('secret');
});

test('translates validated search criteria into Typesense parameters', function () {
    $fields = new Collection([
        'name' => typesenseTestField('name', 'string', searchable: true),
        'price' => typesenseTestField('price', 'decimal', filterable: true, sortable: true),
        'available' => typesenseTestField('available', 'boolean', filterable: true),
        'release_date' => typesenseTestField('release_date', 'date', filterable: true),
    ]);

    $parameters = (new TypesenseQueryTranslator)->translate(new SearchQuery(
        datasetId: 12,
        text: 'phone',
        filters: [
            new SearchFilter('price', SearchOperator::GreaterThanOrEqual, 20),
            new SearchFilter('available', SearchOperator::Equal, true),
            new SearchFilter('release_date', SearchOperator::Equal, '2026-08-20'),
        ],
        sorts: [new SearchSort('price', SearchSortDirection::Descending)],
        limit: 5,
    ), $fields);

    expect($parameters)->toBe([
        'q' => 'phone',
        'query_by' => 'name',
        'per_page' => 5,
        'filter_by' => 'price:>=20 && available:=true && release_date:=1787184000',
        'sort_by' => 'price:desc',
    ]);
});

test('rejects unsupported DatasetField types and reserved metadata keys', function () {
    expect(fn () => (new TypesenseSchemaBuilder)->build(
        'dataset_1',
        new Collection([typesenseTestField('unknown', 'json')]),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => (new TypesenseSchemaBuilder)->build(
        'dataset_1',
        new Collection([typesenseTestField('dataset_record_id', 'string')]),
    ))->toThrow(InvalidArgumentException::class);
});

test('uses a deterministic collection name based on the Dataset id', function () {
    $dataset = (new Dataset)->forceFill(['id' => 123]);

    expect((new TypesenseCollectionManager(Mockery::mock(TypesenseClient::class)))
        ->collectionNameForDataset($dataset))->toBe('dataset_123');
});
