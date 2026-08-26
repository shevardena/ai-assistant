<?php

namespace App\Services\Channels;

use App\Services\Channels\Contracts\EmailProviderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class PostmarkEmailClient implements EmailProviderClient
{
    public function __construct(private readonly EmailProviderErrorNormalizer $errors) {}

    public function validate(string $serverToken, string $fromAddress): EmailProviderResult
    {
        try {
            $response = $this->request($serverToken)->get('/server');

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $serverId = $response->json('ID');

            return EmailProviderResult::success(
                senderVerified: false,
                providerAccountReference: is_scalar($serverId) ? (string) $serverId : null,
            );
        } catch (ConnectionException) {
            return EmailProviderResult::failure('email_timeout');
        }
    }

    /** @param array<string, mixed> $message */
    public function send(string $serverToken, array $message): EmailProviderResult
    {
        try {
            $response = $this->request($serverToken)->post('/email', $message);

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $reference = $response->json('MessageID');

            return EmailProviderResult::success(
                providerMessageReference: is_scalar($reference) ? (string) $reference : null,
            );
        } catch (ConnectionException) {
            return EmailProviderResult::failure('email_timeout');
        }
    }

    private function request(string $serverToken): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.postmark.email.api_url', 'https://api.postmarkapp.com'), '/'))
            ->withHeaders([
                'X-Postmark-Server-Token' => $serverToken,
            ])
            ->acceptJson()
            ->timeout((int) config('services.postmark.email.timeout', 8))
            ->connectTimeout((int) config('services.postmark.email.connect_timeout', 3));
    }

    private function failure(Response $response): EmailProviderResult
    {
        $payload = $response->json();

        return EmailProviderResult::failure($this->errors->normalize(
            $response->status(),
            is_array($payload) ? $payload : null,
        ));
    }
}
