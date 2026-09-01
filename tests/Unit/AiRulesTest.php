<?php

use App\Services\Ai\AiRules;

test('central AI rules cover multilingual catalog normalization and safety', function () {
    $rules = app(AiRules::class)->all();

    expect($rules)
        ->toContain('Reply to the customer in the language used by the customer unless bot instructions explicitly require another language.')
        ->toContain('When calling search_catalog, do not blindly copy the customer\'s full sentence into the text argument.')
        ->toContain('Translation, transliteration, canonicalization, and normalization are allowed when they increase the probability of matching the connected catalog.')
        ->toContain('For search_catalog.text, translate or transliterate the customer\'s term when needed, but preserve the same semantic scope.')
        ->toContain('The search_catalog.text you produce is a concise canonical or catalog-friendly alternative. The backend may first try the customer\'s original meaningful terminology and use your canonical text only as a fallback.')
        ->toContain('Keep the core catalog entity in search_catalog.text and put explicit qualifiers such as year, brand, category, or product type in constraints when the schema supports them.')
        ->toContain('For example, represent "2009 Prius" as text "Prius" with a year equals 2009 constraint, not as text "2009 Prius".')
        ->toContain('Do not teach or emit client-specific remote parameter names; semantic constraints are mapped by the configured operation.')
        ->toContain('Do not add a brand, manufacturer, category, model, or qualifier that the customer did not mention.')
        ->toContain('Prefer the shortest equivalent catalog term.')
        ->toContain('For example, normalize "პრიუს" to "Prius" and "ქემრი" to "Camry", never to "Toyota Prius" or "Toyota Camry".')
        ->toContain('If the customer explicitly says "Toyota Prius", preserve "Toyota Prius" in the search text.')
        ->toContain('Put explicit numeric, category, availability, and other structured criteria in search_catalog.filters or search_catalog.constraints instead of embedding them in search_catalog.text.')
        ->toContain('Use current_price for a generic price request, regular_price for regular or original price, and discount_percent for discount or percentage-off criteria; do not emit client-specific field names.')
        ->toContain('Represent "between X and Y" as a between filter with value null, minimum X, and maximum Y.')
        ->toContain('Use search_catalog.sorts with current_price ascending for cheapest or lowest-price requests and descending for most expensive or highest-price requests. Do not invent a numeric threshold for vague words such as "cheap" or "expensive".')
        ->toContain('If the catalog result reports a bounded local sort with global_guaranteed false, describe the products as the lowest- or highest-priced matches found, not as globally cheapest or most expensive.')
        ->toContain('Preserve exact identifiers whenever possible.')
        ->toContain('When a live catalog search is configured, use search_catalog for every current product, listing, existence, availability, or explicit show/search request that needs catalog data.')
        ->toContain('Treat search_catalog.dataset as a source hint by default and leave source_scope as all unless the customer explicitly asks for one named catalog. Use source_scope specific only for an explicit request such as "search only Beko".')
        ->toContain('Never say that no products were found unless search_catalog was executed for the current request and completed successfully with zero items.')
        ->toContain('If search_catalog fails, times out, or reports an integration error, say that the live catalog could not be checked. Do not convert an integration error into no products found.')
        ->toContain('When an image is attached, inspect the visible product or object conservatively and use the existing search_catalog tool when the customer wants to find or check it.')
        ->toContain('Prefer a strong visible SKU, OEM number, barcode text, part number, or model number over a broad visual guess, and preserve exact identifiers without rewriting them.')
        ->toContain('Use customer text to refine or correct visual interpretation. If the image is ambiguous, search broadly or ask a clarifying question instead of claiming an exact match.')
        ->toContain('Never construct arbitrary API URLs.')
        ->not->toContain('Never translate or replace a product search phrase. If the customer uses Georgian or another non-Latin product term, copy that term exactly into the catalog tool text argument.');
});
