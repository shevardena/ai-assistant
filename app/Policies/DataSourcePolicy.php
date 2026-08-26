<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\DataSource;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCurrentTeamResources;

class DataSourcePolicy
{
    use AuthorizesCurrentTeamResources;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::DataSourcesView);
    }

    public function view(User $user, DataSource $dataSource): bool
    {
        return $this->canAccessTeamResource($user, $dataSource->team_id, TeamPermission::DataSourcesView);
    }

    public function create(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::DataSourcesManage);
    }

    public function update(User $user, DataSource $dataSource): bool
    {
        return $this->canAccessTeamResource($user, $dataSource->team_id, TeamPermission::DataSourcesManage);
    }

    public function delete(User $user, DataSource $dataSource): bool
    {
        return $this->canAccessTeamResource($user, $dataSource->team_id, TeamPermission::DataSourcesManage);
    }

    public function restore(User $user, DataSource $dataSource): bool
    {
        return $this->canAccessTeamResource($user, $dataSource->team_id, TeamPermission::DataSourcesManage);
    }

    public function forceDelete(User $user, DataSource $dataSource): bool
    {
        return $this->canAccessTeamResource($user, $dataSource->team_id, TeamPermission::DataSourcesManage);
    }

    public function manageCredentials(User $user, DataSource $dataSource): bool
    {
        return $this->canAccessTeamResource($user, $dataSource->team_id, TeamPermission::CredentialsManage);
    }

    public function manageApiOperations(User $user, DataSource $dataSource): bool
    {
        return $this->canAccessTeamResource($user, $dataSource->team_id, TeamPermission::ApiOperationsManage);
    }

    public function viewHealth(User $user, DataSource $dataSource): bool
    {
        return $this->canAccessTeamResource($user, $dataSource->team_id, TeamPermission::IntegrationHealthView);
    }
}
