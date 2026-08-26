<?php

namespace App\Http\Controllers;

use App\Enums\BotStatus;
use App\Enums\PlanLimit;
use App\Http\Requests\StoreBotFromTemplateRequest;
use App\Models\Bot;
use App\Models\Team;
use App\Services\Billing\TeamEntitlementService;
use App\Services\Channels\ChannelConnectionService;
use App\Services\Onboarding\BusinessTemplateDefinition;
use App\Services\Onboarding\BusinessTemplateRegistry;
use App\Services\Onboarding\BusinessTemplateSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class OnboardingController extends Controller
{
    public function __construct(
        private readonly BusinessTemplateRegistry $templates,
        private readonly BusinessTemplateSetupService $setup,
        private readonly TeamEntitlementService $entitlements,
        private readonly ChannelConnectionService $channelConnections,
    ) {}

    public function index(Team $currentTeam): Response
    {
        Gate::authorize('viewAny', Bot::class);

        return Inertia::render('onboarding/index', [
            'templates' => array_map(
                static fn (BusinessTemplateDefinition $template): array => $template->toArray(),
                $this->templates->all(),
            ),
            'hasBots' => $currentTeam->bots()->exists(),
            'scratchUrl' => route('bots.create', ['current_team' => $currentTeam->slug]),
        ]);
    }

    public function template(Team $currentTeam, string $template): Response
    {
        Gate::authorize('viewAny', Bot::class);
        $definition = $this->templates->find($template);

        abort_if($definition === null, 404);

        return Inertia::render('onboarding/template', [
            'template' => $definition->toArray(),
            'applyUrl' => route('onboarding.apply', ['current_team' => $currentTeam->slug]),
            'backUrl' => route('onboarding.index', ['current_team' => $currentTeam->slug]),
        ]);
    }

    public function apply(StoreBotFromTemplateRequest $request, Team $currentTeam): RedirectResponse
    {
        $template = $this->templates->find($request->templateKey());
        abort_if($template === null, 404);

        $name = $request->botName() ?? $template->recommendedBotName;
        $bot = DB::transaction(function () use ($currentTeam, $name, $template): Bot {
            $team = Team::query()->lockForUpdate()->findOrFail($currentTeam->id);
            $this->entitlements->assertCanConsume($team, PlanLimit::Bots, 'botName');

            $bot = $team->bots()->create([
                'public_id' => (string) Str::uuid(),
                'name' => $name,
                'slug' => $this->uniqueSlug($team, $name),
                'business_template' => $template->key,
                'status' => BotStatus::Draft->value,
                'default_language' => 'en',
                'settings' => [],
                'appearance' => [],
            ]);

            $this->channelConnections->ensureWebsite($bot);

            return $bot;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot draft created from template.')]);

        return to_route('bots.setup.show', [
            'current_team' => $currentTeam->slug,
            'bot' => $bot,
        ]);
    }

    public function setup(Team $currentTeam, Bot $bot): Response
    {
        Gate::authorize('view', $bot);

        $template = $this->templates->find((string) $bot->business_template)
            ?? $this->customTemplate();

        return Inertia::render('bots/setup', [
            'bot' => [
                'id' => $bot->id,
                'name' => $bot->name,
                'slug' => $bot->slug,
                'status' => $bot->status,
                'businessTemplate' => $bot->business_template,
            ],
            'template' => $template->toArray(),
            'checklist' => $this->setup->forBot($bot, $template),
        ]);
    }

    private function uniqueSlug(Team $team, string $name): string
    {
        $base = Str::slug($name) ?: 'assistant';
        $slug = $base;
        $suffix = 2;

        while ($team->bots()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function customTemplate(): BusinessTemplateDefinition
    {
        return new BusinessTemplateDefinition(
            key: 'custom',
            version: 2,
            nameKey: 'templates.custom.name',
            descriptionKey: 'templates.custom.description',
            bestForKey: 'templates.custom.best_for',
            recommendedBotName: 'Assistant',
            outcomeKeys: [],
            requirements: [],
            workflowRecommendations: [],
            channelRecommendations: [],
            suggestedTestKeys: [],
            onboardingSteps: [
                ['key' => 'data', 'labelKey' => 'templates.setup.steps.data.title', 'descriptionKey' => 'templates.setup.steps.data.description'],
                ['key' => 'capabilities', 'labelKey' => 'templates.setup.steps.capabilities.title', 'descriptionKey' => 'templates.setup.steps.capabilities.description'],
                ['key' => 'tests', 'labelKey' => 'templates.setup.steps.tests.title', 'descriptionKey' => 'templates.setup.steps.tests.description'],
                ['key' => 'design', 'labelKey' => 'templates.setup.steps.design.title', 'descriptionKey' => 'templates.setup.steps.design.description'],
                ['key' => 'domain', 'labelKey' => 'templates.setup.steps.domain.title', 'descriptionKey' => 'templates.setup.steps.domain.description'],
                ['key' => 'embed', 'labelKey' => 'templates.setup.steps.embed.title', 'descriptionKey' => 'templates.setup.steps.embed.description'],
            ],
        );
    }
}
