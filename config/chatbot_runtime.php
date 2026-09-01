<?php

return [
    'enabled' => (bool) env('CHATBOT_RUNTIME_LOGGING', true),
    'channel' => env('CHATBOT_RUNTIME_LOG_CHANNEL', 'chatbot_runtime'),
    'max_payload_bytes' => max(1000, (int) env('CHATBOT_LOG_MAX_PAYLOAD_BYTES', 20000)),
    'log_full_prompt' => (bool) env('CHATBOT_LOG_FULL_AI_PROMPT', false),
    'log_response_body' => (bool) env('CHATBOT_LOG_API_RESPONSE_BODY', env('APP_ENV') !== 'production'),
];
