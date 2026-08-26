<?php

use App\Services\Speech\SelfHostedWhisperSpeechToTextProvider;
use App\Services\Speech\SpeechToTextException;
use Illuminate\Support\Facades\Http;

function speechFixture(): string
{
    $path = tempnam(sys_get_temp_dir(), 'speech-test-');

    expect($path)->not->toBeFalse();
    file_put_contents($path, 'audio');

    return $path;
}

afterEach(function (): void {
    Http::preventStrayRequests();
});

test('the self hosted provider returns transcript metadata', function (): void {
    $path = speechFixture();
    config()->set('speech_to_text.url', 'http://speech-to-text.test');
    Http::fake([
        '*' => Http::response([
            'text' => 'როგორ დავაბრუნო ნივთი?',
            'language' => 'ka',
            'duration_seconds' => 3.84,
        ]),
    ]);

    try {
        $result = app(SelfHostedWhisperSpeechToTextProvider::class)->transcribe(
            $path,
            'audio/webm',
            null,
        );
    } finally {
        unlink($path);
    }

    expect($result->text)->toBe('როგორ დავაბრუნო ნივთი?')
        ->and($result->language)->toBe('ka')
        ->and($result->durationSeconds)->toBe(3.84);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://speech-to-text.test/transcribe');
});

test('the self hosted provider maps connection failures to a safe timeout category', function (): void {
    $path = speechFixture();
    Http::fake([
        '*' => Http::failedConnection(),
    ]);

    try {
        expect(fn (): mixed => app(SelfHostedWhisperSpeechToTextProvider::class)->transcribe($path))
            ->toThrow(fn (SpeechToTextException $exception): bool => $exception->category === 'transcription_timeout');
    } finally {
        unlink($path);
    }
});

test('the self hosted provider rejects an empty transcript', function (): void {
    $path = speechFixture();
    Http::fake([
        '*' => Http::response(['text' => '   ']),
    ]);

    try {
        expect(fn (): mixed => app(SelfHostedWhisperSpeechToTextProvider::class)->transcribe($path))
            ->toThrow(fn (SpeechToTextException $exception): bool => $exception->category === 'empty_transcript');
    } finally {
        unlink($path);
    }
});
