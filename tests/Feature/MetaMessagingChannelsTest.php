<?php

use App\Enums\ChannelConnectionStatus;
use App\Enums\ConversationChannel;
use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\ChannelConnection;
use App\Models\ChannelCredential;
use App\Models\ChannelMessageReceipt;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\Contracts\AiClient;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class MetaMessagingFakeAiClient implements AiClient
{
    public int $calls = 0;

    public function createResponse(array $payload): array
    {
        $this->calls++;

        return ['output' => [], 'output_text' => 'Hello from Meta.', 'usage' => null];
    }
}

/** @return array{0: User, 1: Team, 2: Bot} */
function metaMessagingContext(TeamRole $role = TeamRole::Developer): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    return [$user, $team, $bot];
}

test('authorized users can configure Instagram and Messenger with masked credentials', function () {
    [$user, $team, $bot] = metaMessagingContext();

    $this->actingAs($user)
        ->post(route('bots.channels.instagram.store', [$team->slug, $bot]), [
            'instagram_account_id' => 'instagram-account-1',
            'facebook_page_id' => 'page-1',
            'display_name' => 'Store Instagram',
            'username' => 'store.example',
            'access_token' => 'instagram-secret-token',
            'webhook_verify_token' => 'instagram-verify-token',
            'app_secret' => 'instagram-app-secret',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('bots.channels.messenger.store', [$team->slug, $bot]), [
            'facebook_page_id' => 'page-2',
            'page_name' => 'Store Messenger',
            'access_token' => 'messenger-secret-token',
            'webhook_verify_token' => 'messenger-verify-token',
            'app_secret' => 'messenger-app-secret',
        ])
        ->assertRedirect();

    $credentials = ChannelCredential::query()->where('provider', 'meta')->get();

    expect(ChannelConnection::query()->where('bot_id', $bot->id)->where('channel', ConversationChannel::Instagram)->firstOrFail()->status)
        ->toBe(ChannelConnectionStatus::Active)
        ->and($credentials)->toHaveCount(2)
        ->and($credentials->pluck('encrypted_access_token')->all())
        ->toContain('instagram-secret-token', 'messenger-secret-token')
        ->and($credentials->firstOrFail()->getRawOriginal('encrypted_access_token'))
        ->not->toBe('instagram-secret-token');

    $this->actingAs($user)
        ->get(route('bots.channels.index', [$team->slug, $bot]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.2.implemented', true)
            ->where('channels.2.connection.tokenConfigured', true)
            ->where('channels.2.connection.tokenLastFour', 'oken')
            ->missing('channels.2.connection.accessToken')
            ->missing('channels.2.connection.appSecret')
            ->where('channels.3.connection.pageName', 'Store Messenger'));
});

test('foreign teams and unsupported roles cannot configure Meta messaging', function () {
    [$user, $team] = metaMessagingContext();
    $foreignTeam = Team::factory()->create();
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id]);

    $this->actingAs($user)
        ->post(route('bots.channels.instagram.store', [$team->slug, $foreignBot]), [])
        ->assertNotFound();

    [$supportAgent, $supportTeam, $supportBot] = metaMessagingContext(TeamRole::SupportAgent);

    $this->actingAs($supportAgent)
        ->post(route('bots.channels.messenger.store', [$supportTeam->slug, $supportBot]), [])
        ->assertForbidden();
});

test('Meta webhook verification accepts valid tokens and rejects invalid tokens', function () {
    [$user, $team, $bot] = metaMessagingContext();

    $this->actingAs($user)
        ->post(route('bots.channels.instagram.store', [$team->slug, $bot]), [
            'instagram_account_id' => 'instagram-account-1',
            'facebook_page_id' => 'page-1',
            'access_token' => 'token',
            'webhook_verify_token' => 'verify-token',
            'app_secret' => 'app-secret',
        ]);

    $this->get(route('channels.meta.webhook.verify', [
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'verify-token',
        'hub_challenge' => 'meta-challenge',
    ]))->assertOk()->assertSeeText('meta-challenge');

    $this->get(route('channels.meta.webhook.verify', [
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'wrong-token',
        'hub_challenge' => 'meta-challenge',
    ]))->assertForbidden();

    $payload = [
        'object' => 'instagram',
        'entry' => [[
            'id' => 'instagram-account-1',
            'messaging' => [],
        ]],
    ];

    $this->postJson(route('channels.meta.webhook.receive'), $payload, [
        'X-Hub-Signature-256' => 'sha256=invalid',
    ])->assertForbidden();
});

