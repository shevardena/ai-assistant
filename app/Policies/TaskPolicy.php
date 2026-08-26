<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCurrentTeamResources;

class TaskPolicy
{
    use AuthorizesCurrentTeamResources;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::TasksView);
    }

    public function view(User $user, Task $task): bool
    {
        return $this->canAccessTeamResource($user, $task->team_id, TeamPermission::TasksView);
    }

    public function create(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::TasksManage);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->canAccessTeamResource($user, $task->team_id, TeamPermission::TasksManage);
    }
}
