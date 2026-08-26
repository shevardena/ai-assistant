<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Dataset;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCurrentTeamResources;

class DatasetPolicy
{
    use AuthorizesCurrentTeamResources;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::DatasetsView);
    }

    public function view(User $user, Dataset $dataset): bool
    {
        return $this->canAccessTeamResource($user, $dataset->team_id, TeamPermission::DatasetsView);
    }

    public function create(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::DatasetsManage);
    }

    public function update(User $user, Dataset $dataset): bool
    {
        return $this->canAccessTeamResource($user, $dataset->team_id, TeamPermission::DatasetsManage);
    }

    public function delete(User $user, Dataset $dataset): bool
    {
        return $this->canAccessTeamResource($user, $dataset->team_id, TeamPermission::DatasetsManage);
    }

    public function restore(User $user, Dataset $dataset): bool
    {
        return $this->canAccessTeamResource($user, $dataset->team_id, TeamPermission::DatasetsManage);
    }

    public function forceDelete(User $user, Dataset $dataset): bool
    {
        return $this->canAccessTeamResource($user, $dataset->team_id, TeamPermission::DatasetsManage);
    }

    public function manageFields(User $user, Dataset $dataset): bool
    {
        return $this->canAccessTeamResource($user, $dataset->team_id, TeamPermission::DatasetFieldsManage);
    }

    public function viewHealth(User $user, Dataset $dataset): bool
    {
        return $this->canAccessTeamResource($user, $dataset->team_id, TeamPermission::DataHealthView);
    }
}
