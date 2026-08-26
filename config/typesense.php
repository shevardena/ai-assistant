<?php

return [

    'host' => env('TYPESENSE_HOST', '127.0.0.1'),
    'port' => (int) env('TYPESENSE_PORT', 8108),
    'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
    'api_key' => env('TYPESENSE_API_KEY'),
    'connection_timeout' => (int) env('TYPESENSE_CONNECTION_TIMEOUT', 2),
    'search_timeout' => (int) env('TYPESENSE_SEARCH_TIMEOUT', 2),
    'num_retries' => (int) env('TYPESENSE_NUM_RETRIES', 3),
    'retry_interval_seconds' => (float) env('TYPESENSE_RETRY_INTERVAL_SECONDS', 0.1),

];
