<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardIndexRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Dashboard\TeamDashboardService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(DashboardIndexRequest $request, Team $currentTeam, TeamDashboardService $dashboard): Response
    {
        abort_unless($request->user() instanceof User, 401);
        $email = strtolower($request->user()->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        return Inertia::render('dashboard', [
            'pendingInvitations' => $pendingInvitations,
            ...$dashboard->build($currentTeam, $request->user(), (string) ($request->validated('range') ?? '7d')),
        ]);
    }
}
