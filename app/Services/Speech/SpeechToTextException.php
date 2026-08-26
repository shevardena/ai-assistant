<?php

namespace App\Services\Speech;

use RuntimeException;

final class SpeechToTextException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $category = 'transcription_failed',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
