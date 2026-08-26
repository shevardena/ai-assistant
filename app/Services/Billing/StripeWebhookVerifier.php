<?php

namespace App\Services\Billing;

use App\Exceptions\BillingProviderException;
use JsonException;

final class StripeWebhookVerifier
{
    /** @return array<string, mixed> */
    public function verify(string $payload, ?string $signature): array
    {
        $secret = config('services.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '' || ! is_string($signature) || $signature === '') {
            throw new BillingProviderException('billing_invalid_webhook_signature', 'The Stripe webhook signature is invalid.');
        }

        $parts = collect(explode(',', $signature))->mapWithKeys(function (string $part): array {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);

            return is_string($key) && is_string($value) ? [$key => $value] : [];
        });
        $timestamp = $parts->get('t');
        $expected = $parts->get('v1');

        if (! is_string($timestamp) || ! ctype_digit($timestamp) || ! is_string($expected)) {
            throw new BillingProviderException('billing_invalid_webhook_signature', 'The Stripe webhook signature is invalid.');
        }

        if (abs(time() - (int) $timestamp) > (int) config('services.stripe.webhook_tolerance', 300)) {
            throw new BillingProviderException('billing_invalid_webhook_signature', 'The Stripe webhook signature has expired.');
        }

        $signedPayload = $timestamp.'.'.$payload;
        $actual = hash_hmac('sha256', $signedPayload, $secret);

        if (! hash_equals($actual, $expected)) {
            throw new BillingProviderException('billing_invalid_webhook_signature', 'The Stripe webhook signature is invalid.');
        }

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BillingProviderException('billing_invalid_webhook_payload', 'The Stripe webhook payload is invalid.', $exception);
        }

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            throw new BillingProviderException('billing_invalid_webhook_payload', 'The Stripe webhook payload is invalid.');
        }

        return $event;
    }
}
