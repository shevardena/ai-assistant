<?php

use App\Enums\BotStatus;
use App\Enums\ConversationChannel;
use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\BotDataset;
use App\Models\Conversation;
use App\Models\Dataset;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Models\WidgetVisitor;
use App\Services\Ai\AiException;
use App\Services\Ai\Contracts\AiClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function publicWidgetContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->published()->create([
        'team_id' => $team->id,
    ]);
    $dataset = Dataset::factory()->ready()->create(['team_id' => $team->id]);

    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'is_enabled' => true,
    ]);

    $bot->domains()->create(['domain' => 'example.com']);

    return [$user, $team, $bot];
}

test('ready and legacy published bots expose the widget bootstrap and session', function () {
    [, , $bot] = publicWidgetContext();

    $bot->update([
        'status' => BotStatus::Ready->value,
        'published_at' => null,
    ]);

    $this->withHeader('Origin', 'https://example.com')
        ->get(route('widget.show', ['botPublicId' => $bot->public_id]))
        ->assertOk()
        ->assertSee('widget-root');

    $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.session', ['botPublicId' => $bot->public_id]), [])
        ->assertOk();

    $bot->update(['status' => BotStatus::Published->value]);

    $this->withHeader('Origin', 'https://example.com')
        ->get(route('widget.show', ['botPublicId' => $bot->public_id]))
        ->assertOk();

    $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.session', ['botPublicId' => $bot->public_id]), [])
        ->assertOk();

    foreach ([BotStatus::Draft, BotStatus::Disabled] as $unavailableStatus) {
        $bot->update(['status' => $unavailableStatus->value]);

        $this->withHeader('Origin', 'https://example.com')
            ->get(route('widget.show', ['botPublicId' => $bot->public_id]))
            ->assertOk()
            ->assertSee('data-availability="offline"');

        $this->withHeader('Origin', 'https://example.com')
            ->postJson(route('widget.session', ['botPublicId' => $bot->public_id]), [])
            ->assertNotFound();
    }
});

test('the public status endpoint is read-only and reports runtime availability', function () {
    [, , $bot] = publicWidgetContext();

    $this->withHeader('Origin', 'https://example.com')
        ->getJson(route('widget.status', ['botPublicId' => $bot->public_id]))
        ->assertOk()
        ->assertJsonPath('availability', 'online');

    expect(WidgetVisitor::query()->where('bot_id', $bot->id)->count())->toBe(0)
        ->and(Conversation::query()->where('bot_id', $bot->id)->count())->toBe(0)
        ->and(Message::query()->whereHas('conversation', fn ($query) => $query->where('bot_id', $bot->id))->count())->toBe(0);

    $bot->update(['status' => BotStatus::Disabled->value]);

    $this->withHeader('Origin', 'https://example.com')
        ->getJson(route('widget.status', ['botPublicId' => $bot->public_id]))
        ->assertOk()
        ->assertJsonPath('availability', 'offline');
});

test('an unlisted website cannot load the widget bootstrap', function () {
    [, , $bot] = publicWidgetContext();

    $this->withHeader('Origin', 'https://evil.com')
        ->get(route('widget.show', ['botPublicId' => $bot->public_id]))
        ->assertNotFound();
});

test('an allowed website can load the widget bootstrap through its referrer', function () {
    [, , $bot] = publicWidgetContext();

    $this->withHeader('Referer', 'https://example.com/products')
        ->get(route('widget.show', ['botPublicId' => $bot->public_id]))
        ->assertOk()
        ->assertSee('widget-root');
});

test('an unlisted origin is rejected before a visitor or conversation is created', function () {
    [, , $bot] = publicWidgetContext();

    $this->withHeader('Origin', 'https://evil.com')
        ->postJson(route('widget.session', ['botPublicId' => $bot->public_id]), [])
        ->assertForbidden();

    expect(WidgetVisitor::query()->where('bot_id', $bot->id)->exists())->toBeFalse()
        ->and(Conversation::query()->where('bot_id', $bot->id)->exists())->toBeFalse();
});

test('an allowed origin creates a visitor and widget conversation', function () {
    [, , $bot] = publicWidgetContext();

    $response = $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.session', ['botPublicId' => $bot->public_id]), [])
        ->assertOk()
        ->assertJsonPath('bot.name', $bot->name);

    $conversation = Conversation::query()->where('public_id', $response->json('conversation_id'))->firstOrFail();
    $visitor = WidgetVisitor::query()->where('public_id', $response->json('visitor_id'))->firstOrFail();

    expect($conversation->bot_id)->toBe($bot->id)
        ->and($conversation->visitor_id)->toBe($visitor->id)
        ->and($conversation->channel)->toBe(ConversationChannel::Website)
        ->and($conversation->external_user_reference)->toBe($visitor->public_id)
        ->and($conversation->external_conversation_reference)->toBe($conversation->public_id)
        ->and($conversation->metadata['source'])->toBe('widget')
        ->and($conversation->messages()->count())->toBe(0);
});

