<?php

namespace App\Services\Speech;

final readonly class TranscriptionResult
{
    public function __construct(
        public string $text,
        public ?string $language = null,
        public ?float $durationSeconds = null,
    ) {}
}
