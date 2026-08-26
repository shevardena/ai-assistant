<?php

use App\Models\SourceFile;
use App\Services\Imports\Discovery\SourceFieldDiscoveryService;
use App\Services\Imports\Parsers\CsvFileParser;
use App\Services\Imports\Parsers\JsonFileParser;
use App\Services\Imports\Parsers\XlsxFileParser;

function discoveryMockSourceFile(string $extension): SourceFile
{
    $sourceFile = Mockery::mock(SourceFile::class);
    $attributes = [
        'metadata' => ['extension' => $extension],
        'original_name' => 'source.'.$extension,
    ];

    $sourceFile->shouldReceive('getAttribute')
        ->withAnyArgs()
        ->andReturnUsing(fn (string $attribute): mixed => $attributes[$attribute] ?? null);

    return $sourceFile;
}

test('discovers CSV headers and conservatively infers field types', function () {
    $sourceFile = discoveryMockSourceFile('csv');
    $csv = Mockery::mock(CsvFileParser::class);
    $csv->shouldReceive('headers')->once()->with($sourceFile)->andReturn([
        'id', 'name', 'brand', 'price', 'in_stock',
    ]);
    $csv->shouldReceive('rows')->once()->with($sourceFile)->andReturn([
        ['id' => '1', 'name' => 'Phone', 'brand' => 'Samsung', 'price' => '499.99', 'in_stock' => 'true'],
        ['id' => '2', 'name' => 'Tablet', 'brand' => 'Apple', 'price' => '799.00', 'in_stock' => 'false'],
    ]);
    $service = new SourceFieldDiscoveryService(
        $csv,
        Mockery::mock(JsonFileParser::class),
        Mockery::mock(XlsxFileParser::class),
    );

    $fields = $service->discover($sourceFile, 'id');
    $byPath = collect($fields)->keyBy('sourcePath');

    expect($byPath->get('id')->suggestedType)->toBe('string')
        ->and($byPath->get('name')->suggestedType)->toBe('string')
        ->and($byPath->get('price')->suggestedType)->toBe('decimal')
        ->and($byPath->get('in_stock')->suggestedType)->toBe('boolean')
        ->and($byPath->get('id')->isPrimaryKey)->toBeTrue()
        ->and($byPath->get('price')->sampleValues)->toBe(['499.99', '799.00']);
});

test('discovers JSON nested object paths and ignores array values', function () {
    $sourceFile = discoveryMockSourceFile('json');
    $json = Mockery::mock(JsonFileParser::class);
    $json->shouldReceive('rows')->once()->with($sourceFile)->andReturn([
        [
            'id' => 'A',
            'name' => 'Phone',
            'brand' => ['name' => 'Samsung'],
            'price' => 499.99,
            'tags' => ['phone', 'android'],
        ],
        [
            'id' => 'B',
            'name' => 'Tablet',
            'brand' => ['name' => 'Apple'],
            'price' => 799.0,
            'tags' => ['tablet'],
        ],
    ]);
    $service = new SourceFieldDiscoveryService(
        Mockery::mock(CsvFileParser::class),
        $json,
        Mockery::mock(XlsxFileParser::class),
    );

    $fields = $service->discover($sourceFile);

    expect(collect($fields)->pluck('sourcePath')->all())
        ->toContain('id', 'name', 'brand.name', 'price')
        ->not->toContain('tags')
        ->and(collect($fields)->firstWhere('sourcePath', 'brand.name')->suggestedLabel)
        ->toBe('Brand name');
});

test('represents native JSON booleans as compatible sample values', function () {
    $sourceFile = discoveryMockSourceFile('json');
    $json = Mockery::mock(JsonFileParser::class);
    $json->shouldReceive('rows')->once()->with($sourceFile)->andReturn([
        ['id' => 'A', 'in_stock' => true],
        ['id' => 'B', 'in_stock' => false],
    ]);
    $service = new SourceFieldDiscoveryService(
        Mockery::mock(CsvFileParser::class),
        $json,
        Mockery::mock(XlsxFileParser::class),
    );

    $field = collect($service->discover($sourceFile))->firstWhere('sourcePath', 'in_stock');

    expect($field->suggestedType)->toBe('boolean')
        ->and($field->sampleValues)->toBe(['true', 'false']);
});

test('limits XLSX discovery samples to five values per field', function () {
    $sourceFile = discoveryMockSourceFile('xlsx');
    $xlsx = Mockery::mock(XlsxFileParser::class);
    $xlsx->shouldReceive('headers')->once()->with($sourceFile)->andReturn(['id', 'score']);
    $xlsx->shouldReceive('rows')->once()->with($sourceFile)->andReturn(
        collect(range(1, 60))->map(fn (int $number): array => [
            'id' => (string) $number,
            'score' => (string) ($number + 0.5),
        ])->all(),
    );
    $service = new SourceFieldDiscoveryService(
        Mockery::mock(CsvFileParser::class),
        Mockery::mock(JsonFileParser::class),
        $xlsx,
    );

    $score = collect($service->discover($sourceFile))->firstWhere('sourcePath', 'score');

    expect($score->suggestedType)->toBe('decimal')
        ->and($score->sampleValues)->toHaveCount(5);
});

test('mixed values fall back to string and rows are bounded', function () {
    $sourceFile = discoveryMockSourceFile('json');
    $json = Mockery::mock(JsonFileParser::class);
    $json->shouldReceive('rows')->once()->with($sourceFile)->andReturn(
        collect(range(1, 100))->map(
            fn (int $number): array => ['value' => $number === 2 ? 'ABC' : (string) $number],
        )->all(),
    );
    $service = new SourceFieldDiscoveryService(
        Mockery::mock(CsvFileParser::class),
        $json,
        Mockery::mock(XlsxFileParser::class),
    );

    $field = collect($service->discover($sourceFile))->firstWhere('sourcePath', 'value');

    expect($field->suggestedType)->toBe('string')
        ->and($field->sampleValues)->toHaveCount(5);
});
