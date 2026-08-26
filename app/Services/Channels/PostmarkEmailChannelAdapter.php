<?php

namespace App\Services\Channels;

use App\Data\ChannelInboundMessage;
use App\Data\ChannelOutboundMessage;
use App\Enums\ConversationChannel;
use App\Models\ChannelConnection;
use App\Services\Channels\Contracts\ChannelAdapter;
use App\Services\Channels\Contracts\EmailProviderClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PostmarkEmailChannelAdapter implements ChannelAdapter
{
    private const MAX_TEXT_LENGTH = 12000;

    public function __construct(private readonly EmailProviderClient $provider) {}

    public function validateConnection(string $serverToken, string $fromAddress): EmailProviderResult
    {
        return $this->provider->validate($serverToken, $fromAddress);
    }

    /** @param array<string, mixed> $payload */
    public function receive(array $payload): ?ChannelInboundMessage
    {
        $providerMessageId = $this->stringValue($payload['MessageID'] ?? null);
        $from = $this->addressValue(data_get($payload, 'FromFull.Email'))
            ?? $this->addressValue($payload['From'] ?? null);

        if ($providerMessageId === null || $from === null) {
            return null;
        }

        $headers = $this->headers($payload['Headers'] ?? null);
        $messageId = $headers['message-id'] ?? $this->stringValue($payload['MessageID'] ?? null);
        $inReplyTo = $headers['in-reply-to'] ?? null;
        $references = $this->references($headers['references'] ?? null);
        $threadReference = $references[0] ?? $inReplyTo ?? $messageId ?? $providerMessageId;
        $subject = $this->stringValue($payload['Subject'] ?? null);
        $text = $this->stringValue($payload['StrippedTextReply'] ?? null)
            ?? $this->stringValue($payload['TextBody'] ?? null);

        if ($text === null || trim($text) === '') {
            $html = $this->stringValue($payload['HtmlBody'] ?? null);
            $text = $html === null ? null : $this->htmlToText($html);
        }

        $text = $text === null ? '' : $this->stripQuotedReply($text);
        $attachments = $this->attachmentMetadata($payload['Attachments'] ?? null);
        $attachmentOnly = trim($text) === '' && $attachments !== [];

        if (trim($text) === '' && ! $attachmentOnly) {
            return null;
        }

        return new ChannelInboundMessage(
            channel: ConversationChannel::Email,
            externalConversationId: $threadReference,
            externalUserId: $from,
            text: $attachmentOnly
                ? 'This assistant cannot process email attachments yet. Please resend your question as text.'
                : Str::limit(trim($text), self::MAX_TEXT_LENGTH, ''),
            attachments: $attachments,
            metadata: [
                'provider' => 'postmark',
                'email_subject' => $subject !== null ? Str::limit($subject, 200, '') : null,
                'email_message_id' => $messageId,
                'email_in_reply_to' => $inReplyTo,
                'email_references' => $references,
                'attachment_count' => count($attachments),
                'attachment_only' => $attachmentOnly,
                'automated' => $this->isAutomated($headers),
            ],
            externalMessageId: $providerMessageId,
        );
    }

    public function send(ChannelConnection $connection, ChannelOutboundMessage $message): ChannelDeliveryResult
    {
        $credential = $connection->credential;
        $to = $message->externalUserId;
        $from = $this->stringValue(data_get($connection->configuration, 'from_address'));
        $customMessageId = $this->stringValue($message->metadata['email_message_id'])
            ?? $this->generatedMessageId($connection, $message);

        if ($connection->channel !== ConversationChannel::Email
            || $credential === null
            || $credential->provider !== 'postmark'
            || (int) $credential->team_id !== (int) $connection->team_id
            || (int) $credential->channel_connection_id !== (int) $connection->id
            || $credential->encrypted_access_token === ''
            || $from === null
            || $to === null
            || filter_var($from, FILTER_VALIDATE_EMAIL) === false
            || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ChannelDeliveryResult::failure('email_provider_unavailable');
        }

        $headers = [
            ['Name' => 'Message-ID', 'Value' => $customMessageId],
        ];
        $inReplyTo = $this->stringValue($message->metadata['email_in_reply_to'] ?? null);
        $references = $this->references($message->metadata['email_references'] ?? null);
        $subject = $this->subject(
            $message->metadata['email_subject'] ?? null,
            $inReplyTo !== null || $references !== [],
        );

        if ($inReplyTo !== null) {
            $headers[] = ['Name' => 'In-Reply-To', 'Value' => $inReplyTo];
        }

        if ($references !== []) {
            $headers[] = ['Name' => 'References', 'Value' => implode(' ', array_slice($references, 0, 20))];
        }

        $fromName = $this->stringValue(data_get($connection->configuration, 'from_name'));
        $fromValue = $fromName === null ? $from : $this->formatAddress($fromName, $from);
        $replyTo = $this->stringValue(data_get($connection->configuration, 'reply_to_address'));
        $body = trim($message->text);

        $payload = [
            'From' => $fromValue,
            'To' => $to,
            'Subject' => $subject,
            'TextBody' => $body,
            'HtmlBody' => '<p>'.nl2br(e($body), false).'</p>',
            'Headers' => $headers,
        ];

        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) {
            $payload['ReplyTo'] = $replyTo;
        }

        $result = $this->provider->send($credential->encrypted_access_token, $payload);

        if (! $result->successful) {
            return ChannelDeliveryResult::failure($result->errorCode ?? 'email_delivery_failed');
        }

        return ChannelDeliveryResult::success(
            providerMessageReference: $result->providerMessageReference,
            metadata: [
                'external_message_reference' => $customMessageId,
                'email_message_id' => $customMessageId,
            ],
        );
    }

    public function validWebhook(Request $request, ChannelConnection $connection): bool
    {
        $secret = $connection->credential?->encrypted_verify_token;

        return $connection->channel === ConversationChannel::Email
            && $connection->credential?->provider === 'postmark'
            && is_string($secret)
            && $secret !== ''
            && hash_equals((string) config('services.postmark.email.webhook_username', 'postmark'), (string) $request->getUser())
            && hash_equals($secret, (string) $request->getPassword());
    }

    public function configuredInboundAddress(ChannelConnection $connection): ?string
    {
        $address = $this->stringValue(data_get($connection->configuration, 'inbound_address'));

        return $address !== null && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
            ? strtolower($address)
            : null;
    }

    /** @param array<string, mixed> $payload */
    public function hasConfiguredRecipient(array $payload, string $inboundAddress): bool
    {
        $values = [
            $payload['OriginalRecipient'] ?? null,
            $payload['To'] ?? null,
            data_get($payload, 'ToFull.Email'),
        ];

        foreach ($values as $value) {
            if ($this->addressValue($value) === strtolower($inboundAddress)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $headers */
    private function isAutomated(array $headers): bool
    {
        return ($headers['auto-submitted'] ?? '') !== ''
            || in_array(strtolower($headers['precedence'] ?? ''), ['bulk', 'junk', 'list'], true)
            || ($headers['x-autoreply'] ?? '') !== ''
            || ($headers['x-autorespond'] ?? '') !== '';
    }

    private function addressValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/<([^>]+)>/', $value, $matches) === 1) {
            $value = $matches[1];
        }

        $address = strtolower(trim($value));

        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }

    /**
     * @return array<string, string>
     */
    private function headers(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $headers = [];

        foreach ($value as $header) {
            if (! is_array($header)) {
                continue;
            }

            $name = strtolower(trim((string) ($header['Name'] ?? $header['name'] ?? '')));
            $headerValue = trim((string) ($header['Value'] ?? $header['value'] ?? ''));

            if ($name !== '' && $headerValue !== '' && in_array($name, [
                'message-id', 'in-reply-to', 'references', 'auto-submitted', 'precedence', 'x-autoreply', 'x-autorespond',
            ], true)) {
                $headers[$name] = Str::limit($headerValue, 2000, '');
            }
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    private function references(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn (mixed $reference): ?string => $this->stringValue($reference),
                $value,
            )));
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $tokens = preg_split('/\s+/', trim($value)) ?: [];

        return array_map(
            static fn (mixed $reference): string => Str::limit(trim((string) $reference), 255, ''),
            $tokens,
        );
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<\s*(script|style)\b[^>]*>.*?<\/\s*\1\s*>/is', '', $html) ?? '';

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function stripQuotedReply(string $text): string
    {
        $lines = preg_split('/\R/', trim($text)) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^On .+ wrote:$/i', $trimmed) === 1
                || preg_match('/^-{3,}\s*Original Message\s*-{3,}$/i', $trimmed) === 1
                || str_starts_with($trimmed, '>')) {
                break;
            }

            $kept[] = $line;
        }

        $result = trim(implode("\n", $kept));

        return $result !== '' ? $result : trim($text);
    }

    /**
     * @return list<array{name: string, mime_type: string, size: int|null}>
     */
    private function attachmentMetadata(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $attachments = [];

        foreach ($value as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $attachments[] = [
                'name' => Str::limit((string) ($attachment['Name'] ?? ''), 255, ''),
                'mime_type' => Str::limit((string) ($attachment['ContentType'] ?? 'application/octet-stream'), 120, ''),
                'size' => is_numeric($attachment['ContentLength'] ?? null) ? (int) $attachment['ContentLength'] : null,
            ];
        }

        return $attachments;
    }

    private function subject(mixed $value, bool $reply): string
    {
        $subject = is_string($value) && trim($value) !== '' ? trim($value) : 'Support request';

        $subject = trim(preg_replace('/^(re:\s*)+/i', '', $subject) ?? $subject);

        return $reply ? 'Re: '.$subject : $subject;
    }

    private function formatAddress(string $name, string $address): string
    {
        return '"'.addcslashes(Str::limit($name, 120, ''), '"\\').'" <'.$address.'>';
    }

    private function generatedMessageId(ChannelConnection $connection, ChannelOutboundMessage $message): string
    {
        $host = parse_url((string) config('app.url', 'https://example.test'), PHP_URL_HOST) ?: 'example.test';

        return '<assistant-'.sha1($connection->id.'|'.$message->text.'|'.microtime(true)).'@'.$host.'>';
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
