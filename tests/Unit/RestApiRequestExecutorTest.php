<?php

use App\Services\Imports\Exceptions\ImportException;
use App\Services\Imports\RestApiRequestExecutor;

test('private and local API addresses are rejected', function (string $url) {
    expect(fn () => (new RestApiRequestExecutor)->assertSafeUrl($url))
        ->toThrow(ImportException::class);
})->with([
    'http://127.0.0.1/products',
    'http://localhost/products',
    'http://169.254.169.254/latest/meta-data',
    'http://10.0.0.1/products',
    'http://172.16.0.1/products',
    'http://192.168.1.1/products',
]);

test('bounds configured API request timeouts', function () {
    expect(RestApiRequestExecutor::boundTimeoutSeconds(500, 10))
        ->toBe(1.0)
        ->and(RestApiRequestExecutor::boundTimeoutSeconds(30000, 10))
        ->toBe(10.0);
});
