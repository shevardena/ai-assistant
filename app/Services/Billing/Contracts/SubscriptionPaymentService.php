<?php

namespace App\Services\Billing\Contracts;

use App\Data\BillingCheckoutSession;
use App\Data\BillingPortalSession;
use App\Data\PlanDefinition;
use App\Data\StripeSubscriptionSnapshot;
use App\Models\Team;
use App\Models\TeamSubscription;

interface SubscriptionPaymentService
{
    public function checkout(Team $team, PlanDefinition $plan, string $successUrl, string $cancelUrl): BillingCheckoutSession;

    public function portal(Team $team, string $returnUrl): BillingPortalSession;

    public function updatePlan(TeamSubscription $subscription, PlanDefinition $plan, bool $prorate): StripeSubscriptionSnapshot;

    public function scheduleDowngrade(TeamSubscription $subscription, PlanDefinition $plan): StripeSubscriptionSnapshot;

    public function cancelAtPeriodEnd(TeamSubscription $subscription): StripeSubscriptionSnapshot;

    public function resume(TeamSubscription $subscription): StripeSubscriptionSnapshot;
}
