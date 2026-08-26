<?php

namespace App\Data;

use App\Exceptions\BillingProviderException;

readonly class StripeSubscriptionSnapshot
{
    public function __construct(
        public string $id,
        public string $customerId,
        public string $priceId,
        public ?string $itemId,
        public string $status,
        public ?int $currentPeriodStart,
        public ?int $currentPeriodEnd,
        public bool $cancelAtPeriodEnd,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $id = $payload['id'] ?? null;
        $customer = $payload['customer'] ?? null;
        $status = $payload['status'] ?? null;
        $items = is_array($payload['items'] ?? null) ? $payload['items']['data'] ?? [] : [];
        $item = is_array($items) && is_array($items[0] ?? null) ? $items[0] : [];
        $price = is_array($item['price'] ?? null) ? $item['price']['id'] ?? null : ($item['price'] ?? null);

        if (! is_string($id) || ! is_string($customer) || ! is_string($status) || ! is_string($price)) {
            throw new BillingProviderException('billing_invalid_provider_response', 'Stripe returned an invalid subscription.');
        }

        return new self(
            id: $id,
            customerId: $customer,
            priceId: $price,
            itemId: is_string($item['id'] ?? null) ? $item['id'] : null,
            status: $status,
            currentPeriodStart: is_int($payload['current_period_start'] ?? null) ? $payload['current_period_start'] : null,
            currentPeriodEnd: is_int($payload['current_period_end'] ?? null) ? $payload['current_period_end'] : null,
            cancelAtPeriodEnd: (bool) ($payload['cancel_at_period_end'] ?? false),
        );
    }
}