test('localhost origins are allowed for local widget testing without a bot domain', function () {
    config()->set('widget.allow_localhost', true);
    [, , $bot] = publicWidgetContext();

    $this->withHeader('Origin', 'http://localhost:8000')
        ->postJson(route('widget.session', ['botPublicId' => $bot->public_id]), [])
        ->assertOk();
});

test('a visitor can send messages and cannot use another visitors conversation', function () {
    [, , $bot] = publicWidgetContext();
    app()->instance(AiClient::class, new class implements AiClient
    {
        public int $attempts = 0;

        public function createResponse(array $payload): array
        {
            $this->attempts++;

            if ($this->attempts === 1) {
                throw new AiException('Temporary widget failure.');
            }

            return [
                'output' => [],
                'output_text' => 'Grounded widget answer.',
                'usage' => null,
            ];
        }
    });

    $session = $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.session', ['botPublicId' => $bot->public_id]), []);
    $conversationId = $session->json('conversation_id');
    $visitorId = $session->json('visitor_id');
    $clientMessageId = (string) Str::uuid();

    $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.messages', ['botPublicId' => $bot->public_id]), [
            'conversation_id' => $conversationId,
            'visitor_id' => $visitorId,
            'client_message_id' => $clientMessageId,
            'message' => 'Hello',
        ])
        ->assertServiceUnavailable();

    $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.messages', ['botPublicId' => $bot->public_id]), [
            'conversation_id' => $conversationId,
            'visitor_id' => $visitorId,
            'client_message_id' => $clientMessageId,
            'message' => 'Hello',
        ])
        ->assertOk()
        ->assertJsonPath('message.content', 'Grounded widget answer.')
        ->assertJsonPath('user_message.id', fn (mixed $id): bool => is_int($id));

    $otherSession = $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.session', ['botPublicId' => $bot->public_id]), [])
        ->assertOk();

    $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.messages', ['botPublicId' => $bot->public_id]), [
            'conversation_id' => $conversationId,
            'visitor_id' => $otherSession->json('visitor_id'),
            'message' => 'Should be rejected',
        ])
        ->assertNotFound();

    $conversation = Conversation::query()->where('public_id', $conversationId)->firstOrFail();

    expect(Message::query()->where('conversation_id', $conversation->id)->count())->toBe(3)
        ->and(Message::query()->where('conversation_id', $conversation->id)->where('role', 'user')->count())->toBe(1)
        ->and($conversation->messages()->oldest('id')->first()?->content)->toBe($bot->welcome_message);
});

test('widget sessions and messages are rate limited', function () {
    config()->set('widget.rate_limit_per_minute', 1);
    [, , $bot] = publicWidgetContext();
    $key = $bot->public_id.'|anonymous|127.0.0.1';
    RateLimiter::clear($key);

    $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.session', ['botPublicId' => $bot->public_id]), [])
        ->assertOk();

    $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.session', ['botPublicId' => $bot->public_id]), [])
        ->assertTooManyRequests();
});

test('a widget image message is persisted and sent as multimodal input', function (): void {
    Storage::fake('local');
    [, , $bot] = publicWidgetContext();
    $fake = new class implements AiClient
    {
        /** @var list<array<string, mixed>> */
        public array $payloads = [];

        public function createResponse(array $payload): array
        {
            $this->payloads[] = $payload;

            return [
                'output' => [],
                'output_text' => 'I found a matching catalog result.',
                'usage' => null,
            ];
        }
    };
    app()->instance(AiClient::class, $fake);

    $session = $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.session', ['botPublicId' => $bot->public_id]), [])
        ->assertOk();

    $response = $this->withHeader('Origin', 'https://example.com')
        ->post(route('widget.messages', ['botPublicId' => $bot->public_id]), [
            'conversation_id' => $session->json('conversation_id'),
            'visitor_id' => $session->json('visitor_id'),
            'message' => 'Do you have this?',
            'image' => UploadedFile::fake()->image('catalog-photo.jpg'),
        ])
        ->assertOk()
        ->assertJsonPath('message.content', 'I found a matching catalog result.');

    $userMessage = Message::query()
        ->where('role', 'user')
        ->where('content', 'Do you have this?')
        ->firstOrFail();
    $input = $fake->payloads[0]['input'];
    $currentMessage = $input[array_key_last($input)];

    expect($userMessage->metadata['attachments'][0])
        ->toMatchArray(['type' => 'image', 'mime_type' => 'image/jpeg'])
        ->toHaveKey('storage_path')
        ->and($currentMessage['content'][0])->toMatchArray([
            'type' => 'input_text',
            'text' => 'Do you have this?',
        ])
        ->and($currentMessage['content'][1]['type'])->toBe('input_image')
        ->and($currentMessage['content'][1]['image_url'])->toStartWith('data:image/jpeg;base64,')
        ->and($response->json('user_message.attachments.0.url'))->toContain('/api/widget/'.$bot->public_id.'/attachments/'.$userMessage->id)
        ->and($response->json('user_message.attachments.0.url'))->not->toContain('widget-attachments/');
});
