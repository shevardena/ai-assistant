<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\Team;
use App\Services\Ai\BotCapabilityService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BotCapabilityController extends Controller
{
    public function __construct(private readonly BotCapabilityService $capabilityService) {}

    /**
     * Display the customer-facing capability summary for a Bot.
     */
    public function show(Team $currentTeam, Bot $bot): Response
    {
        Gate::authorize('view', $bot);

        return Inertia::render('bots/capabilities', [
            'bot' => [
                'id' => $bot->id,
                'name' => $bot->name,
                'slug' => $bot->slug,
            ],
            'groups' => $this->capabilityService->forBot($bot),
        ]);
    }
}
