<?php

use App\Services\Knowledge\KnowledgeQueryNormalizer;

test('normalizes unicode and tokenizes mixed language queries without losing scripts', function () {
    $normalizer = app(KnowledgeQueryNormalizer::class);

    expect($normalizer->normalize("  CAMRY\u{00A0} გარანტია?!  "))->toBe('camry გარანტია')
        ->and($normalizer->tokens('CAMRY გარანტია'))->toBe(['camry', 'გარანტია']);
});

test('generates bounded multilingual word variants', function () {
    $normalizer = app(KnowledgeQueryNormalizer::class);
    $variants = $normalizer->variants('ნივთების დაბრუნების returns');

    expect($variants['ნივთების'])->toContain('ნივთ')
        ->and($variants['დაბრუნების'])->toContain('დაბრუნ')
        ->and($variants['returns'])->toContain('return')
        ->and($variants['ნივთების'])->toHaveCount(4);
});
