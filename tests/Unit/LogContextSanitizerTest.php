<?php

use App\Services\Logging\LogContextSanitizer;

test('redacts credentials and preserves safe request values', function () {
    $sanitizer = new LogContextSanitizer;

    expect($sanitizer->sanitize([
        'q' => 'camry',
        'api_key' => 'do-not-log',
        'nested' => ['Authorization' => 'Bearer do-not-log'],
    ]))->toMatchArray([
        'q' => 'camry',
        'api_key' => '[REDACTED]',
        'nested' => ['Authorization' => '[REDACTED]'],
    ]);
});

test('truncates serialized payloads at the configured byte limit', function () {
    $result = (new LogContextSanitizer)->json(['products' => [str_repeat('x', 100)]], 40);

    expect($result['truncated'])->toBeTrue()
        ->and($result['original_bytes'])->toBeGreaterThan(40)
        ->and(strlen($result['body']))->toBe(40);
});
