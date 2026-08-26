<?php

namespace App\Services\Billing;

use App\Data\PlanDefinition;
use App\Data\TeamBillingSummary;
use App\Enums\PlanFeature;
use App\Enums\PlanLimit;
use App\Enums\SubscriptionStatus;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Team;
use App\Models\TeamSubscription;
use Illuminate\Validation\ValidationException;

final class TeamEntitlementService
{
    public function __construct(
        private readonly PlanRegistry $plans,
        private readonly BillingPeriodService $periods,
        private readonly TeamUsageService $usage,
    ) {}

    public function currentPlan(Team $team): PlanDefinition
    {
        $subscription = $team->subscription()->first();

        if ($subscription?->provider === 'stripe'
            && $subscription->status === SubscriptionStatus::Cancelled
            && (! $subscription->current_period_end || $subscription->current_period_end->isPast())) {
            return $this->plans->find('free') ?? $this->plans->legacy();
        }

        $key = $subscription === null ? 'legacy' : $subscription->plan_key;

        return $this->plans->find((string) $key) ?? $this->plans->legacy();
    }

    public function subscription(Team $team): ?TeamSubscription
    {
        return $team->subscription()->first();
    }

    public function hasFeature(Team $team, PlanFeature|string $feature): bool
    {
        $feature = $feature instanceof PlanFeature ? $feature : PlanFeature::tryFrom($feature);

        return $feature !== null && $this->currentPlan($team)->hasFeature($feature);
    }

    public function limit(Team $team, PlanLimit|string $limit): ?int
    {
        $limit = $limit instanceof PlanLimit ? $limit : PlanLimit::tryFrom($limit);

        return $limit === null ? null : $this->currentPlan($team)->limit($limit)['value'];
    }

    public function isUnlimited(Team $team, PlanLimit|string $limit): bool
    {
        return $this->limit($team, $limit) === null;
    }

    public function usage(Team $team, PlanLimit|string $limit): int
    {
        $limit = $limit instanceof PlanLimit ? $limit : PlanLimit::tryFrom($limit);

        return match ($limit) {
            PlanLimit::Bots => $this->usage->botCount($team),
            PlanLimit::TeamMembers => $this->usage->memberCount($team),
            PlanLimit::MonthlyConversations => $this->usage->conversationUsage($team, $this->periods->current($team)),
            PlanLimit::MonthlyActions => $this->usage->actionUsage($team, $this->periods->current($team)),
            null => 0,
        };
    }

    public function remaining(Team $team, PlanLimit|string $limit): ?int
    {
        $limit = $limit instanceof PlanLimit ? $limit : PlanLimit::tryFrom($limit);
        $value = $limit === null ? null : $this->limit($team, $limit);

        return $value === null ? null : max(0, $value - $this->usage($team, $limit));
    }

    public function percentage(Team $team, PlanLimit|string $limit): ?float
    {
        $limit = $limit instanceof PlanLimit ? $limit : PlanLimit::tryFrom($limit);
        $value = $limit === null ? null : $this->limit($team, $limit);

        if ($value === null || $value === 0) {
            return $value === 0 ? 100.0 : null;
        }

        return min(100.0, round(($this->usage($team, $limit) / $value) * 100, 1));
    }

    public function canConsume(Team $team, PlanLimit $limit, int $amount = 1): bool
    {
        $value = $this->limit($team, $limit);

        return $value === null || ($this->usage($team, $limit) + $amount) <= $value;
    }

    public function assertCanConsume(Team $team, PlanLimit $limit, string $field = 'name'): void
    {
        if ($this->canConsume($team, $limit)) {
            return;
        }

        $maximum = $this->limit($team, $limit);
        throw ValidationException::withMessages([
            $field => ["Your current plan allows up to {$maximum} {$limit->label()}."],
        ]);
    }

    public function ensureCanStartConversation(Team $team): void
    {
        $this->ensureProductionLimit($team, PlanLimit::MonthlyConversations);
    }

    public function ensureCanStartAction(Team $team): void
    {
        $this->ensureProductionLimit($team, PlanLimit::MonthlyActions);
    }

    public function billingSummary(Team $team): TeamBillingSummary
    {
        $plan = $this->currentPlan($team);
        $period = $this->periods->current($team);
        $counts = $this->usage->counts($team, $period);
        $subscription = $this->subscription($team);
        $status = $subscription === null ? SubscriptionStatus::Active : $subscription->status;
        $usage = [];

        foreach (PlanLimit::cases() as $limit) {
            $definition = $plan->limit($limit);
            $maximum = $definition['value'];
            $used = $counts[$limit->value];
            $percentage = $maximum === null
                ? null
                : min(100.0, round(($used / max(1, $maximum)) * 100, 1));

            $usage[$limit->value] = [
                'key' => $limit->value,
                'label' => $limit->label(),
                'used' => $used,
                'limit' => $maximum,
                'unlimited' => $maximum === null,
                'percentage' => $percentage,
                'warning' => $percentage !== null && $percentage >= ($definition['warning_threshold'] * 100),
                'reached' => $maximum !== null && $used >= $maximum,
                'enforcement' => $definition['enforcement'],
            ];
        }

        return new TeamBillingSummary(
            plan: $plan->toArray(),
            status: $status,
            period: $period,
            usage: $usage,
            features: collect(PlanFeature::cases())
                ->mapWithKeys(fn (PlanFeature $feature): array => [$feature->value => $plan->hasFeature($feature)])
                ->all(),
        );
    }

    /**
     * Throw the visitor-safe exception for a hard production quota.
     */
    public function ensureProductionLimit(Team $team, PlanLimit $limit): void
    {
        $definition = $this->currentPlan($team)->limit($limit);

        if ($definition['enforcement'] === 'hard' && ! $this->canConsume($team, $limit)) {
            throw new PlanLimitExceededException($limit);
        }
    }
}
