<?php

return [
    'driver' => env('SPEECH_TO_TEXT_DRIVER', 'assemblyai'),
    'url' => env('SPEECH_TO_TEXT_URL', 'http://speech-to-text:8000'),
    'token' => env('SPEECH_TO_TEXT_TOKEN'),
    'timeout' => (int) env('SPEECH_TO_TEXT_TIMEOUT', 90),
    'connect_timeout' => (int) env('SPEECH_TO_TEXT_CONNECT_TIMEOUT', 5),
    'max_upload_kilobytes' => (int) env('SPEECH_TO_TEXT_MAX_UPLOAD_KILOBYTES', 10240),
    'max_duration_seconds' => (int) env('SPEECH_TO_TEXT_MAX_DURATION_SECONDS', 60),
    'rate_limit_per_minute' => (int) env('SPEECH_TO_TEXT_RATE_LIMIT_PER_MINUTE', 10),
    'assemblyai' => [
        'api_key' => env('ASSEMBLYAI_API_KEY'),
        'base_url' => env('ASSEMBLYAI_BASE_URL', 'https://api.assemblyai.com'),
        'timeout' => (int) env('ASSEMBLYAI_TIMEOUT', 30),
        'connect_timeout' => (int) env('ASSEMBLYAI_CONNECT_TIMEOUT', 5),
        'poll_interval_ms' => (int) env('ASSEMBLYAI_POLL_INTERVAL_MS', 500),
        'max_poll_seconds' => (int) env('ASSEMBLYAI_MAX_POLL_SECONDS', 30),
        'speech_models' => ['universal-3-pro', 'universal-2'],
        'language_detection' => true,
    ],
    'mimetypes' => [
        'audio/webm',
        'video/webm',
        'audio/ogg',
        'application/ogg',
        'audio/mp4',
        'video/mp4',
        'audio/mpeg',
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
    ],
];
