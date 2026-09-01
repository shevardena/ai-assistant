<?php

use App\Models\Dataset;
use App\Models\DatasetField;
use App\Services\Imports\DatasetRecordMapper;
use App\Services\Imports\DatasetValueNormalizer;
use App\Services\Imports\Exceptions\ImportException;
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
        mapperField('id', 'id'),
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
            'id' => 'sku-1',
            'name' => 'iPhone 16 Pro',
            'brand' => 'Apple',
            'price' => 3499.0,
        ])
        ->and($mapped['payload'])->not->toHaveKey('internal_supplier_code')
        ->and($mapped['checksum'])->toHaveLength(64);
});

test('resolves nested source paths and supports the existing dollar prefix', function () {
    $dataset = mapperDataset('id');
    $fields = new Collection([
        mapperField('product.id', 'id'),
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
        ->and($mapped['payload'])->toBe(['id' => 'p-1', 'brand' => 'Apple', 'storage' => 256]);
});

test('rejects a row when its primary key or mapped value is invalid', function () {
    $dataset = mapperDataset('id');
    $fields = new Collection([
        mapperField('id', 'id'),
        mapperField('quantity', 'quantity', 'integer'),
    ]);
    $mapper = new DatasetRecordMapper(new SourcePathResolver, new DatasetValueNormalizer);

    expect(fn () => $mapper->map($dataset, ['quantity' => 'not-an-integer'], $fields))
        ->toThrow(RowMappingException::class);
});

test('exposes an external id even when mapped values cannot be normalized', function () {
    $dataset = mapperDataset('id');
    $fields = new Collection([
        mapperField('id', 'id'),
        mapperField('quantity', 'quantity', 'integer'),
    ]);
    $mapper = new DatasetRecordMapper(new SourcePathResolver, new DatasetValueNormalizer);

    try {
        $mapper->map($dataset, ['id' => 'sku-1', 'quantity' => 'not-an-integer'], $fields);
    } catch (RowMappingException $exception) {
        expect($exception->externalId)->toBe('sku-1');

        return;
    }

    throw new RuntimeException('Expected a row mapping exception.');
});

test('includes source and normalized-value diagnostics for invalid mapped values', function () {
    $dataset = mapperDataset('discount_percent');
    $fields = new Collection([
        mapperField('ფასდაკლების %', 'discount_percent', 'integer'),
    ]);
    $mapper = new DatasetRecordMapper(new SourcePathResolver, new DatasetValueNormalizer);

    try {
        $mapper->map($dataset, ['ფასდაკლების %' => '-30%'], $fields);
    } catch (RowMappingException $exception) {
        expect($exception->errors)->toHaveCount(1)
            ->and($exception->errors[0])->toMatchArray([
                'field' => 'discount_percent',
                'stage' => 'normalization',
                'source_field' => 'ფასდაკლების %',
                'mapped_key' => 'discount_percent',
                'raw_value' => '-30%',
                'normalized_value' => null,
                'error_code' => 'invalid_integer',
            ])
            ->and($exception->errors[0]['message'])->toContain('integer');

        return;
    }

    throw new RuntimeException('Expected a row mapping exception.');
});

test('maps the supplied Georgian CSV shape when discount is configured as a string', function () {
    $dataset = mapperDataset('group');
    $fields = new Collection([
        mapperField('ნომენკლატურა.ჯგუფი', 'group'),
        mapperField('საცალო ფასი', 'price', 'integer'),
        mapperField('აქციის ფასი', 'promo_price', 'integer'),
        mapperField('ფასდაკლების %', 'discount_percent'),
    ]);
    $mapper = new DatasetRecordMapper(new SourcePathResolver, new DatasetValueNormalizer);

    $mapped = $mapper->map($dataset, [
        'ნომენკლატურა.ჯგუფი' => 'B1754FN',
        'საცალო ფასი' => '999',
        'აქციის ფასი' => '699',
        'ფასდაკლების %' => '-30%',
    ], $fields);

    expect($mapped['external_id'])->toBe('B1754FN')
        ->and($mapped['payload'])->toBe([
            'group' => 'B1754FN',
            'price' => 999,
            'promo_price' => 699,
            'discount_percent' => '-30%',
        ]);
});

test('resolves the external id from the normalized canonical primary field', function () {
    $dataset = mapperDataset('sku');
    $fields = new Collection([mapperField('Product Code', 'sku')]);
    $mapper = new DatasetRecordMapper(new SourcePathResolver, new DatasetValueNormalizer);

    expect($mapper->externalId($dataset, ['Product Code' => ' B1754FN '], $fields))->toBe('B1754FN');
});

test('rejects an import when the configured primary key is not mapped', function () {
    $dataset = mapperDataset('sku');
    $fields = new Collection([mapperField('Product Code', 'product_code')]);
    $mapper = new DatasetRecordMapper(new SourcePathResolver, new DatasetValueNormalizer);

    expect(fn () => $mapper->validatePrimaryKeyMapping($dataset, $fields))
        ->toThrow(ImportException::class, 'primary key field [sku] is not mapped');
});

test('explains when a mapped primary key source value is missing', function () {
    $dataset = mapperDataset('group');
    $fields = new Collection([mapperField('ნომენკლატურა.ჯგუფი', 'group')]);
    $mapper = new DatasetRecordMapper(new SourcePathResolver, new DatasetValueNormalizer);

    try {
        $mapper->map($dataset, [], $fields);
    } catch (RowMappingException $exception) {
        expect($exception->errors[0])->toMatchArray([
            'source_field' => 'ნომენკლატურა.ჯგუფი',
            'mapped_key' => 'group',
            'error_code' => 'missing_primary_key_value',
            'raw_value' => null,
            'normalized_value' => null,
        ])
            ->and($exception->errors[0]['message'])->toContain('ნომენკლატურა.ჯგუფი');

        return;
    }

    throw new RuntimeException('Expected a row mapping exception.');
});
