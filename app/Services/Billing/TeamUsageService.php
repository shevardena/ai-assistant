<?php

namespace App\Services\Billing;

use App\Data\BillingPeriod;
use App\Enums\PlanLimit;
use App\Enums\RuntimeMode;
use App\Models\Conversation;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;

final class TeamUsageService
{
    public function __construct(private readonly BillingPeriodService $periods) {}

    public function botCount(Team $team): int
    {
        return $team->bots()->count();
    }

    public function memberCount(Team $team): int
    {
        return $team->memberships()->count();
    }

    public function conversationUsage(Team $team, ?BillingPeriod $period = null): int
    {
        $period ??= $this->periods->current($team);

        return $team->conversations()
            ->whereIn('conversations.metadata->source', ['widget', 'customer'])
            ->where('conversations.created_at', '>=', $period->start)
            ->where('conversations.created_at', '<', $period->end)
            ->count();
    }

    public function actionUsage(Team $team, ?BillingPeriod $period = null): int
    {
        $period ??= $this->periods->current($team);

        return $team->toolRuns()
            ->where('runtime_mode', RuntimeMode::Normal->value)
            ->where('created_at', '>=', $period->start)
            ->where('created_at', '<', $period->end)
            ->count();
    }

    /**
     * @return array<string, int>
     */
    public function counts(Team $team, ?BillingPeriod $period = null): array
    {
        $period ??= $this->periods->current($team);

        return [
            PlanLimit::Bots->value => $this->botCount($team),
            PlanLimit::TeamMembers->value => $this->memberCount($team),
            PlanLimit::MonthlyConversations->value => $this->conversationUsage($team, $period),
            PlanLimit::MonthlyActions->value => $this->actionUsage($team, $period),
        ];
    }

    /**
     * @return Builder<Conversation>
     */
    public function productionConversationQuery(Team $team): Builder
    {
        return $team->conversations()->getQuery()->whereIn('conversations.metadata->source', ['widget', 'customer']);
    }
}
