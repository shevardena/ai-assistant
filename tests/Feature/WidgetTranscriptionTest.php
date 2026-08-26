<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\TeamSubscription;
use App\Services\Speech\Contracts\SpeechToTextProvider;
use App\Services\Speech\SpeechToTextException;
use App\Services\Speech\TranscriptionResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('free teams cannot transcribe or create an external provider request', function (): void {
    [, $team, $bot] = publicWidgetContext();
    TeamSubscription::factory()->create(['team_id' => $team->id, 'plan_key' => 'free']);
    Storage::fake('local');
    Http::fake();

    $this->withHeader('Origin', 'https://example.com')
        ->post(route('widget.transcribe', ['botPublicId' => $bot->public_id]), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])
        ->assertStatus(403)
        ->assertJsonPath('error', 'voice_feature_unavailable');

    Http::assertNothingSent();
    expect(Conversation::query()->where('bot_id', $bot->id)->count())->toBe(0)
        ->and(Message::query()->whereHas('conversation', fn ($query) => $query->where('bot_id', $bot->id))->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('paid plans expose voice input through the public session capability', function (string $plan): void {
    [, $team, $bot] = publicWidgetContext();
    TeamSubscription::factory()->create(['team_id' => $team->id, 'plan_key' => $plan]);

    $this->withHeader('Origin', 'https://example.com')
        ->post(route('widget.session', ['botPublicId' => $bot->public_id]), [])
        ->assertOk()
        ->assertJsonPath('bot.capabilities.voice_input', true);
})->with(['starter', 'pro', 'business']);

test('free plans expose voice input as unavailable through the public session capability', function (): void {
    [, $team, $bot] = publicWidgetContext();
    TeamSubscription::factory()->create(['team_id' => $team->id, 'plan_key' => 'free']);

    $this->withHeader('Origin', 'https://example.com')
        ->post(route('widget.session', ['botPublicId' => $bot->public_id]), [])
        ->assertOk()
        ->assertJsonPath('bot.capabilities.voice_input', false);
});

test('voice input capability is isolated between teams', function (): void {
    [, $freeTeam, $freeBot] = publicWidgetContext();
    [, $paidTeam, $paidBot] = publicWidgetContext();
    TeamSubscription::factory()->create(['team_id' => $freeTeam->id, 'plan_key' => 'free']);
    TeamSubscription::factory()->create(['team_id' => $paidTeam->id, 'plan_key' => 'starter']);

    $this->withHeader('Origin', 'https://example.com')
        ->post(route('widget.session', ['botPublicId' => $freeBot->public_id]), [])
        ->assertOk()
        ->assertJsonPath('bot.capabilities.voice_input', false);

    $this->withHeader('Origin', 'https://example.com')
        ->post(route('widget.session', ['botPublicId' => $paidBot->public_id]), [])
        ->assertOk()
        ->assertJsonPath('bot.capabilities.voice_input', true);
});

test('public transcription returns an editable transcript without chat side effects', function (): void {
    [, , $bot] = publicWidgetContext();
    Storage::fake('local');
    app()->instance(SpeechToTextProvider::class, new class implements SpeechToTextProvider
    {
        public function transcribe(string $absolutePath, ?string $mimeType = null, ?string $languageHint = null): TranscriptionResult
        {
            expect(is_file($absolutePath))->toBeTrue();

            return new TranscriptionResult('როგორ დავაბრუნო ნივთი?', 'ka', 2.4);
        }
    });

    $response = $this->withHeader('Origin', 'https://example.com')
        ->post(route('widget.transcribe', ['botPublicId' => $bot->public_id]), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ]);

    $response->assertOk()
        ->assertJson([
            'text' => 'როგორ დავაბრუნო ნივთი?',
            'language' => 'ka',
        ]);

    expect(Conversation::query()->where('bot_id', $bot->id)->count())->toBe(0)
        ->and(Message::query()->whereHas('conversation', fn ($query) => $query->where('bot_id', $bot->id))->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('transcription rejects an unlisted origin before storing audio', function (): void {
    [, , $bot] = publicWidgetContext();
    Storage::fake('local');
    app()->instance(SpeechToTextProvider::class, new class implements SpeechToTextProvider
    {
        public function transcribe(string $absolutePath, ?string $mimeType = null, ?string $languageHint = null): TranscriptionResult
        {
            throw new RuntimeException('Provider must not be called.');
        }
    });

    $this->withHeader('Origin', 'https://evil.com')
        ->post(route('widget.transcribe', ['botPublicId' => $bot->public_id]), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])
        ->assertForbidden();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('provider failures return a safe error and remove temporary audio', function (): void {
    [, , $bot] = publicWidgetContext();
    Storage::fake('local');
    app()->instance(SpeechToTextProvider::class, new class implements SpeechToTextProvider
    {
        public function transcribe(string $absolutePath, ?string $mimeType = null, ?string $languageHint = null): TranscriptionResult
        {
            throw new SpeechToTextException('private provider details', 'transcription_failed');
        }
    });

    $this->withHeader('Origin', 'https://example.com')
        ->post(route('widget.transcribe', ['botPublicId' => $bot->public_id]), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'transcription_failed')
        ->assertJsonMissing(['message' => 'private provider details']);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});
