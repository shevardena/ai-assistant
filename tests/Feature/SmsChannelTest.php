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
use App\Models\TeamSubscription;
use App\Models\User;
use App\Services\Ai\Contracts\AiClient;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

final class SmsFakeAiClient implements AiClient
{
    public int $calls = 0;

    public function createResponse(array $payload): array
    {
        $this->calls++;

        return ['output' => [], 'output_text' => 'Hello from SMS.', 'usage' => null];
    }
}

/** @return array{0: User, 1: Team, 2: Bot, 3: ChannelConnection, 4: string} */
function smsContext(
    TeamRole $role = TeamRole::Developer,
    string $phoneNumber = '+15550002222',
    string $providerChannelReference = 'PN123',
): array {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $connection = ChannelConnection::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'channel' => ConversationChannel::Sms->value,
        'name' => 'SMS',
        'status' => ChannelConnectionStatus::Active->value,
        'provider_channel_reference' => $providerChannelReference,
        'configuration' => [
            'provider' => 'twilio',
            'phone_number' => $phoneNumber,
            'display_name' => 'Support SMS',
        ],
    ]);
    $authToken = 'auth-token-123';

    ChannelCredential::query()->create([
        'team_id' => $team->id,
        'channel_connection_id' => $connection->id,
        'created_by_user_id' => $user->id,
        'provider' => 'twilio',
        'encrypted_access_token' => $authToken,
        'encrypted_verify_token' => 'AC123',
        'encrypted_app_secret' => null,
        'verify_token_hash' => hash('sha256', 'AC123'),
        'access_token_last_four' => 'oken',
    ]);

    return [$user, $team, $bot, $connection, $authToken];
}

test('authorized users configure Twilio SMS with validated encrypted credentials', function () {
    [$user, $team, $bot] = smsContext();
    $bot->channelConnections()->delete();
    fakeSmsValidation();

    $this->actingAs($user)
        ->post(route('bots.channels.sms.store', [$team->slug, $bot]), [
            'phone_number' => '+15550002222',
            'account_sid' => 'AC123',
            'auth_token' => 'auth-token-123',
            'display_name' => 'Support SMS',
        ])
        ->assertRedirect();

    $connection = $bot->channelConnections()->where('channel', ConversationChannel::Sms->value)->firstOrFail();
    $credential = $connection->credential()->firstOrFail();

    expect($connection->status)->toBe(ChannelConnectionStatus::Active)
        ->and($connection->provider_channel_reference)->toBe('PN123')
        ->and($connection->configuration)->toMatchArray([
            'provider' => 'twilio',
            'phone_number' => '+15550002222',
            'display_name' => 'Support SMS',
        ])
        ->and($connection->configuration)->not->toHaveKey('account_sid')
        ->and($connection->configuration)->not->toHaveKey('auth_token')
        ->and($credential->encrypted_access_token)->toBe('auth-token-123')
        ->and($credential->encrypted_verify_token)->toBe('AC123')
        ->and($credential->getRawOriginal('encrypted_access_token'))->not->toBe('auth-token-123')
        ->and($credential->getRawOriginal('encrypted_verify_token'))->not->toBe('AC123');

    $this->actingAs($user)
        ->get(route('bots.channels.index', [$team->slug, $bot]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.5.implemented', true)
            ->where('channels.5.connection.phoneNumber', '+15550002222')
            ->where('channels.5.connection.tokenConfigured', true)
            ->where('channels.5.connection.tokenLastFour', '-123')
            ->missing('channels.5.connection.authToken')
            ->missing('channels.5.connection.accountSid'));
});

test('invalid Twilio credentials do not activate SMS', function () {
    [$user, $team, $bot] = smsContext();
    $bot->channelConnections()->delete();
    Http::fake([
        'https://api.twilio.com/*' => Http::response(['code' => 20003, 'message' => 'private provider detail'], 401),
    ]);

    $this->actingAs($user)
        ->post(route('bots.channels.sms.store', [$team->slug, $bot]), [
            'phone_number' => '+15550002222',
            'account_sid' => 'AC123',
            'auth_token' => 'bad-token',
        ])
        ->assertSessionHasErrors('account_sid');

    expect($bot->channelConnections()->where('channel', ConversationChannel::Sms->value)->exists())->toBeFalse();
});

test('foreign teams and unsupported roles cannot configure SMS', function () {
    [$user, $team, , $connection] = smsContext();
    $foreignTeam = Team::factory()->create();
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id]);

    $this->actingAs($user)
        ->post(route('bots.channels.sms.store', [$team->slug, $foreignBot]), [])
        ->assertNotFound();

    [$supportAgent, $supportTeam, $supportBot] = smsContext(
        TeamRole::SupportAgent,
        '+15550003333',
        'PN456',
    );

    $this->actingAs($supportAgent)
        ->post(route('bots.channels.sms.store', [$supportTeam->slug, $supportBot]), [])
        ->assertForbidden();

    expect($connection->team_id)->toBe($team->id);
});

