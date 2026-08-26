<?php

namespace App\Policies\Concerns;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAuthorizationService;

trait AuthorizesCurrentTeamResources
{
    /**
     * Determine whether the user has a valid current team available.
     */
    protected function canAccessCurrentTeam(User $user, ?TeamPermission $permission = null): bool
    {
        $currentTeam = $user->currentTeam;

        return $currentTeam !== null
            && $user->isCurrentTeam($currentTeam)
            && $user->belongsToTeam($currentTeam)
            && ($permission === null || app(TeamAuthorizationService::class)->can($user, $currentTeam, $permission));
    }

    /**
     * Determine whether a resource belongs to the user's current team.
     */
    protected function canAccessTeamResource(User $user, int $teamId, ?TeamPermission $permission = null): bool
    {
        $currentTeam = $user->currentTeam;

        return $this->canAccessCurrentTeam($user, $permission)
            && $currentTeam !== null
            && $currentTeam->id === $teamId;
    }
}
