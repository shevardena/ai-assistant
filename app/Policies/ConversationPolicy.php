<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Conversation;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCurrentTeamResources;

class ConversationPolicy
{
    use AuthorizesCurrentTeamResources;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::ConversationsView);
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $this->canAccessTeamResource($user, (int) $conversation->bot?->team_id, TeamPermission::ConversationsView);
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $this->canAccessTeamResource($user, (int) $conversation->bot?->team_id, TeamPermission::ConversationsReply);
    }

    public function handoff(User $user, Conversation $conversation): bool
    {
        return $this->canAccessTeamResource($user, (int) $conversation->bot?->team_id, TeamPermission::ConversationsHandoff);
    }

    public function manage(User $user, Conversation $conversation): bool
    {
        return $this->canAccessTeamResource($user, (int) $conversation->bot?->team_id, TeamPermission::ConversationsManage);
    }
}
