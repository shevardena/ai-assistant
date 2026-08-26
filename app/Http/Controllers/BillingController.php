<?php

namespace App\Http\Controllers;

use App\Data\PlanDefinition;
use App\Enums\PlanLimit;
use App\Exceptions\BillingProviderException;
use App\Http\Requests\StoreBillingCheckoutRequest;
use App\Http\Requests\UpdateBillingPlanRequest;
use App\Models\Team;
use App\Models\TeamSubscription;
use App\Services\Billing\Contracts\SubscriptionPaymentService;
use App\Services\Billing\PlanRegistry;
use App\Services\Billing\StripeSubscriptionSyncService;
use App\Services\Billing\TeamEntitlementService;
use App\Services\Billing\TeamUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function index(
        Team $currentTeam,
        TeamEntitlementService $entitlements,
        PlanRegistry $plans,
    ): Response {
        return Inertia::render('billing/index', [
            'summary' => $entitlements->billingSummary($currentTeam)->toArray(),
            'plans' => array_map(
                fn (PlanDefinition $plan): array => $plans->toClientArray($plan),
                $plans->publicPlans(),
            ),
            'subscription' => $this->subscriptionData($currentTeam->subscription()->first()),
        ]);
    }

    public function checkout(StoreBillingCheckoutRequest $request, Team $currentTeam, PlanRegistry $plans, SubscriptionPaymentService $payments): RedirectResponse
    {
        $plan = $plans->find((string) $request->validated('plan_key'));

        if (! $plan || ! $plan->public || in_array($plan->key, ['free', 'legacy'], true)) {
            throw ValidationException::withMessages(['plan_key' => 'This plan is not available for checkout.']);
        }

        $currentSubscription = $currentTeam->subscription()->first();
        if ($currentSubscription
            && ! in_array($currentSubscription->plan_key, ['free', 'legacy'], true)
            && is_string($currentSubscription->provider_subscription_id)
            && $currentSubscription->status->value !== 'cancelled') {
            throw ValidationException::withMessages([
                'plan_key' => 'This Team already has a paid subscription. Use plan management to change it.',
            ]);
        }

        try {
            $session = $payments->checkout(
                $currentTeam,
                $plan,
                route('billing.success', $currentTeam->slug).'?session_id={CHECKOUT_SESSION_ID}',
                route('billing.index', $currentTeam->slug),
            );

            return redirect()->away($session->url);
        } catch (BillingProviderException $exception) {
            return back()->withErrors(['billing' => $this->safeMessage($exception)]);
        }
    }

    public function success(Team $currentTeam): Response
    {
        return Inertia::render('billing/success', [
            'team' => ['name' => $currentTeam->name],
        ]);
    }

    public function portal(Team $currentTeam, SubscriptionPaymentService $payments): RedirectResponse
    {
        try {
            return redirect()->away($payments->portal($currentTeam, route('billing.index', $currentTeam->slug))->url);
        } catch (BillingProviderException $exception) {
            return back()->withErrors(['billing' => $this->safeMessage($exception)]);
        }
    }

    public function updatePlan(
        UpdateBillingPlanRequest $request,
        Team $currentTeam,
        PlanRegistry $plans,
        TeamUsageService $usage,
        SubscriptionPaymentService $payments,
        StripeSubscriptionSyncService $sync,
    ): RedirectResponse {
        $target = $plans->find((string) $request->validated('plan_key'));
        $subscription = $currentTeam->subscription()->firstOrFail();

        if (! $target || ! $target->public || in_array($target->key, ['free', 'legacy'], true)) {
            throw ValidationException::withMessages(['plan_key' => 'This plan is not available.']);
        }

        if ($subscription->plan_key === $target->key) {
            return back();
        }

        $currentRank = $this->planRank($subscription->plan_key);
        $targetRank = $this->planRank($target->key);
        if ($targetRank < $currentRank) {
            $counts = $usage->counts($currentTeam);
            if (($target->limit(PlanLimit::Bots)['value'] !== null && $counts[PlanLimit::Bots->value] > $target->limit(PlanLimit::Bots)['value'])
                || ($target->limit(PlanLimit::TeamMembers)['value'] !== null && $counts[PlanLimit::TeamMembers->value] > $target->limit(PlanLimit::TeamMembers)['value'])) {
                throw ValidationException::withMessages(['plan_key' => 'This Team must fit the lower plan limits before it can be downgraded.']);
            }
        }

        if ($subscription->provider !== 'stripe' || ! $subscription->provider_subscription_id) {
            try {
                return redirect()->away($payments->checkout(
                    $currentTeam,
                    $target,
                    route('billing.success', $currentTeam->slug).'?session_id={CHECKOUT_SESSION_ID}',
                    route('billing.index', $currentTeam->slug),
                )->url);
            } catch (BillingProviderException $exception) {
                return back()->withErrors(['billing' => $this->safeMessage($exception)]);
            }
        }

        try {
            if ($targetRank < $currentRank) {
                $sync->synchronizeProviderSnapshot($payments->scheduleDowngrade($subscription, $target));
            } else {
                $sync->synchronizeProviderSnapshot($payments->updatePlan($subscription, $target, true));
            }

            return back()->with('success', $targetRank < $currentRank
                ? 'Your downgrade is scheduled for the next billing period.'
                : 'Your plan upgrade is being synchronized.');
        } catch (BillingProviderException $exception) {
            Log::warning('Billing plan change failed.', ['team_id' => $currentTeam->getKey(), 'error_code' => $exception->errorCode]);

            return back()->withErrors(['billing' => $this->safeMessage($exception)]);
        }
    }

    public function cancel(Team $currentTeam, SubscriptionPaymentService $payments, StripeSubscriptionSyncService $sync): RedirectResponse
    {
        try {
            $sync->synchronizeProviderSnapshot($payments->cancelAtPeriodEnd($currentTeam->subscription()->firstOrFail()));

            return back()->with('success', 'Your subscription will remain active until the end of the current billing period.');
        } catch (BillingProviderException $exception) {
            return back()->withErrors(['billing' => $this->safeMessage($exception)]);
        }
    }

    public function resume(Team $currentTeam, SubscriptionPaymentService $payments, StripeSubscriptionSyncService $sync): RedirectResponse
    {
        try {
            $sync->synchronizeProviderSnapshot($payments->resume($currentTeam->subscription()->firstOrFail()));

            return back()->with('success', 'Your subscription cancellation was resumed.');
        } catch (BillingProviderException $exception) {
            return back()->withErrors(['billing' => $this->safeMessage($exception)]);
        }
    }

    /** @return array<string, mixed> */
    private function subscriptionData(?TeamSubscription $subscription): array
    {
        return [
            'provider' => $subscription?->provider,
            'status' => $subscription === null ? 'active' : $subscription->status->value,
            'cancel_at_period_end' => (bool) $subscription?->cancel_at_period_end,
            'current_period_end' => $subscription?->current_period_end?->toIso8601String(),
            'has_billing_customer' => is_string($subscription?->provider_customer_id),
        ];
    }

    private function planRank(string $plan): int
    {
        return ['free' => 0, 'legacy' => 0, 'starter' => 1, 'pro' => 2, 'business' => 3][$plan] ?? 0;
    }

    private function safeMessage(BillingProviderException $exception): string
    {
        return match ($exception->errorCode) {
            'billing_invalid_plan_mapping' => 'This plan is not currently available for online billing.',
            'billing_customer_missing' => 'Start a paid plan before opening the billing portal.',
            'billing_subscription_missing' => 'There is no active paid subscription to manage.',
            default => 'Billing is temporarily unavailable. Please try again later.',
        };
    }
}
