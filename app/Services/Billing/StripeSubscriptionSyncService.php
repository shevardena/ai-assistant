<?php

namespace App\Services\Billing;

use App\Data\StripeSubscriptionSnapshot;
use App\Enums\SubscriptionStatus;
use App\Models\StripeWebhookEvent;
use App\Models\TeamSubscription;
use App\Services\Teams\TeamNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class StripeSubscriptionSyncService
{
    public function __construct(
        private readonly PlanRegistry $plans,
        private readonly TeamNotificationService $notifications,
        private readonly WorkspaceProvisioningService $provisioning,
    ) {}

    /** @param array<string, mixed> $event */
    public function handle(array $event): void
    {
        $eventId = $event['id'];
        $eventType = (string) $event['type'];

        DB::transaction(function () use ($event, $eventId, $eventType): void {
            $stored = StripeWebhookEvent::query()->where('event_id', $eventId)->lockForUpdate()->first();

            if ($stored?->processed_at !== null) {
                return;
            }

            $stored ??= StripeWebhookEvent::query()->create([
                'event_id' => $eventId,
                'event_type' => $eventType,
            ]);

            $object = data_get($event, 'data.object');
            if (is_array($object)) {
                match ($eventType) {
                    'checkout.session.completed' => $this->provisioning->finalizeCheckout($object),
                    'customer.subscription.created', 'customer.subscription.updated' => $this->syncSubscription($object, $eventId),
                    'customer.subscription.deleted' => $this->endSubscription($object, $eventId),
                    'invoice.payment_failed' => $this->markPaymentFailed($object, $eventId),
                    'invoice.paid' => $this->markPaymentRecovered($object, $eventId),
                    default => null,
                };
            }

            $stored->forceFill(['processed_at' => now()])->save();
        });
    }

    public function synchronizeProviderSnapshot(StripeSubscriptionSnapshot $snapshot): void
    {
        $subscription = TeamSubscription::query()
            ->where('provider_subscription_id', $snapshot->id)
            ->orWhere('provider_customer_id', $snapshot->customerId)
            ->first();

        if (! $subscription) {
            return;
        }

        $planKey = $this->planKeyForPrice($snapshot->priceId);
        if ($planKey === null) {
            Log::warning('Stripe provider response has an unknown price.', ['subscription_id' => $snapshot->id]);

            return;
        }

        $status = $this->status($snapshot->status);
        $end = $this->timestamp($snapshot->currentPeriodEnd);
        $subscription->forceFill([
            'plan_key' => $status === SubscriptionStatus::Cancelled && (! $end || $end->isPast()) ? 'free' : $planKey,
            'status' => $status,
            'provider' => 'stripe',
            'provider_customer_id' => $snapshot->customerId,
            'provider_subscription_id' => $snapshot->id,
            'provider_price_id' => $snapshot->priceId,
            'provider_subscription_item_id' => $snapshot->itemId,
            'cancel_at_period_end' => $snapshot->cancelAtPeriodEnd,
            'current_period_start' => $this->timestamp($snapshot->currentPeriodStart),
            'current_period_end' => $end,
        ])->save();
    }

    /** @param array<string, mixed> $payload */
    private function syncSubscription(array $payload, string $eventId): void
    {
        $snapshot = StripeSubscriptionSnapshot::fromPayload($payload);
        $this->provisioning->recordSubscriptionSnapshot($payload);
        $subscription = $this->findSubscription($payload);

        if (! $subscription) {
            return;
        }

        $planKey = $this->planKeyForPrice($snapshot->priceId);
        if ($planKey === null) {
            Log::warning('Stripe subscription has an unknown price.', [
                'event_id' => $eventId,
                'subscription_id' => $snapshot->id,
            ]);

            return;
        }

        $wasPaid = ! in_array($subscription->plan_key, ['free', 'legacy'], true);
        $wasCancelling = $subscription->cancel_at_period_end;
        $status = $this->status($snapshot->status);
        $endsAt = $this->timestamp($snapshot->currentPeriodEnd);
        $effectivePlan = $status === SubscriptionStatus::Cancelled
            && ($endsAt === null || $endsAt->isPast())
            ? 'free'
            : $planKey;

        $subscription->forceFill([
            'plan_key' => $effectivePlan,
            'status' => $status,
            'provider' => 'stripe',
            'provider_customer_id' => $snapshot->customerId,
            'provider_subscription_id' => $snapshot->id,
            'provider_price_id' => $snapshot->priceId,
            'provider_subscription_item_id' => $snapshot->itemId,
            'cancel_at_period_end' => $snapshot->cancelAtPeriodEnd,
            'current_period_start' => $this->timestamp($snapshot->currentPeriodStart),
            'current_period_end' => $endsAt,
        ])->save();

        $team = $subscription->team;
        if (! $team) {
            return;
        }

        if (! $wasPaid && in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true) && $effectivePlan !== 'free') {
            $this->notifications->notifySubscriptionActivated($team, 'stripe:'.$eventId.':activated');
        }

        if (! $wasCancelling && $snapshot->cancelAtPeriodEnd) {
            $this->notifications->notifySubscriptionCancelScheduled($team, 'stripe:'.$eventId.':cancel-scheduled');
        }
    }

    /** @param array<string, mixed> $payload */
    private function endSubscription(array $payload, string $eventId): void
    {
        $subscription = $this->findSubscription($payload);
        if (! $subscription) {
            return;
        }

        $subscription->forceFill([
            'plan_key' => 'free',
            'status' => SubscriptionStatus::Cancelled,
            'cancel_at_period_end' => false,
            'current_period_end' => now(),
        ])->save();

        if ($subscription->team) {
            $this->notifications->notifySubscriptionEnded($subscription->team, 'stripe:'.$eventId.':ended');
        }
    }

    /** @param array<string, mixed> $payload */
    private function markPaymentFailed(array $payload, string $eventId): void
    {
        $subscription = $this->findSubscription($payload);
        if (! $subscription || $subscription->status === SubscriptionStatus::Cancelled) {
            return;
        }

        $subscription->forceFill(['status' => SubscriptionStatus::PastDue])->save();
        if ($subscription->team) {
            $this->notifications->notifySubscriptionPaymentFailed($subscription->team, 'stripe:'.$eventId.':payment-failed');
        }
    }

    /** @param array<string, mixed> $payload */
    private function markPaymentRecovered(array $payload, string $eventId): void
    {
        $subscription = $this->findSubscription($payload);
        if (! $subscription || $subscription->status !== SubscriptionStatus::PastDue) {
            return;
        }

        $subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
        if ($subscription->team) {
            $this->notifications->notifySubscriptionActivated($subscription->team, 'stripe:'.$eventId.':recovered');
        }
    }

    /** @param array<string, mixed> $payload */
    private function findSubscription(array $payload): ?TeamSubscription
    {
        $objectId = $payload['id'] ?? null;
        $subscriptionId = is_string($objectId) && str_starts_with($objectId, 'sub_')
            ? $objectId
            : $payload['subscription'] ?? null;
        $customerId = $payload['customer'] ?? null;

        if (! is_string($subscriptionId) && ! is_string($customerId)) {
            return null;
        }

        $query = TeamSubscription::query();
        if (is_string($subscriptionId)) {
            $query->where('provider_subscription_id', $subscriptionId);
            if (is_string($customerId)) {
                $query->orWhere('provider_customer_id', $customerId);
            }
        } else {
            $query->where('provider_customer_id', $customerId);
        }

        return $query->first();
    }

    private function planKeyForPrice(string $priceId): ?string
    {
        foreach (array_keys(config('billing.plans', [])) as $planKey) {
            $planKey = (string) $planKey;
            if (config('billing.plans.'.$planKey.'.stripe_price_id') === $priceId && $this->plans->find($planKey)?->public) {
                return $planKey;
            }
        }

        return null;
    }

    private function status(string $providerStatus): SubscriptionStatus
    {
        return match ($providerStatus) {
            'active' => SubscriptionStatus::Active,
            'trialing' => SubscriptionStatus::Trialing,
            'past_due', 'unpaid', 'incomplete_expired' => SubscriptionStatus::PastDue,
            'incomplete' => SubscriptionStatus::Incomplete,
            default => SubscriptionStatus::Cancelled,
        };
    }

    private function timestamp(?int $timestamp): ?Carbon
    {
        return $timestamp === null ? null : Carbon::createFromTimestampUTC($timestamp);
    }
}
