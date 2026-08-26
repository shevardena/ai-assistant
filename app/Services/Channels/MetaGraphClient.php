<?php

namespace App\Services\Channels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class MetaGraphClient
{
    public function __construct(
        private readonly MetaProviderErrorNormalizer $errors,
        private readonly ?HttpFactory $httpFactory = null,
        private readonly ?string $graphUrl = null,
        private readonly ?string $graphVersion = null,
        private readonly ?int $timeout = null,
        private readonly ?int $connectTimeout = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $referencePaths
     */
    public function post(
        string $resourceReference,
        string $accessToken,
        array $payload,
        array $referencePaths = [],
    ): MetaGraphResult {
        try {
            $response = $this->request($accessToken)
                ->acceptJson()
                ->timeout($this->timeout ?? (int) config('services.meta.timeout', 8))
                ->connectTimeout($this->connectTimeout ?? (int) config('services.meta.connect_timeout', 3))
                ->post($this->messagesUrl($resourceReference), $payload);

            if (! $response->successful()) {
                $body = $response->json();

                return MetaGraphResult::failure($this->errors->normalize(
                    $response->status(),
                    is_array($body) ? $body : null,
                ));
            }

            $body = $response->json();
            $reference = null;

            if (is_array($body)) {
                foreach ($referencePaths as $path) {
                    $value = data_get($body, $path);

                    if (is_scalar($value) && (string) $value !== '') {
                        $reference = (string) $value;
                        break;
                    }
                }
            }

            return MetaGraphResult::success($reference);
        } catch (ConnectionException) {
            return MetaGraphResult::failure('meta_timeout');
        } catch (RequestException) {
            return MetaGraphResult::failure('meta_unavailable');
        }
    }

    private function messagesUrl(string $resourceReference): string
    {
        return rtrim($this->graphUrl ?? (string) config('services.meta.graph_url'), '/')
            .'/'.trim($this->graphVersion ?? (string) config('services.meta.graph_version'), '/')
            .'/'.rawurlencode($resourceReference).'/messages';
    }

    private function request(string $accessToken): PendingRequest
    {
        return $this->httpFactory?->withToken($accessToken) ?? Http::withToken($accessToken);
    }
}
