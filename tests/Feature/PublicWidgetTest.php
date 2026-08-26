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
use App\Services\Ai\Contracts\AiClient;
use Illuminate\Support\Facades\RateLimiter;

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
        public function createResponse(array $payload): array
        {
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

    $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.messages', ['botPublicId' => $bot->public_id]), [
            'conversation_id' => $conversationId,
            'visitor_id' => $visitorId,
            'message' => 'Hello',
        ])
        ->assertOk()
        ->assertJsonPath('message.content', 'Grounded widget answer.');

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
