<?php

$environment = (string) env('APP_ENV', 'production');

return [
    'allow_localhost' => $environment !== 'production'
        && ((bool) env('WIDGET_ALLOW_LOCALHOST', false) || $environment === 'local'),
    'message_max_length' => (int) env('WIDGET_MESSAGE_MAX_LENGTH', 4000),
    'history_limit' => (int) env('WIDGET_HISTORY_LIMIT', 20),
    'max_result_cards' => (int) env('WIDGET_MAX_RESULT_CARDS', 6),
    'rate_limit_per_minute' => (int) env('WIDGET_RATE_LIMIT_PER_MINUTE', 30),
    'base_url' => env('WIDGET_BASE_URL', env('APP_URL', 'http://localhost')),
];
