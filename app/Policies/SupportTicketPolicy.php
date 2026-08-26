<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\SupportTicket;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCurrentTeamResources;

class SupportTicketPolicy
{
    use AuthorizesCurrentTeamResources;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::TicketsView);
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return $this->canAccessTeamResource($user, $ticket->team_id, TeamPermission::TicketsView);
    }

    public function update(User $user, SupportTicket $ticket): bool
    {
        return $this->canAccessTeamResource($user, $ticket->team_id, TeamPermission::TicketsUpdate);
    }
}