test('Instagram text webhook is authenticated, deduplicated, and uses the unified runtime', function () {
    [$user, $team, $bot] = metaMessagingContext();
    $connection = configureMetaChannel($this, $user, $team, $bot, ConversationChannel::Instagram);
    $fake = fakeMetaAiClient();
    app()->instance(AiClient::class, $fake);
    Http::fake(['https://graph.facebook.com/*' => Http::response(['message_id' => 'ig-out'])]);

    $payload = [
        'object' => 'instagram',
        'entry' => [[
            'id' => 'instagram-account-1',
            'messaging' => [[
                'sender' => ['id' => 'ig-user-1'],
                'recipient' => ['id' => 'instagram-account-1'],
                'timestamp' => '1787480000',
                'message' => ['mid' => 'ig-mid-1', 'text' => 'Hello Instagram'],
            ]],
        ]],
    ];

    sendMetaWebhook($this, $payload, 'instagram-app-secret');
    sendMetaWebhook($this, $payload, 'instagram-app-secret');

    $conversation = Conversation::query()->where('channel_connection_id', $connection->id)->firstOrFail();

    expect($conversation->channel)->toBe(ConversationChannel::Instagram)
        ->and($conversation->external_user_reference)->toBe('ig-user-1')
        ->and($conversation->messages()->where('role', 'user')->count())->toBe(1)
        ->and($conversation->messages()->where('role', 'assistant')->count())->toBe(1)
        ->and($fake->calls)->toBe(1)
        ->and(ChannelMessageReceipt::query()->where('external_message_reference', 'ig-mid-1')->count())->toBe(1)
        ->and(Message::query()->where('external_message_reference', 'ig-mid-1')->count())->toBe(1);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://graph.facebook.com/v22.0/instagram-account-1/messages'
        && $request->header('Authorization') === ['Bearer instagram-access-token']
        && $request['recipient']['id'] === 'ig-user-1'
        && $request['message']['text'] === 'Hello from Meta.');
    Http::assertSentCount(1);
});

test('Messenger text webhook resolves only its Page connection and delivers through Meta', function () {
    [$user, $team, $bot] = metaMessagingContext();
    $connection = configureMetaChannel($this, $user, $team, $bot, ConversationChannel::FacebookMessenger);
    $fake = fakeMetaAiClient();
    app()->instance(AiClient::class, $fake);
    Http::fake(['https://graph.facebook.com/*' => Http::response(['message_id' => 'm-out'])]);

    sendMetaWebhook($this, [
        'object' => 'page',
        'entry' => [[
            'id' => 'page-1',
            'messaging' => [[
                'sender' => ['id' => 'psid-1'],
                'recipient' => ['id' => 'page-1'],
                'timestamp' => '1787480000',
                'message' => ['mid' => 'm-mid-1', 'text' => 'Hello Messenger'],
            ]],
        ]],
    ], 'messenger-app-secret');

    $conversation = Conversation::query()->where('channel_connection_id', $connection->id)->firstOrFail();

    expect($conversation->channel)->toBe(ConversationChannel::FacebookMessenger)
        ->and($conversation->external_user_reference)->toBe('psid-1')
        ->and($fake->calls)->toBe(1)
        ->and(ChannelMessageReceipt::query()->where('external_message_reference', 'm-mid-1')->count())->toBe(1);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://graph.facebook.com/v22.0/page-1/messages'
        && $request['recipient']['id'] === 'psid-1'
        && $request['message']['text'] === 'Hello from Meta.');
});

function configureMetaChannel(TestCase $test, User $user, Team $team, Bot $bot, ConversationChannel $channel): ChannelConnection
{
    $route = $channel === ConversationChannel::Instagram
        ? 'bots.channels.instagram.store'
        : 'bots.channels.messenger.store';
    $values = $channel === ConversationChannel::Instagram
        ? [
            'instagram_account_id' => 'instagram-account-1',
            'facebook_page_id' => 'page-1',
            'access_token' => 'instagram-access-token',
            'webhook_verify_token' => 'instagram-verify-token',
            'app_secret' => 'instagram-app-secret',
        ]
        : [
            'facebook_page_id' => 'page-1',
            'access_token' => 'messenger-access-token',
            'webhook_verify_token' => 'messenger-verify-token',
            'app_secret' => 'messenger-app-secret',
        ];

    $test->actingAs($user)->post(route($route, [$team->slug, $bot]), $values)->assertRedirect();

    return $bot->channelConnections()->where('channel', $channel->value)->firstOrFail();
}

function fakeMetaAiClient(): MetaMessagingFakeAiClient
{
    return new MetaMessagingFakeAiClient;
}

/** @param array<string, mixed> $payload */
function sendMetaWebhook(TestCase $test, array $payload, string $appSecret): void
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $test->postJson(route('channels.meta.webhook.receive'), $payload, [
        'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $appSecret),
    ])->assertNoContent();
}
