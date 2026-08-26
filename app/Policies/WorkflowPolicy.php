<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\User;
use App\Models\Workflow;
use App\Policies\Concerns\AuthorizesCurrentTeamResources;

class WorkflowPolicy
{
    use AuthorizesCurrentTeamResources;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::WorkflowsView);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Workflow $workflow): bool
    {
        return $this->canAccessTeamResource($user, (int) $workflow->team_id, TeamPermission::WorkflowsView);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::WorkflowsManage);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Workflow $workflow): bool
    {
        return $this->canAccessTeamResource($user, (int) $workflow->team_id, TeamPermission::WorkflowsManage);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Workflow $workflow): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Workflow $workflow): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Workflow $workflow): bool
    {
        return false;
    }
}
