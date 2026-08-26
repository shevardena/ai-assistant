<?php

namespace App\Services\Billing;

use App\Exceptions\BillingProviderException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class StripeApiClient
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function post(string $path, array $parameters, ?string $idempotencyKey = null): array
    {
        $request = Http::asForm()
            ->acceptJson()
            ->withBasicAuth((string) config('services.stripe.secret'), '')
            ->timeout((int) config('services.stripe.timeout', 15))
            ->connectTimeout((int) config('services.stripe.connect_timeout', 5));

        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        return $this->decode($request->post($this->url($path), $parameters));
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        $request = Http::acceptJson()
            ->withBasicAuth((string) config('services.stripe.secret'), '')
            ->timeout((int) config('services.stripe.timeout', 15))
            ->connectTimeout((int) config('services.stripe.connect_timeout', 5));

        return $this->decode($request->get($this->url($path)));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.stripe.api_url'), '/').'/'.ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        throw new BillingProviderException(
            errorCode: 'billing_provider_unavailable',
            message: 'The billing provider could not complete the request.',
        );
    }
}