test('valid Twilio SMS webhooks are signed, deduplicated, and use the unified runtime', function () {
    [$user, , $bot, $connection, $authToken] = smsContext();
    $fake = new SmsFakeAiClient;
    app()->instance(AiClient::class, $fake);
    Http::fake([
        'https://api.twilio.com/*/Messages.json' => Http::response([
            'sid' => 'SM-outbound-1',
            'status' => 'queued',
        ]),
    ]);
    $payload = [
        'MessageSid' => 'SM-inbound-1',
        'From' => '+15550001111',
        'To' => '+15550002222',
        'Body' => 'Hello',
        'NumMedia' => '0',
    ];
    $url = route('channels.sms.twilio.webhook.receive', $connection->public_id);
    $headers = ['X-Twilio-Signature' => smsSignature($url, $payload, $authToken)];

    $this->actingAs($user)
        ->post($url, $payload, $headers)
        ->assertOk()
        ->assertSee('<Response></Response>', false);
    $this->actingAs($user)
        ->post($url, $payload, $headers)
        ->assertOk();

    $conversation = Conversation::query()->where('channel_connection_id', $connection->id)->firstOrFail();

    expect($conversation->channel)->toBe(ConversationChannel::Sms)
        ->and($conversation->external_user_reference)->toBe('+15550001111')
        ->and($conversation->messages()->where('role', 'user')->count())->toBe(1)
        ->and($conversation->messages()->where('role', 'assistant')->count())->toBe(1)
        ->and($fake->calls)->toBe(1)
        ->and(ChannelMessageReceipt::query()->where('external_message_reference', 'SM-inbound-1')->count())->toBe(1)
        ->and(Message::query()->where('external_message_reference', 'SM-inbound-1')->count())->toBe(1);

    Http::assertSent(fn ($request): bool => $request['To'] === '+15550001111'
        && $request['From'] === '+15550002222'
        && $request['Body'] === 'Hello from SMS.');
    Http::assertSentCount(1);
});

test('missing or invalid Twilio signatures do not create SMS conversations', function () {
    [$user, , , $connection] = smsContext();
    $payload = [
        'MessageSid' => 'SM-inbound-2',
        'From' => '+15550001111',
        'To' => '+15550002222',
        'Body' => 'Hello',
    ];
    $url = route('channels.sms.twilio.webhook.receive', $connection->public_id);

    $this->actingAs($user)->post($url, $payload)->assertForbidden();
    $this->actingAs($user)->post($url, $payload, ['X-Twilio-Signature' => 'invalid'])->assertForbidden();

    expect(Conversation::query()->where('channel_connection_id', $connection->id)->exists())->toBeFalse();
});

test('SMS webhooks reject a different configured To number and reuse the sender conversation', function () {
    [$user, , , $connection, $authToken] = smsContext();
    $fake = new SmsFakeAiClient;
    app()->instance(AiClient::class, $fake);
    Http::fake([
        'https://api.twilio.com/*/Messages.json' => Http::response(['sid' => 'SM-outbound-2']),
    ]);
    $url = route('channels.sms.twilio.webhook.receive', $connection->public_id);
    $wrongNumber = [
        'MessageSid' => 'SM-wrong-number',
        'From' => '+15550001111',
        'To' => '+15550009999',
        'Body' => 'Wrong number',
    ];

    $this->actingAs($user)
        ->post($url, $wrongNumber, ['X-Twilio-Signature' => smsSignature($url, $wrongNumber, $authToken)])
        ->assertOk();

    foreach ([
        ['MessageSid' => 'SM-inbound-3', 'Body' => 'First'],
        ['MessageSid' => 'SM-inbound-4', 'Body' => 'Second'],
    ] as $message) {
        $payload = [...$message, 'From' => '+15550001111', 'To' => '+15550002222'];
        $this->actingAs($user)
            ->post($url, $payload, ['X-Twilio-Signature' => smsSignature($url, $payload, $authToken)])
            ->assertOk();
    }

    expect(Conversation::query()->where('channel_connection_id', $connection->id)->count())->toBe(1)
        ->and($fake->calls)->toBe(2)
        ->and(ChannelMessageReceipt::query()->where('external_message_reference', 'SM-wrong-number')->exists())->toBeFalse();
});

test('SMS hard conversation quota sends a generic response without creating a new conversation', function () {
    [$user, $team, $bot, $connection, $authToken] = smsContext();
    TeamSubscription::factory()->create(['team_id' => $team->id, 'plan_key' => 'free']);
    Conversation::factory()->count(250)->create([
        'bot_id' => $bot->id,
        'channel_connection_id' => $connection->id,
        'channel' => ConversationChannel::Sms->value,
        'metadata' => ['source' => 'customer'],
    ]);
    Http::fake([
        'https://api.twilio.com/*/Messages.json' => Http::response(['sid' => 'SM-quota-fallback']),
    ]);
    $payload = [
        'MessageSid' => 'SM-quota-inbound',
        'From' => '+15550003333',
        'To' => '+15550002222',
        'Body' => 'Start a new chat',
    ];
    $url = route('channels.sms.twilio.webhook.receive', $connection->public_id);

    $this->actingAs($user)
        ->post($url, $payload, ['X-Twilio-Signature' => smsSignature($url, $payload, $authToken)])
        ->assertOk();

    expect(Conversation::query()->where('channel_connection_id', $connection->id)->count())->toBe(250);
    Http::assertSent(fn ($request): bool => $request['Body'] === 'This assistant is temporarily unavailable. Please try again later.');
});

function fakeSmsValidation(): void
{
    Http::fake([
        'https://api.twilio.com/2010-04-01/Accounts/AC123/IncomingPhoneNumbers.json*' => Http::response([
            'incoming_phone_numbers' => [[
                'sid' => 'PN123',
                'phone_number' => '+15550002222',
                'friendly_name' => 'Support SMS',
            ]],
        ]),
    ]);
}

/** @param array<string, string> $payload */
function smsSignature(string $url, array $payload, string $authToken): string
{
    ksort($payload);
    $data = $url;

    foreach ($payload as $key => $value) {
        $data .= $key.$value;
    }

    return base64_encode(hash_hmac('sha1', $data, $authToken, true));
}
