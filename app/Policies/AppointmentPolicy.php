<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Appointment;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCurrentTeamResources;

class AppointmentPolicy
{
    use AuthorizesCurrentTeamResources;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCurrentTeam($user, TeamPermission::AppointmentsView);
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $this->canAccessTeamResource($user, $appointment->team_id, TeamPermission::AppointmentsView);
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $this->canAccessTeamResource($user, $appointment->team_id, TeamPermission::AppointmentsUpdate);
    }
}
