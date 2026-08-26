<?php

namespace App\Services\Speech;

use App\Services\Speech\Contracts\SpeechToTextProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SelfHostedWhisperSpeechToTextProvider implements SpeechToTextProvider
{
    public function transcribe(
        string $absolutePath,
        ?string $mimeType = null,
        ?string $languageHint = null,
    ): TranscriptionResult {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new SpeechToTextException('The recording could not be opened.');
        }

        try {
            $request = Http::baseUrl(rtrim((string) config('speech_to_text.url'), '/'))
                ->acceptJson()
                ->timeout((int) config('speech_to_text.timeout', 90))
                ->connectTimeout((int) config('speech_to_text.connect_timeout', 5));

            $token = config('speech_to_text.token');

            if (is_string($token) && $token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request
                ->attach(
                    'audio',
                    $handle,
                    basename($absolutePath),
                    ['Content-Type' => $mimeType ?: 'application/octet-stream'],
                )
                ->post('/transcribe', array_filter([
                    'language' => $languageHint,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        } catch (ConnectionException $exception) {
            throw new SpeechToTextException(
                'The transcription service timed out or could not be reached.',
                'transcription_timeout',
                $exception,
            );
        } catch (Throwable $exception) {
            throw new SpeechToTextException('The recording could not be transcribed.', previous: $exception);
        } finally {
            fclose($handle);
        }

        if ($response->status() === 408 || $response->status() === 504) {
            throw new SpeechToTextException('The transcription took too long.', 'transcription_timeout');
        }

        if (! $response->successful()) {
            throw new SpeechToTextException('The transcription service rejected the recording.');
        }

        $text = $response->json('text');

        if (! is_string($text) || trim($text) === '') {
            throw new SpeechToTextException('No speech was detected in the recording.', 'empty_transcript');
        }

        $language = $response->json('language');
        $duration = $response->json('duration_seconds');

        return new TranscriptionResult(
            trim($text),
            is_string($language) && $language !== '' ? $language : null,
            is_numeric($duration) ? (float) $duration : null,
        );
    }
}
