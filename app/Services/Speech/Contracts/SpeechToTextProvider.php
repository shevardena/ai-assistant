<?php

namespace App\Services\Speech\Contracts;

use App\Services\Speech\TranscriptionResult;

interface SpeechToTextProvider
{
    public function transcribe(
        string $absolutePath,
        ?string $mimeType = null,
        ?string $languageHint = null,
    ): TranscriptionResult;
}
