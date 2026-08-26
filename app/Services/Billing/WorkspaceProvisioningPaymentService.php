<?php

namespace App\Services\Billing;

use App\Data\BillingCheckoutSession;
use App\Data\PlanDefinition;
use App\Enums\WorkspaceProvisioningStatus;
use App\Exceptions\BillingProviderException;
use App\Models\WorkspaceProvisioning;
use Illuminate\Support\Facades\DB;

final class WorkspaceProvisioningPaymentService
{
    public function __construct(private readonly StripeApiClient $stripe) {}

    public function checkout(
        WorkspaceProvisioning $provisioning,
        PlanDefinition $plan,
        string $successUrl,
        string $cancelUrl,
    ): BillingCheckoutSession {
        $priceId = $this->priceId($plan);

        return DB::transaction(function () use ($provisioning, $plan, $priceId, $successUrl, $cancelUrl): BillingCheckoutSession {
            $provisioning = WorkspaceProvisioning::query()->whereKey($provisioning->getKey())->lockForUpdate()->firstOrFail();

            if ($provisioning->status === WorkspaceProvisioningStatus::Completed) {
                throw new BillingProviderException('billing_provisioning_completed', 'This workspace has already been provisioned.');
            }

            if ($provisioning->status === WorkspaceProvisioningStatus::CheckoutCreated
                && $provisioning->expires_at?->isFuture()
                && is_string($provisioning->checkout_session_id)
                && is_string($provisioning->checkout_url)) {
                return new BillingCheckoutSession($provisioning->checkout_session_id, $provisioning->checkout_url);
            }

            $customerId = $provisioning->provider_customer_id;
            if (! is_string($customerId) || $customerId === '') {
                $customer = $this->stripe->post('/customers', [
                    'name' => $provisioning->team_name,
                    'metadata[workspace_provisioning_id]' => (string) $provisioning->getKey(),
                    'metadata[user_id]' => (string) $provisioning->user_id,
                ], 'customer:workspace-provisioning:'.$provisioning->getKey());
                $customerId = $customer['id'] ?? null;

                if (! is_string($customerId) || $customerId === '') {
                    throw new BillingProviderException('billing_provider_unavailable', 'Stripe returned an invalid customer.');
                }
            }

            $payload = $this->stripe->post('/checkout/sessions', [
                'mode' => 'subscription',
                'customer' => $customerId,
                'line_items[0][price]' => $priceId,
                'line_items[0][quantity]' => 1,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => 'workspace-provisioning:'.$provisioning->getKey(),
                'metadata[workspace_provisioning_id]' => (string) $provisioning->getKey(),
                'metadata[plan_key]' => $plan->key,
                'subscription_data[metadata][workspace_provisioning_id]' => (string) $provisioning->getKey(),
                'subscription_data[metadata][plan_key]' => $plan->key,
            ], 'checkout:workspace-provisioning:'.$provisioning->getKey().':'.$plan->key);

            if (! is_string($payload['id'] ?? null) || ! is_string($payload['url'] ?? null)) {
                throw new BillingProviderException('billing_checkout_failed', 'Stripe returned an invalid Checkout Session.');
            }

            $provisioning->forceFill([
                'status' => WorkspaceProvisioningStatus::CheckoutCreated,
                'checkout_session_id' => $payload['id'],
                'checkout_url' => $payload['url'],
                'provider_customer_id' => $customerId,
                'provider_price_id' => $priceId,
                'expires_at' => now()->addHour(),
            ])->save();

            return new BillingCheckoutSession($payload['id'], $payload['url']);
        });
    }

    /** @return array<string, mixed> */
    public function retrieveSubscription(string $subscriptionId): array
    {
        return $this->stripe->get('/subscriptions/'.$subscriptionId);
    }

    private function priceId(PlanDefinition $plan): string
    {
        $priceId = config('billing.plans.'.$plan->key.'.stripe_price_id');

        if (! is_string($priceId) || $priceId === '') {
            throw new BillingProviderException('billing_invalid_plan_mapping', 'This plan is not configured for Stripe.');
        }

        return $priceId;
    }
}
