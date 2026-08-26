<?php

namespace App\Services\Typesense;

use Http\Client\Curl\Client as CurlClient;
use RuntimeException;
use Typesense\Client as VendorClient;

class TypesenseClientFactory
{
    public function make(): TypesenseClient
    {
        $apiKey = (string) config('typesense.api_key', '');

        if ($apiKey === '') {
            throw new RuntimeException('Typesense is enabled but TYPESENSE_API_KEY is missing.');
        }

        $timeout = max(
            (int) config('typesense.connection_timeout', 2),
            (int) config('typesense.search_timeout', 2),
        );
        $httpClient = new CurlClient(options: [
            CURLOPT_CONNECTTIMEOUT => (int) config('typesense.connection_timeout', 2),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        return new TypesenseClient(new VendorClient([
            'api_key' => $apiKey,
            'nodes' => [[
                'host' => (string) config('typesense.host'),
                'port' => (int) config('typesense.port'),
                'protocol' => (string) config('typesense.protocol'),
            ]],
            'client' => $httpClient,
            'num_retries' => (int) config('typesense.num_retries', 3),
            'retry_interval_seconds' => (float) config('typesense.retry_interval_seconds', 0.1),
        ]));
    }
}
