<?php

namespace App\Http\Controllers;

use App\Enums\BotStatus;
use App\Enums\PlanLimit;
use App\Http\Requests\StoreBotRequest;
use App\Http\Requests\UpdateBotRequest;
use App\Models\Bot;
use App\Models\Dataset;
use App\Models\Team;
use App\Services\Billing\TeamEntitlementService;
use App\Services\Cards\BotWidgetAppearance;
use App\Services\Channels\ChannelConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BotController extends Controller
{
    public function __construct(
        private readonly BotWidgetAppearance $widgetAppearance,
        private readonly TeamEntitlementService $entitlements,
        private readonly ChannelConnectionService $channelConnections,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Bot::class);

        $team = $this->currentTeam($request);

        return Inertia::render('bots/index', [
            'bots' => $team->bots()
                ->select(['id', 'name', 'slug', 'status', 'created_at', 'updated_at'])
                ->latest()
                ->paginate(10)
                ->withQueryString()
                ->through(fn (Bot $bot): array => $this->summary($bot)),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        Gate::authorize('create', Bot::class);

        return Inertia::render('bots/create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBotRequest $request): RedirectResponse
    {
        $team = $this->currentTeam($request);
        $bot = DB::transaction(function () use ($team, $request): Bot {
            $team = Team::query()->lockForUpdate()->findOrFail($team->id);
            $this->entitlements->assertCanConsume($team, PlanLimit::Bots);

            $bot = $team->bots()->create([
                ...$request->validated(),
                'public_id' => (string) Str::uuid(),
                'status' => BotStatus::Draft->value,
            ]);

            $this->channelConnections->ensureWebsite($bot);

            return $bot;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot created.')]);

        return to_route('bots.show', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Team $currentTeam, Bot $bot): Response
    {
        Gate::authorize('view', $bot);

        return Inertia::render('bots/show', [
            'bot' => $this->details($bot),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Team $currentTeam, Bot $bot): Response
    {
        Gate::authorize('update', $bot);

        return Inertia::render('bots/edit', [
            'bot' => $this->details($bot),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBotRequest $request, Team $currentTeam, Bot $bot): RedirectResponse
    {
        Gate::authorize('update', $bot);

        $bot->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot updated.')]);

        return to_route('bots.show', [
            'current_team' => $this->currentTeam($request)->slug,
            'bot' => $bot,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Team $currentTeam, Bot $bot): RedirectResponse
    {
        Gate::authorize('delete', $bot);

        $bot->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot deleted.')]);

        return to_route('bots.index', [
            'current_team' => $this->currentTeam($request)->slug,
        ]);
    }

    /**
     * Get the authenticated user's current team.
     */
    private function currentTeam(Request $request): Team
    {
        $team = $request->user()?->currentTeam;

        abort_if(! $team, 403);

        return $team;
    }

    /**
     * Get the fields required by the index page.
     *
     * @return array<string, mixed>
     */
    private function summary(Bot $bot): array
    {
        return [
            'id' => $bot->id,
            'name' => $bot->name,
            'slug' => $bot->slug,
            'status' => $bot->status,
            'createdAt' => $bot->created_at?->toISOString(),
            'updatedAt' => $bot->updated_at?->toISOString(),
        ];
    }

    /**
     * Get the fields required by the edit and show pages.
     *
     * @return array<string, mixed>
     */
    private function details(Bot $bot): array
    {
        $activeDomains = $bot->domains()
            ->active()
            ->orderBy('domain')
            ->get(['id', 'domain']);
        $attachedDatasetCount = $bot->botDatasets()
            ->where('is_enabled', true)
            ->count();

        return [
            ...$this->summary($bot),
            'defaultLanguage' => $bot->default_language,
            'instructions' => $bot->instructions,
            'welcomeMessage' => $bot->welcome_message,
            'fallbackMessage' => $bot->fallback_message,
            'datasets' => $this->datasetAssignments($bot),
            'domains' => $activeDomains
                ->map(fn ($domain): array => [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                ])
                ->values()
                ->all(),
            'widget' => [
                'publicId' => $bot->public_id,
                'baseUrl' => rtrim((string) config('widget.base_url'), '/'),
                'datasetCount' => $attachedDatasetCount,
                'domainCount' => $activeDomains->count(),
                'snippet' => sprintf(
                    '<script src="%s/widget.js" data-bot="%s" data-position="%s" async></script>',
                    rtrim((string) config('widget.base_url'), '/'),
                    $bot->public_id,
                    $this->widgetAppearance->for($bot)['launcher_position'],
                ),
                'ready' => in_array($bot->status, ['ready', 'published'], true)
                    && $activeDomains->isNotEmpty(),
            ],
        ];
    }

    /**
     * Get current-team Dataset choices and their assignment state.
     *
     * @return list<array{id: int, name: string, slug: string, attached: bool}>
     */
    private function datasetAssignments(Bot $bot): array
    {
        $attachedDatasetIds = $bot->botDatasets()
            ->pluck('dataset_id')
            ->map(fn (mixed $datasetId): int => (int) $datasetId)
            ->flip();

        $assignments = $bot->team->datasets()
            ->select(['id', 'team_id', 'name', 'slug'])
            ->orderBy('name')
            ->get()
            ->map(fn (Dataset $dataset): array => [
                'id' => $dataset->id,
                'name' => $dataset->name,
                'slug' => $dataset->slug,
                'attached' => $attachedDatasetIds->has($dataset->id),
            ])
            ->values()
            ->all();

        return array_values($assignments);
    }
}
