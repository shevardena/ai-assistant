<?php

use App\Models\Dataset;
use App\Models\DatasetField;
use App\Services\Imports\DatasetRecordMapper;
use App\Services\Imports\DatasetValueNormalizer;
use App\Services\Imports\Exceptions\RowMappingException;
use App\Services\Imports\SourcePathResolver;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

uses(TestCase::class);

function mapperField(string $sourcePath, string $key, string $dataType = 'string'): DatasetField
{
    $field = Mockery::mock(DatasetField::class);
    $attributes = [
        'source_path' => $sourcePath,
        'key' => $key,
        'data_type' => $dataType,
        'normalizer' => null,
    ];

    $field->shouldReceive('getAttribute')
        ->withAnyArgs()
        ->andReturnUsing(fn (string $attribute): mixed => $attributes[$attribute] ?? null);

    return $field;
}

function mapperDataset(string $primaryKeyPath): Dataset
{
    $dataset = Mockery::mock(Dataset::class);
    $dataset->shouldReceive('getAttribute')
        ->with('primary_key_path')
        ->andReturn($primaryKeyPath);

    return $dataset;
}

test('maps only configured source fields to the canonical payload', function () {
    $dataset = mapperDataset('id');
    $fields = new Collection([
        mapperField('title', 'name'),
        mapperField('manufacturer', 'brand'),
        mapperField('price_gel', 'price', 'decimal'),
    ]);
    $mapper = new DatasetRecordMapper(new SourcePathResolver, new DatasetValueNormalizer);

    $mapped = $mapper->map($dataset, [
        'id' => 'sku-1',
        'title' => 'iPhone 16 Pro',
        'manufacturer' => 'Apple',
        'price_gel' => '3499.00',
        'internal_supplier_code' => 'SECRET-X',
    ], $fields);

    expect($mapped['external_id'])->toBe('sku-1')
        ->and($mapped['payload'])->toBe([
            'name' => 'iPhone 16 Pro',
            'brand' => 'Apple',
            'price' => 3499.0,
        ])
        ->and($mapped['payload'])->not->toHaveKey('internal_supplier_code')
        ->and($mapped['checksum'])->toHaveLength(64);
});

test('resolves nested source paths and supports the existing dollar prefix', function () {
    $dataset = mapperDataset('$.product.id');
    $fields = new Collection([
        mapperField('product.brand.name', 'brand'),
        mapperField('attributes.storage', 'storage', 'integer'),
    ]);
    $mapper = new DatasetRecordMapper(new SourcePathResolver, new DatasetValueNormalizer);

    $mapped = $mapper->map($dataset, [
        'product' => [
            'id' => 'p-1',
            'brand' => ['name' => 'Apple'],
        ],
        'attributes' => ['storage' => '256'],
    ], $fields);

    expect($mapped['external_id'])->toBe('p-1')
        ->and($mapped['payload'])->toBe(['brand' => 'Apple', 'storage' => 256]);
});

test('rejects a row when its primary key or mapped value is invalid', function () {
    $dataset = mapperDataset('id');
    $fields = new Collection([mapperField('quantity', 'quantity', 'integer')]);
    $mapper = new DatasetRecordMapper(new SourcePathResolver, new DatasetValueNormalizer);

    expect(fn () => $mapper->map($dataset, ['quantity' => 'not-an-integer'], $fields))
        ->toThrow(RowMappingException::class);
});

test('exposes an external id even when mapped values cannot be normalized', function () {
    $dataset = mapperDataset('id');
    $fields = new Collection([mapperField('quantity', 'quantity', 'integer')]);
    $mapper = new DatasetRecordMapper(new SourcePathResolver, new DatasetValueNormalizer);

    try {
        $mapper->map($dataset, ['id' => 'sku-1', 'quantity' => 'not-an-integer'], $fields);
    } catch (RowMappingException $exception) {
        expect($exception->externalId)->toBe('sku-1');

        return;
    }

    throw new RuntimeException('Expected a row mapping exception.');
});
