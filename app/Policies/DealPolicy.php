<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Deal;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCurrentTeamResources;

class DealPolicy
{
    use AuthorizesCurrentTeamResources;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::DealsView);
    }

    public function view(User $user, Deal $deal): bool
    {
        return $this->canAccessTeamResource($user, $deal->team_id, TeamPermission::DealsView);
    }

    public function create(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::DealsManage);
    }

    public function update(User $user, Deal $deal): bool
    {
        return $this->canAccessTeamResource($user, $deal->team_id, TeamPermission::DealsManage);
    }
}
