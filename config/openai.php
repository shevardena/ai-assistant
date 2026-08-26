<?php

return [
    'api_key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL'),
    'timeout' => (int) env('OPENAI_TIMEOUT', 30),
    'max_tool_rounds' => (int) env('OPENAI_MAX_TOOL_ROUNDS', 3),
    'max_results' => (int) env('OPENAI_MAX_RESULTS', 10),
    'conversation_history_limit' => (int) env('AI_CONVERSATION_MAX_MESSAGES', 20),
];
