<?php

namespace App\Services\Billing;

use App\Data\PlanDefinition;
use App\Data\StripeSubscriptionSnapshot;
use App\Enums\SubscriptionStatus;
use App\Enums\TeamRole;
use App\Enums\WorkspaceProvisioningStatus;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkspaceProvisioning;
use App\Services\Deals\PipelineService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WorkspaceProvisioningService
{
    public function __construct(
        private readonly PlanRegistry $plans,
        private readonly WorkspaceProvisioningPaymentService $payments,
        private readonly PipelineService $pipelines,
    ) {}

    public function start(User $user, string $name, string $planKey, string $successUrl, string $cancelUrl): string
    {
        $plan = $this->paidPlan($planKey);
        $provisioning = WorkspaceProvisioning::query()->create([
            'user_id' => $user->getKey(),
            'team_name' => $name,
            'plan_key' => $plan->key,
            'status' => WorkspaceProvisioningStatus::Pending,
            'expires_at' => now()->addHour(),
        ]);

        return $this->payments->checkout($provisioning, $plan, $successUrl, $cancelUrl)->url;
    }

    public function checkout(WorkspaceProvisioning $provisioning, string $successUrl, string $cancelUrl): string
    {
        return $this->payments->checkout($provisioning, $this->paidPlan($provisioning->plan_key), $successUrl, $cancelUrl)->url;
    }

    public function retry(WorkspaceProvisioning $provisioning, string $successUrl, string $cancelUrl): string
    {
        $plan = $this->paidPlan($provisioning->plan_key);

        DB::transaction(function () use ($provisioning): void {
            $locked = WorkspaceProvisioning::query()->whereKey($provisioning->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === WorkspaceProvisioningStatus::Completed) {
                throw ValidationException::withMessages(['billing' => 'This workspace has already been created.']);
            }

            $locked->forceFill([
                'status' => WorkspaceProvisioningStatus::Pending,
                'checkout_session_id' => null,
                'checkout_url' => null,
                'expires_at' => now()->addHour(),
            ])->save();
        });

        return $this->payments->checkout($provisioning->fresh(), $plan, $successUrl, $cancelUrl)->url;
    }

    public function cancel(WorkspaceProvisioning $provisioning): void
    {
        DB::transaction(function () use ($provisioning): void {
            $locked = WorkspaceProvisioning::query()->whereKey($provisioning->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== WorkspaceProvisioningStatus::Completed) {
                $locked->forceFill(['status' => WorkspaceProvisioningStatus::Cancelled])->save();
            }
        });
    }

    public function markExpired(WorkspaceProvisioning $provisioning): WorkspaceProvisioning
    {
        if ($provisioning->status === WorkspaceProvisioningStatus::CheckoutCreated && $provisioning->expires_at?->isPast()) {
            $provisioning->forceFill(['status' => WorkspaceProvisioningStatus::Expired])->save();
        }

        return $provisioning->fresh();
    }

    /** @param array<string, mixed> $payload */
    public function recordSubscriptionSnapshot(array $payload): void
    {
        $provisioningId = data_get($payload, 'metadata.workspace_provisioning_id');
        if (! is_string($provisioningId) || ! ctype_digit($provisioningId)) {
            return;
        }

        $provisioning = WorkspaceProvisioning::query()->find((int) $provisioningId);
        if (! $provisioning || $provisioning->status === WorkspaceProvisioningStatus::Completed) {
            return;
        }

        $snapshot = StripeSubscriptionSnapshot::fromPayload($payload);
        $plan = $this->paidPlanOrNull($provisioning->plan_key);
        $expectedPrice = $plan ? config('billing.plans.'.$plan->key.'.stripe_price_id') : null;
        if (! is_string($expectedPrice)
            || $snapshot->priceId !== $expectedPrice
            || ($provisioning->provider_customer_id !== null && $provisioning->provider_customer_id !== $snapshot->customerId)) {
            return;
        }

        $provisioning->forceFill([
            'provider_subscription_id' => $snapshot->id,
            'provider_customer_id' => $snapshot->customerId,
            'provider_price_id' => $snapshot->priceId,
        ])->save();
    }

    /** @param array<string, mixed> $session */
    public function finalizeCheckout(array $session): void
    {
        $provisioningId = data_get($session, 'metadata.workspace_provisioning_id');
        $sessionId = $session['id'] ?? null;
        if (! is_string($provisioningId) || ! ctype_digit($provisioningId) || ! is_string($sessionId)) {
            return;
        }

        $provisioning = WorkspaceProvisioning::query()->whereKey((int) $provisioningId)->first();
        if (! $provisioning
            || $provisioning->checkout_session_id !== $sessionId
            || $provisioning->status !== WorkspaceProvisioningStatus::CheckoutCreated
            || data_get($session, 'metadata.plan_key') !== $provisioning->plan_key) {
            return;
        }

        if (($session['mode'] ?? null) !== 'subscription'
            || ($session['status'] ?? null) !== 'complete'
            || ! in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true)) {
            return;
        }

        $customerId = $session['customer'] ?? null;
        $subscriptionId = $session['subscription'] ?? null;
        if (! is_string($customerId) || ! is_string($subscriptionId) || $customerId !== $provisioning->provider_customer_id) {
            return;
        }

        $snapshot = StripeSubscriptionSnapshot::fromPayload($this->payments->retrieveSubscription($subscriptionId));
        $plan = $this->paidPlanOrNull($provisioning->plan_key);
        $expectedPrice = $plan ? config('billing.plans.'.$plan->key.'.stripe_price_id') : null;
        if (! $plan
            || $snapshot->id !== $subscriptionId
            || $snapshot->customerId !== $customerId
            || $snapshot->priceId !== $expectedPrice
            || $snapshot->status !== 'active'
            || ($provisioning->provider_subscription_id !== null && $provisioning->provider_subscription_id !== $snapshot->id)) {
            return;
        }

        DB::transaction(function () use ($provisioning, $snapshot, $plan, $sessionId): void {
            $locked = WorkspaceProvisioning::query()->whereKey($provisioning->getKey())->lockForUpdate()->first();
            if (! $locked
                || $locked->checkout_session_id !== $sessionId
                || $locked->status !== WorkspaceProvisioningStatus::CheckoutCreated
                || $locked->provider_customer_id !== $snapshot->customerId
                || ($locked->provider_subscription_id !== null && $locked->provider_subscription_id !== $snapshot->id)) {
                return;
            }

            $team = Team::query()->create(['name' => $locked->team_name]);
            $team->subscription()->create([
                'plan_key' => $plan->key,
                'status' => SubscriptionStatus::Active,
                'provider' => 'stripe',
                'provider_customer_id' => $snapshot->customerId,
                'provider_subscription_id' => $snapshot->id,
                'provider_price_id' => $snapshot->priceId,
                'provider_subscription_item_id' => $snapshot->itemId,
                'cancel_at_period_end' => $snapshot->cancelAtPeriodEnd,
                'current_period_start' => $this->timestamp($snapshot->currentPeriodStart),
                'current_period_end' => $this->timestamp($snapshot->currentPeriodEnd),
            ]);
            $team->memberships()->create(['user_id' => $locked->user_id, 'role' => TeamRole::Owner]);
            $this->pipelines->ensureDefault($team);

            $locked->forceFill([
                'status' => WorkspaceProvisioningStatus::Completed,
                'team_id' => $team->getKey(),
                'completed_at' => now(),
            ])->save();
        });
    }

    public function plan(string $planKey): PlanDefinition
    {
        return $this->paidPlan($planKey);
    }

    private function paidPlan(string $planKey): PlanDefinition
    {
        $plan = $this->paidPlanOrNull($planKey);
        if (! $plan || ! is_string(config('billing.plans.'.$plan->key.'.stripe_price_id')) || config('billing.plans.'.$plan->key.'.stripe_price_id') === '') {
            throw ValidationException::withMessages(['plan_key' => 'This paid plan is not currently available.']);
        }

        return $plan;
    }

    private function paidPlanOrNull(string $planKey): ?PlanDefinition
    {
        $plan = $this->plans->find($planKey);

        return $plan && $plan->public && ! in_array($plan->key, ['free', 'legacy'], true) ? $plan : null;
    }

    private function timestamp(?int $timestamp): ?Carbon
    {
        return $timestamp === null ? null : Carbon::createFromTimestampUTC($timestamp);
    }
}
