<?php

namespace App\Services\Channels;

use App\Data\ChannelInboundMessage;
use App\Data\ChannelOutboundMessage;
use App\Enums\ConversationChannel;
use App\Models\ChannelConnection;
use App\Services\Channels\Contracts\ChannelAdapter;
use Illuminate\Support\Str;

final class TelegramChannelAdapter implements ChannelAdapter
{
    private const MAX_TEXT_LENGTH = 4096;

    public function __construct(private readonly TelegramBotApiClient $api) {}

    public function validateBot(string $botToken): TelegramApiResult
    {
        return $this->api->getMe($botToken);
    }

    public function registerWebhook(string $botToken, string $webhookUrl, string $secretToken): TelegramApiResult
    {
        return $this->api->setWebhook($botToken, $webhookUrl, $secretToken);
    }

    public function deleteWebhook(string $botToken): TelegramApiResult
    {
        return $this->api->deleteWebhook($botToken);
    }

    /** @param array<string, mixed> $payload */
    public function receive(array $payload): ?ChannelInboundMessage
    {
        $message = $payload['message'] ?? null;

        if (! is_array($message) || data_get($message, 'chat.type') !== 'private') {
            return null;
        }

        $updateId = $this->stringValue($payload['update_id'] ?? null);
        $messageId = $this->stringValue($message['message_id'] ?? null);
        $chatId = $this->stringValue(data_get($message, 'chat.id'));
        $userId = $this->stringValue(data_get($message, 'from.id')) ?? $chatId;
        $text = $this->stringValue($message['text'] ?? null);

        if ($chatId === null || $userId === null || $text === null || trim($text) === '') {
            return null;
        }

        return new ChannelInboundMessage(
            channel: ConversationChannel::Telegram,
            externalConversationId: $chatId,
            externalUserId: $userId,
            text: Str::limit(trim($text), self::MAX_TEXT_LENGTH, ''),
            metadata: [
                'chat_type' => 'private',
                'display_name' => $this->displayName($message),
                'provider_timestamp' => $this->stringValue($message['date'] ?? null),
            ],
            externalMessageId: $updateId ?? $messageId,
        );
    }

    public function send(ChannelConnection $connection, ChannelOutboundMessage $message): ChannelDeliveryResult
    {
        $credential = $connection->credential;
        $chatId = $message->externalUserId;

        if ($connection->channel !== ConversationChannel::Telegram
            || $credential === null
            || $credential->provider !== 'telegram'
            || (int) $credential->team_id !== (int) $connection->team_id
            || (int) $credential->channel_connection_id !== (int) $connection->id
            || $credential->encrypted_access_token === ''
            || $chatId === null || $chatId === '') {
            return ChannelDeliveryResult::failure('telegram_unavailable');
        }

        $lastReference = null;

        foreach ($this->splitText($message->text) as $text) {
            $result = $this->api->sendMessage($credential->encrypted_access_token, $chatId, $text);

            if (! $result->successful) {
                return ChannelDeliveryResult::failure($result->errorCode ?? 'telegram_delivery_failed');
            }

            $lastReference = $result->providerMessageReference ?? $lastReference;
        }

        return ChannelDeliveryResult::success($lastReference);
    }

    /** @return list<string> */
    private function splitText(string $text): array
    {
        $text = trim($text);

        if (Str::length($text) <= self::MAX_TEXT_LENGTH) {
            return [$text];
        }

        $parts = [];

        while (Str::length($text) > self::MAX_TEXT_LENGTH) {
            $part = Str::substr($text, 0, self::MAX_TEXT_LENGTH);
            $breakAt = mb_strrpos($part, ' ');

            if ($breakAt === false || $breakAt < (self::MAX_TEXT_LENGTH - 500)) {
                $breakAt = self::MAX_TEXT_LENGTH;
            }

            $parts[] = trim(Str::substr($text, 0, $breakAt));
            $text = ltrim(Str::substr($text, $breakAt));
        }

        if ($text !== '') {
            $parts[] = $text;
        }

        return $parts;
    }

    /** @param array<string, mixed> $message */
    private function displayName(array $message): ?string
    {
        $first = $this->stringValue(data_get($message, 'from.first_name'));
        $last = $this->stringValue(data_get($message, 'from.last_name'));
        $username = $this->stringValue(data_get($message, 'from.username'));
        $name = trim(($first ?? '').' '.($last ?? ''));

        return $name !== '' ? $name : $username;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
