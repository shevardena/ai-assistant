<?php

use App\Services\Ai\AiRules;

test('central AI rules cover multilingual catalog normalization and safety', function () {
    $rules = app(AiRules::class)->all();

    expect($rules)
        ->toContain('Reply to the customer in the language used by the customer unless bot instructions explicitly require another language.')
        ->toContain('When calling search_catalog, do not blindly copy the customer\'s full sentence into the text argument.')
        ->toContain('Translation, transliteration, canonicalization, and normalization are allowed when they increase the probability of matching the connected catalog.')
        ->toContain('Preserve exact identifiers whenever possible.')
        ->toContain('Never construct arbitrary API URLs.')
        ->not->toContain('Never translate or replace a product search phrase. If the customer uses Georgian or another non-Latin product term, copy that term exactly into the catalog tool text argument.');
});
