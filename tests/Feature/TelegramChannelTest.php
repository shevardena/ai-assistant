<?php

use App\Enums\ChannelConnectionStatus;
use App\Enums\ConversationChannel;
use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\ChannelConnection;
use App\Models\ChannelMessageReceipt;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\Contracts\AiClient;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class TelegramFakeAiClient implements AiClient
{
    public int $calls = 0;

    public function createResponse(array $payload): array
    {
        $this->calls++;

        return ['output' => [], 'output_text' => 'Hello from Telegram.', 'usage' => null];
    }
}

/** @return array{0: User, 1: Team, 2: Bot} */
function telegramContext(TeamRole $role = TeamRole::Developer): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    return [$user, $team, $bot];
}

test('authorized users configure Telegram through getMe and setWebhook without exposing the token', function () {
    [$user, $team, $bot] = telegramContext();
    fakeTelegramSetup();

    $this->actingAs($user)
        ->post(route('bots.channels.telegram.store', [$team->slug, $bot]), [
            'bot_token' => 'telegram-token-123',
        ])
        ->assertRedirect();

    $connection = $bot->channelConnections()->where('channel', ConversationChannel::Telegram->value)->firstOrFail();
    $credential = $connection->credential()->firstOrFail();

    expect($connection->status)->toBe(ChannelConnectionStatus::Active)
        ->and($connection->provider_channel_reference)->toBe('123456')
        ->and($connection->configuration)->toMatchArray([
            'bot_username' => 'support_bot',
            'display_name' => 'Support',
            'webhook_configured' => true,
        ])
        ->and($credential->encrypted_access_token)->toBe('telegram-token-123')
        ->and($credential->getRawOriginal('encrypted_access_token'))->not->toBe('telegram-token-123')
        ->and($credential->encrypted_verify_token)->not->toBe('');

    $this->actingAs($user)
        ->get(route('bots.channels.index', [$team->slug, $bot]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.4.implemented', true)
            ->where('channels.4.connection.botUsername', 'support_bot')
            ->where('channels.4.connection.tokenConfigured', true)
            ->where('channels.4.connection.webhookConfigured', true)
            ->missing('channels.4.connection.botToken')
            ->missing('channels.4.connection.webhookSecret'));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token-123/getMe');
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/setWebhook')
        && str_contains((string) $request['url'], '/api/channels/telegram/'.$connection->public_id.'/webhook')
        && is_string($request['secret_token'])
        && strlen($request['secret_token']) === 64
        && $request['allowed_updates'] === ['message']);
});

test('invalid Telegram tokens and provider webhook failures do not activate a connection', function () {
    [$user, $team, $bot] = telegramContext();
    Http::fake([
        'https://api.telegram.org/botbad-token/getMe' => Http::response([
            'ok' => false,
            'error_code' => 401,
            'description' => 'Unauthorized',
        ], 401),
    ]);

    $this->actingAs($user)
        ->post(route('bots.channels.telegram.store', [$team->slug, $bot]), ['bot_token' => 'bad-token'])
        ->assertSessionHasErrors('bot_token');

    expect($bot->channelConnections()->where('channel', ConversationChannel::Telegram->value)->exists())->toBeFalse();

    fakeTelegramGetMeOnly();
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/getMe')) {
            return Http::response([
                'ok' => true,
                'result' => ['id' => 123456, 'is_bot' => true, 'first_name' => 'Support', 'username' => 'support_bot'],
            ]);
        }

        return Http::response(['ok' => false, 'error_code' => 500, 'description' => 'provider unavailable'], 500);
    });

    $this->actingAs($user)
        ->post(route('bots.channels.telegram.store', [$team->slug, $bot]), ['bot_token' => 'telegram-token-123'])
        ->assertSessionHasErrors('bot_token');

    expect($bot->channelConnections()->where('channel', ConversationChannel::Telegram->value)->firstOrFail()->status)
        ->toBe(ChannelConnectionStatus::Error);
});

test('foreign teams and unsupported roles cannot configure Telegram', function () {
    [$user, $team] = telegramContext();
    $foreignTeam = Team::factory()->create();
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id]);

    $this->actingAs($user)
        ->post(route('bots.channels.telegram.store', [$team->slug, $foreignBot]), [])
        ->assertNotFound();

    [$supportAgent, $supportTeam, $supportBot] = telegramContext(TeamRole::SupportAgent);

    $this->actingAs($supportAgent)
        ->post(route('bots.channels.telegram.store', [$supportTeam->slug, $supportBot]), [])
        ->assertForbidden();
});

