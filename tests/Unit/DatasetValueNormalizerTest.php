<?php

use App\Models\DatasetField;
use App\Services\Imports\DatasetValueNormalizer;
use Tests\TestCase;

uses(TestCase::class);

function normalizerField(string $dataType, ?string $normalizer = null): DatasetField
{
    $field = Mockery::mock(DatasetField::class);
    $attributes = [
        'key' => 'value',
        'data_type' => $dataType,
        'normalizer' => $normalizer,
    ];

    $field->shouldReceive('getAttribute')
        ->withAnyArgs()
        ->andReturnUsing(fn (string $attribute): mixed => $attributes[$attribute] ?? null);

    return $field;
}

test('normalizes the supported DatasetField scalar types', function () {
    $normalizer = new DatasetValueNormalizer;

    expect($normalizer->normalize(normalizerField('string'), 123))->toBe('123')
        ->and($normalizer->normalize(normalizerField('integer'), '42'))->toBe(42)
        ->and($normalizer->normalize(normalizerField('decimal'), '42.50'))->toBe(42.5)
        ->and($normalizer->normalize(normalizerField('boolean'), 'yes'))->toBeTrue()
        ->and($normalizer->normalize(normalizerField('boolean'), 'off'))->toBeFalse()
        ->and($normalizer->normalize(normalizerField('date'), '2026-08-20 12:30:00'))->toBe('2026-08-20')
        ->and($normalizer->normalize(normalizerField('datetime'), '2026-08-20 12:30:00'))->toContain('2026-08-20')
        ->and($normalizer->normalize(normalizerField('url'), 'https://example.com'))->toBe('https://example.com');
});

test('preserves catalog values that are numeric strings or signed percentages', function () {
    $normalizer = new DatasetValueNormalizer;

    expect($normalizer->normalize(normalizerField('integer'), '999'))->toBe(999)
        ->and($normalizer->normalize(normalizerField('integer'), '2999'))->toBe(2999)
        ->and($normalizer->normalize(normalizerField('string'), '-30%'))->toBe('-30%')
        ->and($normalizer->normalize(normalizerField('decimal'), '2999.50'))->toBe(2999.5);
});

test('applies the supported field normalizers', function () {
    $normalizer = new DatasetValueNormalizer;

    expect($normalizer->normalize(normalizerField('string', 'lowercase'), 'Apple'))->toBe('apple')
        ->and($normalizer->normalize(normalizerField('decimal', 'percentage'), '12.5%'))->toBe(12.5)
        ->and($normalizer->normalize(normalizerField('decimal', 'currency'), '$1,250.50'))->toBe(1250.5)
        ->and($normalizer->normalize(normalizerField('decimal', 'gb'), '1 TB'))->toBe(1024.0);
});

test('rejects malformed or unsupported values', function () {
    $normalizer = new DatasetValueNormalizer;

    expect(fn () => $normalizer->normalize(normalizerField('integer'), '4.2'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $normalizer->normalize(normalizerField('boolean'), 'maybe'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $normalizer->normalize(normalizerField('url'), 'not-a-url'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $normalizer->normalize(normalizerField('string', 'unknown'), 'value'))
        ->toThrow(InvalidArgumentException::class);
});
