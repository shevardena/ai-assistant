<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\BillingAccountService;
use App\Services\Billing\BillingPeriodService;
use App\Services\Billing\PlanRegistry;
use App\Services\Deals\PipelineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateTeam
{
    /**
     * Create a new team and add the user as owner.
     */
    public function __construct(
        private readonly BillingPeriodService $periods,
        private readonly PlanRegistry $plans,
        private readonly BillingAccountService $billingAccounts,
        private readonly PipelineService $pipelines,
    ) {}

    public function handle(User $user, string $name, bool $isPersonal = false): Team
    {
        return DB::transaction(function () use ($user, $name, $isPersonal) {
            $billingAccount = $this->billingAccounts->lockedForUser($user);

            if (! $this->billingAccounts->canCreateFreeTeam($billingAccount)) {
                throw ValidationException::withMessages([
                    'billing' => 'Your Free workspace has already been used. Additional workspaces require a paid plan.',
                ]);
            }

            $team = Team::create([
                'name' => $name,
                'is_personal' => $isPersonal,
            ]);

            $period = $this->periods->current($team);
            $team->subscription()->create([
                'plan_key' => $this->plans->find('free')->key,
                'status' => 'active',
                'current_period_start' => $period->start,
                'current_period_end' => $period->end,
            ]);

            $this->billingAccounts->consumeFreeWorkspace($billingAccount);

            $membership = $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Owner,
            ]);

            $user->switchTeam($team);
            $this->pipelines->ensureDefault($team);

            return $team;
        });
    }
}
