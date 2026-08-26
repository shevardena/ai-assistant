<?php

return [
    'connect_timeout' => (float) env('REST_SOURCE_CONNECT_TIMEOUT', 5),
    'timeout' => (float) env('REST_SOURCE_TIMEOUT', 30),
    'max_pages' => (int) env('REST_SOURCE_MAX_PAGES', 100),
    'max_records' => (int) env('REST_SOURCE_MAX_RECORDS', 10000),
    'max_response_bytes' => (int) env('REST_SOURCE_MAX_RESPONSE_BYTES', 10485760),
    'max_redirects' => 0,
    'user_agent' => env('REST_SOURCE_USER_AGENT', 'AI-Search-Assistant/1.0'),
];
