<?php

return [
    'default_results' => (int) env('LIVE_READ_DEFAULT_RESULTS', 5),
    'expanded_results' => (int) env('LIVE_READ_EXPANDED_RESULTS', 10),
    'max_results' => (int) env('LIVE_READ_MAX_RESULTS', 20),
    'max_candidates' => (int) env('LIVE_READ_MAX_CANDIDATES', 500),
    'max_pages' => (int) env('LIVE_READ_MAX_PAGES', 10),
    'timeout_seconds' => (int) env('LIVE_READ_TIMEOUT_SECONDS', 15),
    'max_response_bytes' => (int) env('LIVE_READ_MAX_RESPONSE_BYTES', 5 * 1024 * 1024),
    'around_tolerance_percent' => (float) env('LIVE_READ_AROUND_TOLERANCE_PERCENT', 10),
];
