<?php

return [
    'disk' => env('SOURCE_FILES_DISK', 'local'),

    'max_size_kb' => (int) env('SOURCE_FILE_MAX_SIZE_KB', 25600),
];
