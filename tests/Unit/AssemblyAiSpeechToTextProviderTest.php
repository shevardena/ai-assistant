<?php

use App\Services\Speech\AssemblyAiSpeechToTextProvider;
use App\Services\Speech\SpeechToTextException;
use Illuminate\Support\Facades\Http;

function assemblyAiSpeechFixture(): string
{
    $path = tempnam(sys_get_temp_dir(), 'assemblyai-test-');

    expect($path)->not->toBeFalse();
    file_put_contents($path, 'audio');

    return $path;
}

beforeEach(function (): void {
    config()->set('speech_to_text.assemblyai.api_key', 'assemblyai-test-key');
    config()->set('speech_to_text.assemblyai.base_url', 'https://assemblyai.test');
    config()->set('speech_to_text.assemblyai.poll_interval_ms', 0);
    config()->set('speech_to_text.assemblyai.max_poll_seconds', 1);
});

afterEach(function (): void {
    Http::preventStrayRequests();
});

test('the AssemblyAI provider uploads, submits, polls, and returns a transcript', function (): void {
    $path = assemblyAiSpeechFixture();
    $polls = 0;
    Http::fake(function ($request) use (&$polls) {
        return match (true) {
            str_ends_with($request->url(), '/v2/upload') => Http::response([
                'upload_url' => 'https://cdn.assemblyai.test/audio',
            ]),
            str_ends_with($request->url(), '/v2/transcript') => Http::response([
                'id' => 'transcript-1',
                'status' => 'queued',
            ]),
            default => Http::response(++$polls === 1
                ? ['id' => 'transcript-1', 'status' => 'processing']
                : [
                    'id' => 'transcript-1',
                    'status' => 'completed',
                    'text' => 'როგორ დავაბრუნო ნივთი?',
                    'language_code' => 'ka',
                    'audio_duration' => 2.5,
                ]),
        };
    });

    try {
        $result = app(AssemblyAiSpeechToTextProvider::class)->transcribe($path, 'audio/webm');
    } finally {
        unlink($path);
    }

    expect($result->text)->toBe('როგორ დავაბრუნო ნივთი?')
        ->and($result->language)->toBe('ka')
        ->and($result->durationSeconds)->toBe(2.5);

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/v2/transcript')) {
            return false;
        }

        $payload = json_decode($request->body(), true) ?: [];

        return $request->hasHeader('Authorization', 'assemblyai-test-key')
            && $payload['audio_url'] === 'https://cdn.assemblyai.test/audio'
            && $payload['speech_models'] === ['universal-3-pro', 'universal-2']
            && $payload['language_detection'] === true;
    });
});

test('the AssemblyAI provider sends supported language hints and detects unknown languages automatically', function (): void {
    $path = assemblyAiSpeechFixture();
    $payloads = [];
    Http::fake(function ($request) use (&$payloads) {
        if (str_ends_with($request->url(), '/v2/transcript')) {
            $payloads[] = json_decode($request->body(), true) ?: [];
        }

        if (str_ends_with($request->url(), '/v2/upload')) {
            return Http::response(['upload_url' => 'https://cdn.assemblyai.test/audio']);
        }

        if (count($payloads) === 1) {
            return Http::response(['id' => 'ka-job', 'status' => 'completed', 'text' => 'ტესტი', 'language_code' => 'ka']);
        }

        return Http::response(['id' => 'unknown-job', 'status' => 'completed', 'text' => 'test', 'language_code' => 'en']);
    });

    try {
        app(AssemblyAiSpeechToTextProvider::class)->transcribe($path, 'audio/webm', 'ka-GE');
        app(AssemblyAiSpeechToTextProvider::class)->transcribe($path, 'audio/webm', 'xx-XX');
    } finally {
        unlink($path);
    }

    expect($payloads[0]['language_code'])->toBe('ka')
        ->and($payloads[0])->not->toHaveKey('language_detection')
        ->and($payloads[1]['language_detection'])->toBeTrue()
        ->and($payloads[1])->not->toHaveKey('language_code');
});

test('the AssemblyAI provider maps rate limits and connection failures safely', function (): void {
    $path = assemblyAiSpeechFixture();
    Http::fake(['*/v2/upload' => Http::response(['error' => 'Too Many Requests'], 429)]);

    try {
        expect(fn (): mixed => app(AssemblyAiSpeechToTextProvider::class)->transcribe($path))
            ->toThrow(fn (SpeechToTextException $exception): bool => $exception->category === 'rate_limited');
    } finally {
        unlink($path);
    }

    $path = assemblyAiSpeechFixture();
    Http::fake(['*/v2/upload' => Http::failedConnection()]);

    try {
        expect(fn (): mixed => app(AssemblyAiSpeechToTextProvider::class)->transcribe($path))
            ->toThrow(fn (SpeechToTextException $exception): bool => $exception->category === 'transcription_timeout');
    } finally {
        unlink($path);
    }
});

test('the AssemblyAI provider maps authentication and upstream failures to safe errors', function (): void {
    $path = assemblyAiSpeechFixture();
    Http::fake(['*/v2/upload' => Http::response(['error' => 'Authentication error'], 401)]);

    try {
        expect(fn (): mixed => app(AssemblyAiSpeechToTextProvider::class)->transcribe($path))
            ->toThrow(SpeechToTextException::class);
    } finally {
        unlink($path);
    }

    $path = assemblyAiSpeechFixture();
    Http::fake([
        '*/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.test/audio']),
        '*/v2/transcript' => Http::response(['error' => 'Internal server error'], 500),
    ]);

    try {
        expect(fn (): mixed => app(AssemblyAiSpeechToTextProvider::class)->transcribe($path))
            ->toThrow(SpeechToTextException::class);
    } finally {
        unlink($path);
    }
});

test('the AssemblyAI provider rejects invalid and empty transcript responses', function (): void {
    $path = assemblyAiSpeechFixture();
    Http::fake([
        '*/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.test/audio']),
        '*/v2/transcript' => Http::response(['status' => 'completed']),
    ]);

    try {
        expect(fn (): mixed => app(AssemblyAiSpeechToTextProvider::class)->transcribe($path))
            ->toThrow(SpeechToTextException::class);
    } finally {
        unlink($path);
    }

    $path = assemblyAiSpeechFixture();
    Http::fake([
        '*/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.test/audio']),
        '*/v2/transcript' => Http::response(['id' => 'empty-job', 'status' => 'completed', 'text' => ' ']),
    ]);

    try {
        expect(fn (): mixed => app(AssemblyAiSpeechToTextProvider::class)->transcribe($path))
            ->toThrow(fn (SpeechToTextException $exception): bool => $exception->category === 'empty_transcript');
    } finally {
        unlink($path);
    }
});

test('the AssemblyAI provider stops polling at the configured deadline', function (): void {
    $path = assemblyAiSpeechFixture();
    config()->set('speech_to_text.assemblyai.max_poll_seconds', 0);
    Http::fake([
        '*/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.test/audio']),
        '*/v2/transcript' => Http::response(['id' => 'slow-job', 'status' => 'queued']),
    ]);

    try {
        expect(fn (): mixed => app(AssemblyAiSpeechToTextProvider::class)->transcribe($path))
            ->toThrow(fn (SpeechToTextException $exception): bool => $exception->category === 'transcription_timeout');
    } finally {
        unlink($path);
    }
});
