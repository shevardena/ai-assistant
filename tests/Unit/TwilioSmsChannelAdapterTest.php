<?php

use App\Data\ChannelOutboundMessage;
use App\Enums\ConversationChannel;
use App\Models\ChannelConnection;
use App\Models\ChannelCredential;
use App\Services\Channels\Contracts\SmsProviderClient;
use App\Services\Channels\SmsProviderErrorNormalizer;
use App\Services\Channels\SmsProviderResult;
use App\Services\Channels\TwilioSignatureValidator;
use App\Services\Channels\TwilioSmsChannelAdapter;
use App\Services\Channels\TwilioSmsClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

final class FakeSmsProviderClient implements SmsProviderClient
{
    /** @var list<array<string, string>> */
    public array $sent = [];

    public function validate(string $accountSid, string $authToken, string $phoneNumber): SmsProviderResult
    {
        return SmsProviderResult::success(
            providerChannelReference: 'PN123',
            displayName: 'Support SMS',
        );
    }

    public function send(
        string $accountSid,
        string $authToken,
        string $from,
        string $to,
        string $body,
    ): SmsProviderResult {
        $this->sent[] = compact('accountSid', 'authToken', 'from', 'to', 'body');

        return SmsProviderResult::success('SM123');
    }
}

test('Twilio adapter normalizes inbound SMS and ignores media-only messages', function () {
    $provider = new FakeSmsProviderClient;
    $adapter = new TwilioSmsChannelAdapter($provider, new TwilioSignatureValidator);

    $message = $adapter->receive([
        'MessageSid' => 'SM-inbound-1',
        'From' => '+15550001111',
        'To' => '+15550002222',
        'Body' => ' Hello SMS ',
        'NumMedia' => '1',
    ]);

    expect($message?->channel)->toBe(ConversationChannel::Sms)
        ->and($message?->externalConversationId)->toBe('+15550001111')
        ->and($message?->externalUserId)->toBe('+15550001111')
        ->and($message?->externalMessageId)->toBe('SM-inbound-1')
        ->and($message?->text)->toBe('Hello SMS')
        ->and($message?->metadata['media_count'])->toBe(1)
        ->and($adapter->receive([
            'MessageSid' => 'SM-media-only',
            'From' => '+15550001111',
            'To' => '+15550002222',
            'Body' => '',
            'NumMedia' => '1',
        ]))->toBeNull();
});

test('Twilio adapter sends bounded SMS messages with encrypted credential values', function () {
    $provider = new FakeSmsProviderClient;
    $adapter = new TwilioSmsChannelAdapter($provider, new TwilioSignatureValidator);
    $connection = new ChannelConnection;
    $connection->setRawAttributes([
        'id' => 10,
        'team_id' => 20,
        'channel' => ConversationChannel::Sms->value,
        'configuration' => json_encode(['phone_number' => '+15550002222'], JSON_THROW_ON_ERROR),
    ]);
    $credential = new ChannelCredential;
    $credential->setRawAttributes([
        'id' => 11,
        'team_id' => 20,
        'channel_connection_id' => 10,
        'provider' => 'twilio',
    ]);
    $credential->encrypted_access_token = 'auth-token';
    $credential->encrypted_verify_token = 'AC123';
    $connection->setRelation('credential', $credential);

    $result = $adapter->send($connection, new ChannelOutboundMessage(
        channel: ConversationChannel::Sms,
        text: str_repeat('A ', 3000),
        externalUserId: '+15550001111',
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->providerMessageReference)->toBe('SM123')
        ->and($provider->sent)->toHaveCount(3)
        ->and($provider->sent)->each->toHaveKey('body')
        ->and(array_reduce(
            $provider->sent,
            static fn (bool $valid, array $sent): bool => $valid && mb_strlen($sent['body']) <= 1500,
            true,
        ))->toBeTrue()
        ->and($provider->sent[0]['accountSid'])->toBe('AC123')
        ->and($provider->sent[0]['authToken'])->toBe('auth-token');
});

test('Twilio signatures include the exact URL and sorted form parameters', function () {
    $validator = new TwilioSignatureValidator;
    $request = Request::create('/api/channels/sms/twilio/connection/webhook', 'POST', [
        'Body' => 'Hello',
        'MessageSid' => 'SM123',
    ], server: ['HTTP_HOST' => 'example.test']);
    $data = $request->fullUrl().'BodyHelloMessageSidSM123';
    $signature = base64_encode(hash_hmac('sha1', $data, 'auth-token', true));
    $request->headers->set('X-Twilio-Signature', $signature);

    expect($validator->valid($request, 'auth-token'))->toBeTrue();

    $request->request->set('Body', 'Changed');
    expect($validator->valid($request, 'auth-token'))->toBeFalse();
});

test('Twilio client uses the validation and messaging endpoints without leaking credentials', function () {
    Http::fake([
        'https://api.twilio.com/2010-04-01/Accounts/AC123/IncomingPhoneNumbers.json*' => Http::response([
            'incoming_phone_numbers' => [[
                'sid' => 'PN123',
                'phone_number' => '+15550002222',
                'friendly_name' => 'Support SMS',
            ]],
        ]),
        'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json' => Http::response([
            'sid' => 'SM123',
            'status' => 'queued',
        ]),
    ]);
    $client = new TwilioSmsClient(new SmsProviderErrorNormalizer);

    expect($client->validate('AC123', 'auth-token', '+15550002222')->successful)->toBeTrue()
        ->and($client->send('AC123', 'auth-token', '+15550002222', '+15550001111', 'Hello')->providerMessageReference)->toBe('SM123');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
        && $request['To'] === '+15550001111'
        && $request['From'] === '+15550002222'
        && $request['Body'] === 'Hello'
        && ! str_contains((string) $request->body(), 'auth-token'));
});
