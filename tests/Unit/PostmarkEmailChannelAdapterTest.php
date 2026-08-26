<?php

use App\Data\ChannelOutboundMessage;
use App\Enums\ConversationChannel;
use App\Models\ChannelConnection;
use App\Models\ChannelCredential;
use App\Services\Channels\Contracts\EmailProviderClient;
use App\Services\Channels\EmailProviderResult;
use App\Services\Channels\PostmarkEmailChannelAdapter;
use Tests\TestCase;

uses(TestCase::class);

final class FakeEmailProviderClient implements EmailProviderClient
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function validate(string $serverToken, string $fromAddress): EmailProviderResult
    {
        return EmailProviderResult::success(providerAccountReference: 'server-1');
    }

    /** @param array<string, mixed> $message */
    public function send(string $serverToken, array $message): EmailProviderResult
    {
        $this->sent[] = $message;

        return EmailProviderResult::success('postmark-message-1');
    }
}

test('Postmark adapter normalizes plain text, threading headers, and bounded attachment metadata', function () {
    $provider = new FakeEmailProviderClient;
    $adapter = new PostmarkEmailChannelAdapter($provider);

    $message = $adapter->receive([
        'MessageID' => 'pm-1',
        'From' => 'Jane <jane@example.com>',
        'OriginalRecipient' => 'support@example.com',
        'Subject' => 'Order issue',
        'TextBody' => "New text\n\nOn Aug 23, John wrote:\n> old text",
        'Headers' => [
            ['Name' => 'Message-ID', 'Value' => '<root@example.com>'],
            ['Name' => 'References', 'Value' => '<root@example.com> <old@example.com>'],
        ],
        'Attachments' => [[
            'Name' => 'order.pdf',
            'ContentType' => 'application/pdf',
            'ContentLength' => 120,
            'Content' => 'not-read',
        ]],
    ]);

    expect($message?->channel)->toBe(ConversationChannel::Email)
        ->and($message?->externalConversationId)->toBe('<root@example.com>')
        ->and($message?->externalUserId)->toBe('jane@example.com')
        ->and($message?->externalMessageId)->toBe('pm-1')
        ->and($message?->text)->toBe('New text')
        ->and($message?->metadata['email_subject'])->toBe('Order issue')
        ->and($message?->metadata['email_references'])->toBe(['<root@example.com>', '<old@example.com>'])
        ->and($message?->attachments)->toMatchArray([['name' => 'order.pdf', 'mime_type' => 'application/pdf', 'size' => 120]]);
});

test('Postmark adapter converts HTML-only and marks automated messages', function () {
    $adapter = new PostmarkEmailChannelAdapter(new FakeEmailProviderClient);

    $message = $adapter->receive([
        'MessageID' => 'pm-2',
        'From' => 'robot@example.com',
        'To' => 'support@example.com',
        'HtmlBody' => '<style>bad</style><p>Hello&nbsp;there</p><script>bad()</script>',
        'Headers' => [
            ['Name' => 'Auto-Submitted', 'Value' => 'auto-replied'],
        ],
    ]);

    expect($message?->text)->toBe('Hello there')
        ->and($message?->metadata['automated'])->toBeTrue();
});

test('Postmark adapter sends plain and HTML bodies with RFC threading headers without exposing credentials', function () {
    $provider = new FakeEmailProviderClient;
    $adapter = new PostmarkEmailChannelAdapter($provider);
    $connection = new ChannelConnection;
    $connection->setRawAttributes([
        'id' => 10,
        'team_id' => 20,
        'channel' => ConversationChannel::Email->value,
        'configuration' => json_encode([
            'from_address' => 'support@example.com',
            'from_name' => 'Support',
            'reply_to_address' => 'reply@example.com',
        ], JSON_THROW_ON_ERROR),
    ]);
    $credential = new ChannelCredential;
    $credential->setRawAttributes([
        'id' => 11,
        'team_id' => 20,
        'channel_connection_id' => 10,
        'provider' => 'postmark',
    ]);
    $credential->encrypted_access_token = 'server-secret';
    $credential->encrypted_verify_token = 'webhook-secret';
    $connection->setRelation('credential', $credential);

    $result = $adapter->send($connection, new ChannelOutboundMessage(
        channel: ConversationChannel::Email,
        text: 'Hello from the assistant.',
        externalUserId: 'jane@example.com',
        metadata: [
            'email_subject' => 'Order issue',
            'email_message_id' => '<assistant-1@example.test>',
            'email_in_reply_to' => '<root@example.com>',
            'email_references' => ['<root@example.com>'],
        ],
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->metadata['external_message_reference'])->toBe('<assistant-1@example.test>')
        ->and($provider->sent[0])->toMatchArray([
            'From' => '"Support" <support@example.com>',
            'To' => 'jane@example.com',
            'Subject' => 'Re: Order issue',
            'TextBody' => 'Hello from the assistant.',
        ])
        ->and($provider->sent[0]['Headers'])->toContain(['Name' => 'Message-ID', 'Value' => '<assistant-1@example.test>'])
        ->and($provider->sent[0]['Headers'])->toContain(['Name' => 'In-Reply-To', 'Value' => '<root@example.com>']);
});
