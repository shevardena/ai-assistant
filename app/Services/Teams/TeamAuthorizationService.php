<?php

namespace App\Services\Teams;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class TeamAuthorizationService
{
    public function role(User $user, Team $team): ?TeamRole
    {
        return $user->teamRole($team);
    }

    public function can(User $user, Team $team, TeamPermission $permission): bool
    {
        if (! $user->belongsToTeam($team)) {
            return false;
        }

        return $this->role($user, $team)?->hasPermission($permission) ?? false;
    }

    /**
     * Return a safe frontend permission map for a team member.
     *
     * @return array<string, bool>
     */
    public function permissionMap(User $user, Team $team): array
    {
        if (! $user->belongsToTeam($team)) {
            return [];
        }

        return collect($this->role($user, $team)?->permissions() ?? [])
            ->mapWithKeys(fn (TeamPermission $permission): array => [$permission->value => true])
            ->all();
    }

    public function canManageMember(User $actor, Team $team, User $member): bool
    {
        if (! $this->can($actor, $team, TeamPermission::TeamMembersManage)) {
            return false;
        }

        if (! $member->belongsToTeam($team) || $team->owner()?->is($member)) {
            return false;
        }

        return true;
    }

    /**
     * Resolve recipients from current membership records and the central role map.
     *
     * @return Collection<int, User>
     */
    public function membersWithPermission(Team $team, TeamPermission $permission): Collection
    {
        return $team->memberships()
            ->with('user')
            ->get()
            ->filter(fn (Membership $membership): bool => $membership->role->hasPermission($permission))
            ->map(fn (Membership $membership): User => $membership->user)
            ->values();
    }
}
