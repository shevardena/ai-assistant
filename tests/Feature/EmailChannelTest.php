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

final class EmailFakeAiClient implements AiClient
{
    public int $calls = 0;

    public function createResponse(array $payload): array
    {
        $this->calls++;

        return ['output' => [], 'output_text' => 'Hello by email.', 'usage' => null];
    }
}

/** @return array{0: User, 1: Team, 2: Bot} */
function emailContext(TeamRole $role = TeamRole::Developer): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    return [$user, $team, $bot];
}

function createEmailConnection(Bot $bot, User $user, string $inbound = 'support@inbound.postmarkapp.com'): ChannelConnection
{
    $connection = ChannelConnection::factory()->create([
        'team_id' => $bot->team_id,
        'bot_id' => $bot->id,
        'channel' => ConversationChannel::Email->value,
        'name' => 'Email',
        'status' => ChannelConnectionStatus::Active->value,
        'provider_channel_reference' => $inbound,
        'configuration' => [
            'provider' => 'postmark',
            'inbound_address' => $inbound,
            'from_address' => 'support@example.com',
            'from_name' => 'Support',
            'inbound_status' => 'setup_pending',
        ],
    ]);
    ChannelCredential::query()->create([
        'team_id' => $bot->team_id,
        'channel_connection_id' => $connection->id,
        'created_by_user_id' => $user->id,
        'provider' => 'postmark',
        'encrypted_access_token' => 'server-secret',
        'encrypted_verify_token' => 'webhook-secret',
        'encrypted_app_secret' => null,
        'verify_token_hash' => hash('sha256', 'webhook-secret'),
        'access_token_last_four' => 'cret',
    ]);

    return $connection->fresh(['credential', 'bot']);
}

function emailPayload(string $providerId, string $messageId, string $subject = 'Order issue', array $headers = []): array
{
    return [
        'MessageID' => $providerId,
        'From' => 'Jane <jane@example.com>',
        'To' => 'support@inbound.postmarkapp.com',
        'OriginalRecipient' => 'support@inbound.postmarkapp.com',
        'Subject' => $subject,
        'TextBody' => 'Please help me.',
        'Headers' => [
            ['Name' => 'Message-ID', 'Value' => $messageId],
            ...$headers,
        ],
    ];
}

