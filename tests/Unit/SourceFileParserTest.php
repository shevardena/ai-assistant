<?php

use App\Models\SourceFile;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Imports\Parsers\CsvFileParser;
use App\Services\Imports\Parsers\JsonFileParser;
use App\Services\Imports\Parsers\XlsxFileParser;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

uses(TestCase::class);

function parserSourceFile(string $path, string $originalName): SourceFile
{
    $sourceFile = Mockery::mock(SourceFile::class);
    $attributes = [
        'disk' => 'local',
        'path' => $path,
        'original_name' => $originalName,
        'metadata' => ['extension' => pathinfo($originalName, PATHINFO_EXTENSION)],
    ];

    $sourceFile->shouldReceive('getAttribute')
        ->withAnyArgs()
        ->andReturnUsing(fn (string $attribute): mixed => $attributes[$attribute] ?? null);

    return $sourceFile;
}

test('parses CSV headers, quoted commas, and skips empty rows', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/products.csv', "id,title\n1,\"Phone, Pro\"\n\n2,Tablet\n");

    $rows = iterator_to_array((new CsvFileParser)->rows(parserSourceFile('imports/products.csv', 'products.csv')));

    expect($rows)->toBe([
        ['id' => '1', 'title' => 'Phone, Pro'],
        ['id' => '2', 'title' => 'Tablet'],
    ]);
});

test('parses top-level JSON arrays and rejects unsupported roots', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/products.json', json_encode([
        ['id' => '1', 'title' => 'Phone'],
        ['id' => '2', 'title' => 'Tablet'],
    ], JSON_THROW_ON_ERROR));
    Storage::disk('local')->put('imports/object.json', '{"items":[]}');
    Storage::disk('local')->put('imports/empty.json', '[]');

    $rows = iterator_to_array((new JsonFileParser)->rows(parserSourceFile('imports/products.json', 'products.json')));
    $emptyRows = iterator_to_array((new JsonFileParser)->rows(parserSourceFile('imports/empty.json', 'empty.json')));

    expect($rows)->toHaveCount(2)
        ->and($emptyRows)->toBe([])
        ->and(fn () => iterator_to_array((new JsonFileParser)->rows(parserSourceFile('imports/object.json', 'object.json'))))
        ->toThrow(ImportException::class);
});

test('parses the first worksheet from XLSX files', function () {
    Storage::fake('local');
    $temporaryPath = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    $spreadsheet = new Spreadsheet;
    $worksheet = $spreadsheet->getActiveSheet();
    $worksheet->fromArray([
        ['id', 'title'],
        ['1', 'Phone'],
    ]);
    (new Xlsx($spreadsheet))->save($temporaryPath);
    Storage::disk('local')->put('imports/products.xlsx', file_get_contents($temporaryPath));
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
    unlink($temporaryPath);

    $rows = iterator_to_array((new XlsxFileParser)->rows(parserSourceFile('imports/products.xlsx', 'products.xlsx')));

    expect($rows)->toBe([
        ['id' => 1, 'title' => 'Phone'],
    ]);
});

test('treats an XLSX header row without records as a valid empty source', function () {
    Storage::fake('local');
    $temporaryPath = tempnam(sys_get_temp_dir(), 'xlsx-empty-test-');
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([
        ['id', 'title'],
    ]);
    (new Xlsx($spreadsheet))->save($temporaryPath);
    Storage::disk('local')->put('imports/empty.xlsx', file_get_contents($temporaryPath));
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
    unlink($temporaryPath);

    expect(iterator_to_array((new XlsxFileParser)->rows(parserSourceFile('imports/empty.xlsx', 'empty.xlsx'))))
        ->toBe([]);
});
