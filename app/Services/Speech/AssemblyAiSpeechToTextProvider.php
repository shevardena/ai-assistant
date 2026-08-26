<?php

namespace App\Services\Speech;

use App\Services\Speech\Contracts\SpeechToTextProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class AssemblyAiSpeechToTextProvider implements SpeechToTextProvider
{
    public function transcribe(
        string $absolutePath,
        ?string $mimeType = null,
        ?string $languageHint = null,
    ): TranscriptionResult {
        $apiKey = config('speech_to_text.assemblyai.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new SpeechToTextException(
                'Voice transcription is not configured.',
                'voice_unavailable',
            );
        }

        $content = file_get_contents($absolutePath);

        if ($content === false) {
            throw new SpeechToTextException('The recording could not be opened.');
        }

        try {
            $upload = $this->client($apiKey)
                ->withBody($content, $mimeType ?: 'application/octet-stream')
                ->post('/v2/upload');

            $this->throwForFailure($upload);
            $uploadUrl = $upload->json('upload_url');

            if (! is_string($uploadUrl) || trim($uploadUrl) === '') {
                throw new SpeechToTextException('The transcription service returned an invalid upload response.');
            }

            $language = $this->normalizeLanguage($languageHint);
            $payload = [
                'audio_url' => $uploadUrl,
                'speech_models' => config('speech_to_text.assemblyai.speech_models', ['universal-3-pro', 'universal-2']),
            ];

            if ($language !== null) {
                $payload['language_code'] = $language;
            } else {
                $payload['language_detection'] = (bool) config('speech_to_text.assemblyai.language_detection', true);
            }

            $client = $this->client($apiKey);
            $transcript = $client->post('/v2/transcript', $payload);
            $this->throwForFailure($transcript);
            $transcriptId = $transcript->json('id');

            if (! is_string($transcriptId) || trim($transcriptId) === '') {
                throw new SpeechToTextException('The transcription service returned an invalid transcript response.');
            }

            return $this->poll($client, $transcriptId);
        } catch (SpeechToTextException $exception) {
            throw $exception;
        } catch (ConnectionException $exception) {
            throw new SpeechToTextException(
                'The transcription service timed out or could not be reached.',
                'transcription_timeout',
                $exception,
            );
        } catch (Throwable $exception) {
            throw new SpeechToTextException('The recording could not be transcribed.', previous: $exception);
        }
    }

    private function client(string $apiKey): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('speech_to_text.assemblyai.base_url'), '/'))
            ->withHeaders(['Authorization' => $apiKey])
            ->acceptJson()
            ->timeout((int) config('speech_to_text.assemblyai.timeout', 30))
            ->connectTimeout((int) config('speech_to_text.assemblyai.connect_timeout', 5));
    }

    private function poll(PendingRequest $client, string $transcriptId): TranscriptionResult
    {
        $deadline = microtime(true) + max(0, (int) config('speech_to_text.assemblyai.max_poll_seconds', 30));
        $intervalMilliseconds = max(0, (int) config('speech_to_text.assemblyai.poll_interval_ms', 500));

        do {
            if (microtime(true) > $deadline) {
                throw new SpeechToTextException('The transcription took too long.', 'transcription_timeout');
            }

            $response = $client->get('/v2/transcript/'.rawurlencode($transcriptId));
            $this->throwForFailure($response);
            $status = $response->json('status');

            if ($status === 'completed') {
                $text = $response->json('text');

                if (! is_string($text) || trim($text) === '') {
                    throw new SpeechToTextException('No speech was detected in the recording.', 'empty_transcript');
                }

                $language = $response->json('language_code');
                $duration = $response->json('audio_duration');

                return new TranscriptionResult(
                    trim($text),
                    is_string($language) && $language !== '' ? $language : null,
                    is_numeric($duration) ? (float) $duration : null,
                );
            }

            if ($status === 'error') {
                throw new SpeechToTextException('The transcription service could not process the recording.');
            }

            if (! in_array($status, ['queued', 'processing'], true)) {
                throw new SpeechToTextException('The transcription service returned an invalid status.');
            }

            $remainingMilliseconds = (int) max(0, ($deadline - microtime(true)) * 1000);

            if ($intervalMilliseconds > 0 && $remainingMilliseconds > 0) {
                usleep(min($intervalMilliseconds, $remainingMilliseconds) * 1000);
            }
        } while (microtime(true) <= $deadline);

        throw new SpeechToTextException('The transcription took too long.', 'transcription_timeout');
    }

    private function throwForFailure(Response $response): void
    {
        if ($response->status() === 429) {
            throw new SpeechToTextException('The transcription service is rate-limited.', 'rate_limited');
        }

        if ($response->status() === 408 || $response->status() === 504) {
            throw new SpeechToTextException('The transcription took too long.', 'transcription_timeout');
        }

        if (! $response->successful()) {
            throw new SpeechToTextException('The transcription service rejected the recording.');
        }
    }

    private function normalizeLanguage(?string $languageHint): ?string
    {
        if (! is_string($languageHint) || trim($languageHint) === '') {
            return null;
        }

        return match (strtolower(strtok(trim($languageHint), '-_') ?: '')) {
            'en' => 'en',
            'ka' => 'ka',
            'ru' => 'ru',
            default => null,
        };
    }
}