test('authorized users configure Postmark Email with encrypted credentials and honest inbound setup state', function () {
    [$user, $team, $bot] = emailContext();
    Http::fake(['https://api.postmarkapp.com/*' => Http::response(['ID' => 42], 200)]);

    $this->actingAs($user)
        ->post(route('bots.channels.email.store', [$team->slug, $bot]), [
            'inbound_address' => 'support@inbound.postmarkapp.com',
            'from_address' => 'support@example.com',
            'from_name' => 'Support',
            'server_api_token' => 'server-secret',
            'webhook_secret' => 'webhook-secret',
        ])
        ->assertRedirect();

    $connection = $bot->channelConnections()->where('channel', ConversationChannel::Email->value)->firstOrFail();
    $credential = $connection->credential()->firstOrFail();

    expect($connection->status)->toBe(ChannelConnectionStatus::Active)
        ->and($connection->configuration)->toMatchArray([
            'provider' => 'postmark',
            'inbound_address' => 'support@inbound.postmarkapp.com',
            'from_address' => 'support@example.com',
            'inbound_status' => 'setup_pending',
        ])
        ->and($connection->configuration)->not->toHaveKey('server_api_token')
        ->and($credential->encrypted_access_token)->toBe('server-secret')
        ->and($credential->getRawOriginal('encrypted_access_token'))->not->toBe('server-secret');

    $this->actingAs($user)
        ->get(route('bots.channels.index', [$team->slug, $bot]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.6.key', 'email')
            ->where('channels.6.implemented', true)
            ->where('channels.6.connection.tokenConfigured', true)
            ->where('channels.6.connection.inboundStatus', 'setup_pending')
            ->missing('channels.6.connection.serverApiToken')
            ->missing('channels.6.connection.webhookSecret'));
});

test('Email inbound webhooks are authenticated, deduplicated, threaded, and use one unified runtime', function () {
    [$user, , $bot] = emailContext();
    $connection = createEmailConnection($bot, $user);
    $fake = new EmailFakeAiClient;
    app()->instance(AiClient::class, $fake);
    Http::fake([
        'https://api.postmarkapp.com/email' => Http::response(['MessageID' => 'pm-outbound-1'], 200),
    ]);

    $url = route('channels.email.webhook.receive', $connection->public_id);
    $first = emailPayload('pm-in-1', '<root@example.com>');

    $this->postJson($url, $first)
        ->assertForbidden();

    $this->withBasicAuth('postmark', 'webhook-secret')
        ->postJson($url, $first)
        ->assertNoContent();
    $this->withBasicAuth('postmark', 'webhook-secret')
        ->postJson($url, $first)
        ->assertNoContent();

    $conversation = Conversation::query()->where('channel_connection_id', $connection->id)->firstOrFail();
    $outboundId = Message::query()->where('conversation_id', $conversation->id)->where('role', 'assistant')->value('external_message_reference');
    $second = emailPayload('pm-in-2', '<reply@example.com>', headers: [
        ['Name' => 'In-Reply-To', 'Value' => $outboundId],
    ]);

    $this->withBasicAuth('postmark', 'webhook-secret')
        ->postJson($url, $second)
        ->assertNoContent();

    expect(Conversation::query()->where('channel_connection_id', $connection->id)->count())->toBe(1)
        ->and($conversation->fresh()->messages()->where('role', 'user')->count())->toBe(2)
        ->and($fake->calls)->toBe(2)
        ->and(ChannelMessageReceipt::query()->where('channel_connection_id', $connection->id)->count())->toBe(2);

    Http::assertSentCount(2);
});

test('Email creates separate conversations for new threads and ignores automated or self messages', function () {
    [$user, , $bot] = emailContext();
    $connection = createEmailConnection($bot, $user);
    app()->instance(AiClient::class, new EmailFakeAiClient);
    Http::fake(['https://api.postmarkapp.com/email' => Http::response(['MessageID' => 'pm-outbound'], 200)]);
    $url = route('channels.email.webhook.receive', $connection->public_id);

    $this->withBasicAuth('postmark', 'webhook-secret')->postJson($url, emailPayload('pm-a', '<a@example.com>'))->assertNoContent();
    $this->withBasicAuth('postmark', 'webhook-secret')->postJson($url, emailPayload('pm-b', '<b@example.com>', 'Another issue'))->assertNoContent();
    $this->withBasicAuth('postmark', 'webhook-secret')->postJson($url, [
        ...emailPayload('pm-auto', '<auto@example.com>'),
        'Headers' => [['Name' => 'Auto-Submitted', 'Value' => 'auto-replied']],
    ])->assertNoContent();
    $this->withBasicAuth('postmark', 'webhook-secret')->postJson($url, [
        ...emailPayload('pm-self', '<self@example.com>'),
        'From' => 'support@example.com',
    ])->assertNoContent();

    expect(Conversation::query()->where('channel_connection_id', $connection->id)->count())->toBe(2)
        ->and(ChannelMessageReceipt::query()->where('channel_connection_id', $connection->id)->count())->toBe(2);
});

test('Email channel configuration keeps team and channel management authorization', function () {
    [$user, $team, $bot] = emailContext();
    $foreignTeam = Team::factory()->create();
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id]);
    Http::fake(['https://api.postmarkapp.com/*' => Http::response(['ID' => 42], 200)]);

    $this->actingAs($user)
        ->post(route('bots.channels.email.store', [$team->slug, $foreignBot]), [])
        ->assertNotFound();

    [$supportAgent, $supportTeam, $supportBot] = emailContext(TeamRole::SupportAgent);

    $this->actingAs($supportAgent)
        ->post(route('bots.channels.email.store', [$supportTeam->slug, $supportBot]), [])
        ->assertForbidden();
});
