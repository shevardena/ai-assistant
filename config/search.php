<?php

return [

    'engine' => env('SEARCH_ENGINE', 'postgres'),

    'typesense' => [
        'sync_after_import' => filter_var(
            env('TYPESENSE_SYNC_AFTER_IMPORT', env('SEARCH_ENGINE', 'postgres') === 'typesense'),
            FILTER_VALIDATE_BOOL,
        ),
    ],

    'knowledge' => [
        'primary_minimum_confidence' => 0.48,
        'fallback_minimum_confidence' => 0.58,
        'candidate_limit' => 250,
    ],

];
