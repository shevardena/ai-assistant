<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Bot;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCurrentTeamResources;

class BotPolicy
{
    use AuthorizesCurrentTeamResources;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::BotsView);
    }

    public function view(User $user, Bot $bot): bool
    {
        return $this->canAccessTeamResource($user, $bot->team_id, TeamPermission::BotsView);
    }

    public function create(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::BotsUpdate);
    }

    public function update(User $user, Bot $bot): bool
    {
        return $this->canAccessTeamResource($user, $bot->team_id, TeamPermission::BotsUpdate);
    }

    public function delete(User $user, Bot $bot): bool
    {
        return $this->canAccessTeamResource($user, $bot->team_id, TeamPermission::BotsUpdate);
    }

    public function restore(User $user, Bot $bot): bool
    {
        return $this->canAccessTeamResource($user, $bot->team_id, TeamPermission::BotsUpdate);
    }

    public function forceDelete(User $user, Bot $bot): bool
    {
        return $this->canAccessTeamResource($user, $bot->team_id, TeamPermission::BotsUpdate);
    }

    public function updateContent(User $user, Bot $bot): bool
    {
        return $this->canAccessTeamResource($user, $bot->team_id, TeamPermission::BotsContentEdit);
    }

    public function viewTests(User $user, Bot $bot): bool
    {
        return $this->canAccessTeamResource($user, $bot->team_id, TeamPermission::BotTestsView);
    }

    public function manageTests(User $user, Bot $bot): bool
    {
        return $this->canAccessTeamResource($user, $bot->team_id, TeamPermission::BotTestsManage);
    }
}
