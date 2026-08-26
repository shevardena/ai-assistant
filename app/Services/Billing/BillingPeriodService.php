<?php

namespace App\Services\Billing;

use App\Data\BillingPeriod;
use App\Models\Team;
use Illuminate\Support\Carbon;

final class BillingPeriodService
{
    public function current(Team $team): BillingPeriod
    {
        $subscription = $team->subscription()->first();

        if ($subscription?->provider === 'stripe' && $subscription->current_period_start && $subscription->current_period_end) {
            return new BillingPeriod(
                Carbon::parse($subscription->current_period_start),
                Carbon::parse($subscription->current_period_end),
            );
        }

        $start = Carbon::now(config('app.timezone'))->startOfMonth();

        return new BillingPeriod($start, $start->copy()->addMonth());
    }
}
