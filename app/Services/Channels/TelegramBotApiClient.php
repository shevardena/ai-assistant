<?php

namespace App\Services\Channels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class TelegramBotApiClient
{
    public function __construct(
        private readonly TelegramProviderErrorNormalizer $errors,
        private readonly ?string $apiUrl = null,
        private readonly ?int $timeout = null,
        private readonly ?int $connectTimeout = null,
    ) {}

    public function getMe(string $botToken): TelegramApiResult
    {
        try {
            $response = $this->request($botToken)->get($this->url($botToken, 'getMe'));

            if (! $response->successful() || $response->json('ok') !== true) {
                return $this->failure($response);
            }

            $result = $response->json('result');

            if (! is_array($result) || ! is_numeric($result['id'] ?? null) || ! is_string($result['first_name'] ?? null)) {
                return TelegramApiResult::failure('telegram_delivery_failed');
            }

            return TelegramApiResult::success(new TelegramBotProfile(
                id: (int) $result['id'],
                firstName: $result['first_name'],
                lastName: is_string($result['last_name'] ?? null) ? $result['last_name'] : null,
                username: is_string($result['username'] ?? null) ? $result['username'] : null,
            ));
        } catch (ConnectionException) {
            return TelegramApiResult::failure('telegram_timeout');
        } catch (RequestException) {
            return TelegramApiResult::failure('telegram_unavailable');
        }
    }

    public function setWebhook(string $botToken, string $webhookUrl, string $secretToken): TelegramApiResult
    {
        return $this->post($botToken, 'setWebhook', [
            'url' => $webhookUrl,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message'],
        ]);
    }

    public function deleteWebhook(string $botToken): TelegramApiResult
    {
        return $this->post($botToken, 'deleteWebhook');
    }

    public function sendMessage(string $botToken, string $chatId, string $text): TelegramApiResult
    {
        $result = $this->post($botToken, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'link_preview_options' => ['is_disabled' => true],
        ]);

        return $result;
    }

    /** @param array<string, mixed> $payload */
    private function post(string $botToken, string $method, array $payload = []): TelegramApiResult
    {
        try {
            $response = $this->request($botToken)->post($this->url($botToken, $method), $payload);

            if (! $response->successful() || $response->json('ok') !== true) {
                return $this->failure($response);
            }

            $messageId = $response->json('result.message_id');

            return TelegramApiResult::success(
                providerMessageReference: is_scalar($messageId) ? (string) $messageId : null,
            );
        } catch (ConnectionException) {
            return TelegramApiResult::failure('telegram_timeout');
        } catch (RequestException) {
            return TelegramApiResult::failure('telegram_unavailable');
        }
    }

    private function failure(Response $response): TelegramApiResult
    {
        $status = $response->status();
        $payload = $response->json();

        return TelegramApiResult::failure($this->errors->normalize(
            $status,
            is_array($payload) ? $payload : null,
        ));
    }

    private function url(string $botToken, string $method): string
    {
        return rtrim($this->apiUrl ?? (string) config('services.telegram.api_url'), '/').'/bot'.$botToken.'/'.$method;
    }

    private function request(string $botToken): PendingRequest
    {
        return Http::timeout($this->timeout ?? (int) config('services.telegram.timeout', 8))
            ->connectTimeout($this->connectTimeout ?? (int) config('services.telegram.connect_timeout', 3));
    }
}
