<?php

namespace App\Services\Billing;

use App\Data\BillingCheckoutSession;
use App\Data\BillingPortalSession;
use App\Data\PlanDefinition;
use App\Data\StripeSubscriptionSnapshot;
use App\Exceptions\BillingProviderException;
use App\Models\Team;
use App\Models\TeamSubscription;
use App\Services\Billing\Contracts\SubscriptionPaymentService;
use Illuminate\Support\Facades\DB;

final class StripeSubscriptionPaymentService implements SubscriptionPaymentService
{
    public function __construct(private readonly StripeApiClient $stripe) {}

    public function checkout(Team $team, PlanDefinition $plan, string $successUrl, string $cancelUrl): BillingCheckoutSession
    {
        $priceId = $this->priceId($plan);
        $subscription = $this->ensureCustomer($team);

        $payload = $this->stripe->post('/checkout/sessions', [
            'mode' => 'subscription',
            'customer' => $subscription->provider_customer_id,
            'line_items[0][price]' => $priceId,
            'line_items[0][quantity]' => 1,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $team->slug,
            'metadata[team_slug]' => $team->slug,
            'metadata[plan_key]' => $plan->key,
            'subscription_data[metadata][team_slug]' => $team->slug,
            'subscription_data[metadata][plan_key]' => $plan->key,
        ], 'checkout:team:'.$team->getKey().':'.$plan->key);

        if (! is_string($payload['id'] ?? null) || ! is_string($payload['url'] ?? null)) {
            throw new BillingProviderException('billing_checkout_failed', 'Stripe returned an invalid Checkout Session.');
        }

        return new BillingCheckoutSession($payload['id'], $payload['url']);
    }

    public function portal(Team $team, string $returnUrl): BillingPortalSession
    {
        $subscription = $team->subscription()->first();

        if (! is_string($subscription?->provider_customer_id)) {
            throw new BillingProviderException('billing_customer_missing', 'This Team does not have a billing customer yet.');
        }

        $payload = $this->stripe->post('/billing_portal/sessions', [
            'customer' => $subscription->provider_customer_id,
            'return_url' => $returnUrl,
        ], 'portal:team:'.$team->getKey());

        if (! is_string($payload['url'] ?? null)) {
            throw new BillingProviderException('billing_portal_failed', 'Stripe returned an invalid billing portal session.');
        }

        return new BillingPortalSession($payload['url']);
    }

    public function updatePlan(TeamSubscription $subscription, PlanDefinition $plan, bool $prorate): StripeSubscriptionSnapshot
    {
        $priceId = $this->priceId($plan);
        $itemId = $subscription->provider_subscription_item_id;

        if (! is_string($itemId) || $itemId === '') {
            $itemId = $this->retrieve($subscription)->itemId;
        }

        if (! is_string($subscription->provider_subscription_id) || ! is_string($itemId)) {
            throw new BillingProviderException('billing_subscription_missing', 'This Team does not have an active Stripe subscription.');
        }

        $payload = $this->stripe->post('/subscriptions/'.$subscription->provider_subscription_id, [
            'items[0][id]' => $itemId,
            'items[0][price]' => $priceId,
            'proration_behavior' => $prorate ? 'create_prorations' : 'none',
            'cancel_at_period_end' => 'false',
        ], 'plan-change:team:'.$subscription->team_id.':'.$plan->key);

        return StripeSubscriptionSnapshot::fromPayload($payload);
    }

    public function cancelAtPeriodEnd(TeamSubscription $subscription): StripeSubscriptionSnapshot
    {
        return $this->changeCancellation($subscription, true);
    }

    public function scheduleDowngrade(TeamSubscription $subscription, PlanDefinition $plan): StripeSubscriptionSnapshot
    {
        $priceId = $this->priceId($plan);
        $currentPriceId = $subscription->provider_price_id;

        if (! is_string($subscription->provider_subscription_id)
            || ! is_string($currentPriceId)
            || ! $subscription->current_period_end) {
            throw new BillingProviderException('billing_subscription_missing', 'This Team does not have an active Stripe subscription.');
        }

        $this->stripe->post('/subscription_schedules', [
            'from_subscription' => $subscription->provider_subscription_id,
            'end_behavior' => 'release',
            'phases[0][items][0][price]' => $currentPriceId,
            'phases[0][items][0][quantity]' => 1,
            'phases[0][end_date]' => $subscription->current_period_end->timestamp,
            'phases[1][items][0][price]' => $priceId,
            'phases[1][items][0][quantity]' => 1,
        ], 'downgrade:team:'.$subscription->team_id.':'.$plan->key);

        return $this->retrieve($subscription);
    }

    public function resume(TeamSubscription $subscription): StripeSubscriptionSnapshot
    {
        return $this->changeCancellation($subscription, false);
    }

    private function changeCancellation(TeamSubscription $subscription, bool $cancel): StripeSubscriptionSnapshot
    {
        if (! is_string($subscription->provider_subscription_id)) {
            throw new BillingProviderException('billing_subscription_missing', 'This Team does not have an active Stripe subscription.');
        }

        $payload = $this->stripe->post('/subscriptions/'.$subscription->provider_subscription_id, [
            'cancel_at_period_end' => $cancel ? 'true' : 'false',
        ], 'cancellation:team:'.$subscription->team_id.':'.($cancel ? 'cancel' : 'resume'));

        return StripeSubscriptionSnapshot::fromPayload($payload);
    }

    private function ensureCustomer(Team $team): TeamSubscription
    {
        return DB::transaction(function () use ($team): TeamSubscription {
            $subscription = TeamSubscription::query()->where('team_id', $team->getKey())->lockForUpdate()->first();

            if (! $subscription) {
                $subscription = $team->subscription()->create([
                    'plan_key' => 'free',
                    'status' => 'active',
                    'current_period_start' => now()->startOfMonth(),
                    'current_period_end' => now()->startOfMonth()->addMonth(),
                ]);
            }

            if (is_string($subscription->provider_customer_id) && $subscription->provider_customer_id !== '') {
                return $subscription;
            }

            $payload = $this->stripe->post('/customers', [
                'name' => $team->name,
                'metadata[team_slug]' => $team->slug,
            ], 'customer:team:'.$team->getKey());

            if (! is_string($payload['id'] ?? null)) {
                throw new BillingProviderException('billing_provider_unavailable', 'Stripe returned an invalid customer.');
            }

            $subscription->forceFill([
                'provider' => 'stripe',
                'provider_customer_id' => $payload['id'],
            ])->save();

            return $subscription->fresh();
        });
    }

    private function retrieve(TeamSubscription $subscription): StripeSubscriptionSnapshot
    {
        if (! is_string($subscription->provider_subscription_id)) {
            throw new BillingProviderException('billing_subscription_missing', 'This Team does not have an active Stripe subscription.');
        }

        return StripeSubscriptionSnapshot::fromPayload($this->stripe->get('/subscriptions/'.$subscription->provider_subscription_id));
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
