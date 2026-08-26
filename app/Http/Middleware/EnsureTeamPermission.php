<?php

namespace App\Http\Middleware;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamPermission
{
    public function __construct(private readonly TeamAuthorizationService $authorization) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $team = $request->route('current_team') ?? $request->route('team');

        if (is_string($team)) {
            $team = Team::query()->where('slug', $team)->first();
        }

        $ability = TeamPermission::tryFrom($permission);

        abort_unless(
            $user instanceof User
            && $team instanceof Team
            && $ability !== null
            && $this->authorization->can($user, $team, $ability),
            403,
        );

        return $next($request);
    }
}
