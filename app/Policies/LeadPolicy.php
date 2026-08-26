<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Lead;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCurrentTeamResources;

class LeadPolicy
{
    use AuthorizesCurrentTeamResources;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::LeadsView);
    }

    public function view(User $user, Lead $lead): bool
    {
        return $this->canAccessTeamResource($user, $lead->team_id, TeamPermission::LeadsView);
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->canAccessTeamResource($user, $lead->team_id, TeamPermission::LeadsUpdate);
    }
}
