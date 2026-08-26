<?php

namespace App\Http\Controllers\Teams;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\UpdateTeamMemberRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TeamMemberController extends Controller
{
    /**
     * Update the specified team member's role.
     */
    public function update(
        UpdateTeamMemberRequest $request,
        Team $team,
        User $user,
        TeamAuthorizationService $authorization,
    ): RedirectResponse {
        Gate::authorize('updateMember', $team);
        abort_unless($authorization->canManageMember($request->user(), $team, $user), 403);

        $newRole = TeamRole::from($request->validated('role'));

        $team->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->update(['role' => $newRole]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Remove the specified team member.
     */
    public function destroy(
        Request $request,
        Team $team,
        User $user,
        TeamAuthorizationService $authorization,
    ): RedirectResponse {
        Gate::authorize('removeMember', $team);
        abort_unless($request->user() instanceof User, 401);
        abort_unless($authorization->canManageMember($request->user(), $team, $user), 403);

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentTeam($team)) {
            $user->switchTeam($user->personalTeam());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
