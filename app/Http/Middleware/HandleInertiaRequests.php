<?php

namespace App\Http\Middleware;

use App\Services\Billing\BillingAccountService;
use App\Services\Billing\PlanRegistry;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => fn (): string => app()->getLocale(),
            'supportedLocales' => $this->supportedLocales(),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'currentTeamPermissions' => fn () => $user?->currentTeam
                ? $user->toTeamPermissions($user->currentTeam)->abilities
                : [],
            'currentTeamUnreadNotificationsCount' => fn () => $user?->currentTeam
                ? $user->notifications()
                    ->where('team_id', $user->currentTeam->getKey())
                    ->whereNull('read_at')
                    ->count()
                : 0,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            'workspaceBilling' => fn () => $user ? [
                'free_available' => app(BillingAccountService::class)->canCreateFreeTeam(app(BillingAccountService::class)->forUser($user)),
                'plans' => array_map(fn ($plan): array => app(PlanRegistry::class)->toClientArray($plan), app(PlanRegistry::class)->publicPlans()),
            ] : null,
        ];
    }

    /**
     * @return list<array{code: string, name: string, nativeName: string}>
     */
    private function supportedLocales(): array
    {
        $supportedLocales = config('locales.supported', []);

        if (! is_array($supportedLocales)) {
            return [];
        }

        $locales = [];

        foreach ($supportedLocales as $code => $metadata) {
            if (! is_string($code) || ! is_array($metadata)) {
                continue;
            }

            $locales[] = [
                'code' => $code,
                'name' => (string) ($metadata['label'] ?? $code),
                'nativeName' => (string) ($metadata['native_label'] ?? $code),
            ];
        }

        return $locales;
    }
}
