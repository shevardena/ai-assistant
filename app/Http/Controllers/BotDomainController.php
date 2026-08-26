<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBotDomainRequest;
use App\Models\Bot;
use App\Models\BotDomain;
use App\Models\Team;
use App\Services\Widget\WidgetDomainNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BotDomainController extends Controller
{
    public function __construct(private readonly WidgetDomainNormalizer $normalizer) {}

    public function store(StoreBotDomainRequest $request, Team $currentTeam, Bot $bot): RedirectResponse
    {
        Gate::authorize('update', $bot);

        $domain = $this->normalizedDomain($request);

        if ($bot->domains()->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['domain' => 'This domain is already allowed.']);
        }

        $bot->domains()->create(['domain' => $domain]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Allowed domain added.')]);

        return to_route('bots.show', ['current_team' => $currentTeam, 'bot' => $bot]);
    }

    public function destroy(Team $currentTeam, Bot $bot, BotDomain $domain): RedirectResponse
    {
        Gate::authorize('update', $bot);

        $bot->domains()->whereKey($domain->id)->firstOrFail()->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Allowed domain removed.')]);

        return to_route('bots.show', [
            'current_team' => $currentTeam,
            'bot' => $bot,
        ]);
    }

    private function normalizedDomain(StoreBotDomainRequest $request): string
    {
        try {
            return $this->normalizer->normalize((string) $request->validated('domain'));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['domain' => $exception->getMessage()]);
        }
    }
}