test('Telegram private text webhooks validate the secret, deduplicate updates, and use the unified runtime', function () {
    [$user, $team, $bot] = telegramContext();
    fakeTelegramSetup();
    $this->actingAs($user)
        ->post(route('bots.channels.telegram.store', [$team->slug, $bot]), ['bot_token' => 'telegram-token-123'])
        ->assertRedirect();
    $connection = $bot->channelConnections()->where('channel', ConversationChannel::Telegram->value)->firstOrFail();
    $secret = $connection->credential()->firstOrFail()->encrypted_verify_token;

    $fake = new TelegramFakeAiClient;
    app()->instance(AiClient::class, $fake);
    Http::fake([
        'https://api.telegram.org/bottelegram-token-123/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 88],
        ]),
    ]);
    $payload = [
        'update_id' => 7001,
        'message' => [
            'message_id' => 42,
            'from' => ['id' => 9001, 'first_name' => 'Jane'],
            'chat' => ['id' => 9001, 'type' => 'private'],
            'date' => 1787480000,
            'text' => 'Hello',
        ],
    ];

    sendTelegramUpdate($this, $connection, $payload, $secret);
    sendTelegramUpdate($this, $connection, $payload, $secret);

    $conversation = Conversation::query()->where('channel_connection_id', $connection->id)->firstOrFail();

    expect($conversation->channel)->toBe(ConversationChannel::Telegram)
        ->and($conversation->external_user_reference)->toBe('9001')
        ->and($conversation->messages()->where('role', 'user')->count())->toBe(1)
        ->and($conversation->messages()->where('role', 'assistant')->count())->toBe(1)
        ->and($fake->calls)->toBe(1)
        ->and(ChannelMessageReceipt::query()->where('external_message_reference', 'update:7001')->count())->toBe(1)
        ->and(Message::query()->where('external_message_reference', '7001')->count())->toBe(1);

    Http::assertSent(fn ($request): bool => $request['chat_id'] === '9001'
        && $request['text'] === 'Hello from Telegram.');
    Http::assertSentCount(1);
});

test('Telegram rejects missing or invalid webhook secrets and ignores group chats', function () {
    [$user, $team, $bot] = telegramContext();
    fakeTelegramSetup();
    $this->actingAs($user)
        ->post(route('bots.channels.telegram.store', [$team->slug, $bot]), ['bot_token' => 'telegram-token-123'])
        ->assertRedirect();
    $connection = $bot->channelConnections()->where('channel', ConversationChannel::Telegram->value)->firstOrFail();
    $secret = $connection->credential()->firstOrFail()->encrypted_verify_token;
    $payload = [
        'update_id' => 7002,
        'message' => [
            'message_id' => 43,
            'from' => ['id' => 9001],
            'chat' => ['id' => -100, 'type' => 'group'],
            'text' => 'ignored',
        ],
    ];

    $this->postJson(route('channels.telegram.webhook.receive', $connection->public_id), $payload)
        ->assertForbidden();
    $this->postJson(route('channels.telegram.webhook.receive', $connection->public_id), $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
    ])->assertForbidden();
    $this->postJson(route('channels.telegram.webhook.receive', $connection->public_id), $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => $secret,
    ])->assertNoContent();

    expect(Conversation::query()->where('channel_connection_id', $connection->id)->exists())->toBeFalse();
});

test('disconnecting Telegram deletes the webhook, credentials, and preserves history', function () {
    [$user, $team, $bot] = telegramContext();
    fakeTelegramSetup();
    $this->actingAs($user)
        ->post(route('bots.channels.telegram.store', [$team->slug, $bot]), ['bot_token' => 'telegram-token-123'])
        ->assertRedirect();
    $connection = $bot->channelConnections()->where('channel', ConversationChannel::Telegram->value)->firstOrFail();

    Http::fake([
        'https://api.telegram.org/bottelegram-token-123/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
    ]);

    $this->actingAs($user)
        ->delete(route('bots.channels.telegram.destroy', [$team->slug, $bot]))
        ->assertRedirect();

    expect($connection->fresh()->status)->toBe(ChannelConnectionStatus::Disabled)
        ->and($connection->credential()->exists())->toBeFalse();
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/deleteWebhook'));
});

function fakeTelegramSetup(): void
{
    Http::fake([
        'https://api.telegram.org/bottelegram-token-123/getMe' => Http::response([
            'ok' => true,
            'result' => [
                'id' => 123456,
                'is_bot' => true,
                'first_name' => 'Support',
                'username' => 'support_bot',
            ],
        ]),
        'https://api.telegram.org/bottelegram-token-123/setWebhook' => Http::response([
            'ok' => true,
            'result' => true,
        ]),
    ]);
}

function fakeTelegramGetMeOnly(): void
{
    Http::fake([
        'https://api.telegram.org/bottelegram-token-123/getMe' => Http::response([
            'ok' => true,
            'result' => [
                'id' => 123456,
                'is_bot' => true,
                'first_name' => 'Support',
                'username' => 'support_bot',
            ],
        ]),
    ]);
}

/** @param array<string, mixed> $payload */
function sendTelegramUpdate(TestCase $test, ChannelConnection $connection, array $payload, string $secret): void
{
    $test->postJson(route('channels.telegram.webhook.receive', $connection->public_id), $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => $secret,
    ])->assertNoContent();
}
