<?php

namespace App\Http\Controllers;

use App\Enums\BotTestExpectationType;
use App\Http\Requests\StoreBotTestScenarioRequest;
use App\Http\Requests\UpdateBotTestScenarioRequest;
use App\Models\Bot;
use App\Models\BotTestScenario;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\BotToolRegistry;
use App\Services\BotTestService;
use App\Services\Conversations\Blocks\ConversationBlockType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BotTestController extends Controller
{
    public function __construct(
        private readonly BotTestService $tests,
        private readonly BotToolRegistry $tools,
    ) {}

    public function index(Team $currentTeam, Bot $bot): Response
    {
        Gate::authorize('viewTests', $bot);

        return Inertia::render('bots/tests/index', [
            'bot' => $this->botData($bot),
            ...$this->tests->index($currentTeam, $bot),
            ...$this->catalogData($bot),
        ]);
    }

    public function create(Team $currentTeam, Bot $bot): Response
    {
        Gate::authorize('manageTests', $bot);

        return Inertia::render('bots/tests/create', [
            'bot' => $this->botData($bot),
            ...$this->catalogData($bot),
        ]);
    }

    public function store(StoreBotTestScenarioRequest $request, Team $currentTeam, Bot $bot): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $scenario = $this->tests->create($currentTeam, $bot, $user, $request->validated());

        return to_route('bots.tests.show', [$currentTeam->slug, $bot, $scenario]);
    }

    public function show(Team $currentTeam, Bot $bot, BotTestScenario $testScenario): Response
    {
        Gate::authorize('viewTests', $bot);

        return Inertia::render('bots/tests/show', [
            'bot' => $this->botData($bot),
            ...$this->tests->show($currentTeam, $bot, $testScenario),
            ...$this->catalogData($bot),
        ]);
    }

    public function edit(Team $currentTeam, Bot $bot, BotTestScenario $testScenario): Response
    {
        Gate::authorize('manageTests', $bot);
        $scenario = $this->tests->show($currentTeam, $bot, $testScenario)['scenario'];

        return Inertia::render('bots/tests/edit', [
            'bot' => $this->botData($bot),
            'scenario' => $scenario,
            ...$this->catalogData($bot),
        ]);
    }

    public function update(UpdateBotTestScenarioRequest $request, Team $currentTeam, Bot $bot, BotTestScenario $testScenario): RedirectResponse
    {
        Gate::authorize('manageTests', $bot);
        $this->tests->update($currentTeam, $bot, $testScenario, $request->validated());

        return to_route('bots.tests.show', [$currentTeam->slug, $bot, $testScenario]);
    }

    public function destroy(Team $currentTeam, Bot $bot, BotTestScenario $testScenario): RedirectResponse
    {
        Gate::authorize('manageTests', $bot);
        $this->tests->delete($currentTeam, $bot, $testScenario);

        return to_route('bots.tests.index', [$currentTeam->slug, $bot]);
    }

    public function run(Request $request, Team $currentTeam, Bot $bot, BotTestScenario $testScenario): RedirectResponse
    {
        Gate::authorize('manageTests', $bot);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $run = $this->tests->run($currentTeam, $bot, $user, $testScenario);

        return to_route('bots.tests.show', [$currentTeam->slug, $bot, $testScenario])
            ->with('testRunStatus', $run->status->value);
    }

    /** @return array{id: int, name: string, slug: string} */
    private function botData(Bot $bot): array
    {
        return ['id' => $bot->id, 'name' => $bot->name, 'slug' => $bot->slug];
    }

    /** @return array{tools: list<string>, blocks: list<array{value: string, label: string}>} */
    private function catalogData(Bot $bot): array
    {
        return [
            'tools' => $this->tools->knownToolNames(),
            'blocks' => array_map(
                static fn (ConversationBlockType $block): array => [
                    'value' => $block->value,
                    'label' => str($block->value)->replace('_', ' ')->title()->toString(),
                ],
                ConversationBlockType::cases(),
            ),
            'expectationTypes' => array_map(
                static fn (BotTestExpectationType $type): array => [
                    'value' => $type->value,
                    'label' => str($type->value)->replace('_', ' ')->title()->toString(),
                ],
                BotTestExpectationType::cases(),
            ),
        ];
    }
}
