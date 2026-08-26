<?php

use App\Data\ChannelOutboundMessage;
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
use App\Services\Channels\WhatsAppChannelAdapter;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{0: User, 1: Team, 2: Bot, 3: ChannelConnection, 4: string, 5: string} */
function whatsappContext(TeamRole $role = TeamRole::Developer): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $connection = ChannelConnection::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'channel' => ConversationChannel::WhatsApp->value,
        'name' => 'WhatsApp',
        'status' => ChannelConnectionStatus::Active->value,
        'provider_channel_reference' => 'phone-number-123',
        'provider_account_reference' => 'business-456',
        'configuration' => ['display_phone_number' => '+995 555 000 123', 'verified_name' => 'Store Bot'],
    ]);
    $verifyToken = 'verify-token-123';
    $appSecret = 'app-secret-123';

    ChannelCredential::query()->create([
        'team_id' => $team->id,
        'channel_connection_id' => $connection->id,
        'created_by_user_id' => $user->id,
        'provider' => 'whatsapp',
        'encrypted_access_token' => 'access-token-secret',
        'encrypted_verify_token' => $verifyToken,
        'encrypted_app_secret' => $appSecret,
        'verify_token_hash' => hash('sha256', $verifyToken),
        'access_token_last_four' => 'cret',
    ]);

    return [$user, $team, $bot, $connection, $verifyToken, $appSecret];
}

test('authorized users can configure WhatsApp without exposing credentials', function () {
    [$user, $team, $bot] = whatsappContext();
    $bot->channelConnections()->where('channel', 'whatsapp')->delete();

    $this->actingAs($user)
        ->post(route('bots.channels.whatsapp.store', [$team->slug, $bot]), [
            'phone_number_id' => 'phone-number-999',
            'business_account_id' => 'business-999',
            'display_phone_number' => '+995 555 000 999',
            'access_token' => 'new-access-token',
            'webhook_verify_token' => 'new-verify-token',
            'app_secret' => 'new-app-secret',
        ])
        ->assertRedirect();

    $connection = $bot->channelConnections()->where('channel', 'whatsapp')->firstOrFail();
    $credential = $connection->credential()->firstOrFail();

    expect($connection->status)->toBe(ChannelConnectionStatus::Active)
        ->and($credential->encrypted_access_token)->toBe('new-access-token')
        ->and($credential->getRawOriginal('encrypted_access_token'))->not->toBe('new-access-token');

    $this->actingAs($user)
        ->get(route('bots.channels.index', [$team->slug, $bot]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.1.implemented', true)
            ->where('channels.1.connection.tokenConfigured', true)
            ->where('channels.1.connection.tokenLastFour', 'oken')
            ->missing('channels.1.connection.accessToken')
            ->missing('channels.1.connection.appSecret'));
});

test('foreign teams cannot configure a WhatsApp connection', function () {
    [$user, $team] = whatsappContext();
    $foreignTeam = Team::factory()->create();
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id]);

    $this->actingAs($user)
        ->post(route('bots.channels.whatsapp.store', [$team->slug, $foreignBot]), [
            'phone_number_id' => 'phone-number-999',
            'access_token' => 'secret',
            'app_secret' => 'app-secret',
        ])
        ->assertNotFound();
});

test('WhatsApp webhook verification returns only a valid challenge', function () {
    [, , , , $verifyToken] = whatsappContext();

    $this->get(route('channels.whatsapp.webhook.verify', [
        'hub_mode' => 'subscribe',
        'hub_verify_token' => $verifyToken,
        'hub_challenge' => 'challenge-123',
    ]))->assertOk()->assertSeeText('challenge-123');

    $this->get(route('channels.whatsapp.webhook.verify', [
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'wrong-token',
        'hub_challenge' => 'challenge-123',
    ]))->assertForbidden();
});

test('WhatsApp text webhooks are authenticated, deduplicated, and use the unified runtime', function () {
    [$user, , $bot, $connection, , $appSecret] = whatsappContext();
    $fake = new class implements AiClient
    {
        public int $calls = 0;

        public function createResponse(array $payload): array
        {
            $this->calls++;

            return ['output' => [], 'output_text' => 'Hello from WhatsApp.', 'usage' => null];
        }
    };
    app()->instance(AiClient::class, $fake);
    Http::fake(['https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]])]);
    $payload = ['entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => 'phone-number-123'],
        'messages' => [[
            'from' => '995555111222',
            'id' => 'wamid.in',
            'timestamp' => '1787480000',
            'type' => 'text',
            'text' => ['body' => 'Hello'],
        ]],
    ]]]]]];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $appSecret),
    ];

    $this->actingAs($user);
    $this->call('POST', route('channels.whatsapp.webhook.receive'), [], [], [], $headers, $body)->assertNoContent();
    $this->call('POST', route('channels.whatsapp.webhook.receive'), [], [], [], $headers, $body)->assertNoContent();

    $conversation = Conversation::query()->where('channel_connection_id', $connection->id)->firstOrFail();

    expect($conversation->channel)->toBe(ConversationChannel::WhatsApp)
        ->and($conversation->external_user_reference)->toBe('995555111222')
        ->and($conversation->messages()->where('role', 'user')->count())->toBe(1)
        ->and($conversation->messages()->where('role', 'assistant')->count())->toBe(1)
        ->and($fake->calls)->toBe(1)
        ->and(ChannelMessageReceipt::query()->where('external_message_reference', 'wamid.in')->count())->toBe(1)
        ->and(Message::query()->where('external_message_reference', 'wamid.in')->count())->toBe(1);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://graph.facebook.com/v22.0/phone-number-123/messages'
            && $request->header('Authorization') === ['Bearer access-token-secret']
            && $request['to'] === '995555111222'
            && $request['text']['body'] === 'Hello from WhatsApp.';
    });
    Http::assertSentCount(1);
});

test('WhatsApp outbound provider failures are normalized without leaking provider details', function () {
    [, , , $connection] = whatsappContext();
    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'private provider detail'],
        ], 401),
    ]);

    $result = app(WhatsAppChannelAdapter::class)->send($connection, new ChannelOutboundMessage(
        channel: ConversationChannel::WhatsApp,
        text: 'Hello',
        externalUserId: '995555111222',
    ));

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('whatsapp_auth_failed')
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('private provider detail');
});
