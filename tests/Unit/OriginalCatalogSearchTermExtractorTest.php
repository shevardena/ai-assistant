<?php

use App\Services\Ai\OriginalCatalogSearchTermExtractor;

test('extracts the original meaningful catalog term from multilingual requests', function () {
    $extractor = app(OriginalCatalogSearchTermExtractor::class);

    expect($extractor->extract('პრიუს'))->toBe('პრიუს')
        ->and($extractor->extract('მაჩვენე პრიუსის ნაწილები'))->toBe('პრიუს')
        ->and($extractor->extract('სალამი, პრისუზე რამე გაქვთ?'))->toBe('პრისუ')
        ->and($extractor->extract('2009 წლიან პრიუსზე?'))->toBe('პრიუს')
        ->and($extractor->extract('show me Toyota Prius products'))->toBe('Toyota Prius')
        ->and($extractor->extract('ABC-123'))->toBe('ABC-123');
});

test('does not turn a generic product request into a search term', function () {
    expect(app(OriginalCatalogSearchTermExtractor::class)->extract('მაჩვენე პროდუქტები'))->toBeNull();
});

test('removes conversational filler and structured numeric criteria from fallback terms', function () {
    $extractor = app(OriginalCatalogSearchTermExtractor::class);

    expect($extractor->extract('du u have any camry products under 150?'))->toBe('camry')
        ->and($extractor->extract('150 ლარამდე ქემრი რა გაქვს'))->toBe('ქემრი');
});

test('preserves literal title structure while removing conversational suffixes', function () {
    $extractor = app(OriginalCatalogSearchTermExtractor::class);

    expect($extractor->extractLiteral('07-09 CAMRY - ბამპერი (წინა) გაქვს'))->toBe('07-09 CAMRY - ბამპერი (წინა)')
        ->and($extractor->extractLiteral('09-11 PRIUS - ცხაური გაქვს?'))->toBe('09-11 PRIUS - ცხაური')
        ->and($extractor->extractLiteral('ABC-123 გაქვთ?'))->toBe('ABC-123')
        ->and($extractor->extractLiteral('show me products'))->toBeNull();
});
