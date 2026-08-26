<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenAiResponsesClient implements AiClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{output: list<array<string, mixed>>, output_text: string|null, usage: array<string, mixed>|null}
     */
    public function createResponse(array $payload): array
    {
        $apiKey = config('openai.api_key');
        $model = config('openai.model');

        if (! is_string($apiKey) || trim($apiKey) === '' || ! is_string($model) || trim($model) === '') {
            throw new AiException('OpenAI is not configured. Set OPENAI_API_KEY and OPENAI_MODEL.');
        }

        try {
            $response = $this->request($apiKey)->post('/responses', [
                ...$payload,
                'model' => $model,
                'store' => false,
            ]);
        } catch (ConnectionException $exception) {
            throw new AiException('OpenAI could not be reached.', previous: $exception);
        } catch (Throwable $exception) {
            throw new AiException('OpenAI request failed.', previous: $exception);
        }

        if ($response->unauthorized() || $response->forbidden()) {
            throw new AiException('OpenAI authentication failed.');
        }

        if ($response->tooManyRequests()) {
            throw new AiException('OpenAI rate limit reached.');
        }

        if ($response->serverError()) {
            throw new AiException('OpenAI is temporarily unavailable.');
        }

        if (! $response->successful()) {
            throw new AiException('OpenAI rejected the request.');
        }

        $body = $response->json();

        if (! is_array($body) || ! is_array($body['output'] ?? null)) {
            throw new AiException('OpenAI returned an unexpected response.');
        }

        /** @var list<array<string, mixed>> $output */
        $output = array_values(array_filter(
            $body['output'],
            static fn (mixed $item): bool => is_array($item),
        ));

        return [
            'output' => $output,
            'output_text' => is_string($body['output_text'] ?? null) ? $body['output_text'] : null,
            'usage' => is_array($body['usage'] ?? null) ? $body['usage'] : null,
        ];
    }

    private function request(string $apiKey): PendingRequest
    {
        return Http::withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('openai.timeout', 30))
            ->connectTimeout(min((int) config('openai.timeout', 30), 10))
            ->retry(
                3,
                static fn (int $attempt, mixed $exception): int => $attempt === 1 ? 100 : 500,
                static fn (Throwable $exception, PendingRequest $request, ?string $message): bool => $exception instanceof ConnectionException,
            )
            ->baseUrl('https://api.openai.com/v1');
    }
}
