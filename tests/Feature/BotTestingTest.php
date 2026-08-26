<?php

use App\Enums\BotTestRunStatus;
use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\BotTestScenario;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\SearchRun;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Ai\Contracts\AiClient;
use App\Services\BotTestService;

function botTestingContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    return [$user, $team, $bot];
}

function botTestingRoute(Team $team, Bot $bot): string
{
    return route('bots.tests.index', [$team->slug, $bot->id]);
}

test('team members can create, inspect, update, and delete saved Bot tests', function () {
    [$user, $team, $bot] = botTestingContext();
    $this->actingAs($user);

    $this->get(botTestingRoute($team, $bot))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('bots/tests/index')
            ->where('bot.id', $bot->id)
            ->where('summary.total', 0));

    $this->post(route('bots.tests.store', [$team->slug, $bot->id]), [
        'name' => 'Availability check',
        'input_message' => 'Is the blue laptop in stock?',
        'is_enabled' => true,
        'expectations' => [['type' => 'response_contains', 'value' => 'available']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $scenario = BotTestScenario::query()->firstOrFail();

    expect($scenario->team_id)->toBe($team->id)
        ->and($scenario->bot_id)->toBe($bot->id)
        ->and($scenario->expectations)->toBe([['type' => 'response_contains', 'value' => 'available']]);

    $this->patch(route('bots.tests.update', [$team->slug, $bot->id, $scenario]), [
        'name' => 'Updated availability check',
        'input_message' => 'Is the red laptop in stock?',
        'is_enabled' => false,
        'expectations' => [['type' => 'tool_not_called', 'value' => 'search_catalog']],
    ])->assertRedirect();

    expect($scenario->fresh()->name)->toBe('Updated availability check')
        ->and($scenario->fresh()->is_enabled)->toBeFalse();

    $this->delete(route('bots.tests.destroy', [$team->slug, $bot->id, $scenario]))
        ->assertRedirect(route('bots.tests.index', [$team->slug, $bot->id]));

    expect(BotTestScenario::query()->find($scenario->id))->toBeNull();
});

test('saved tests cannot cross team or Bot boundaries', function () {
    [$user, $team, $bot] = botTestingContext();
    $otherTeam = Team::factory()->create();
    $otherBot = Bot::factory()->create(['team_id' => $otherTeam->id]);
    $scenario = BotTestScenario::factory()->create([
        'team_id' => $otherTeam->id,
        'bot_id' => $otherBot->id,
    ]);
    $this->actingAs($user);

    $this->get(botTestingRoute($team, $otherBot))->assertForbidden();
    $this->get(route('bots.tests.show', [$team->slug, $bot->id, $scenario]))->assertNotFound();
    $this->post(route('bots.tests.run', [$team->slug, $bot->id, $scenario]))->assertNotFound();
});

test('a saved test evaluates runtime output and stays out of production telemetry', function () {
    [$user, $team, $bot] = botTestingContext();
    $scenario = BotTestScenario::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'expectations' => [['type' => 'response_contains', 'value' => 'available']],
    ]);
    $this->actingAs($user);

    app()->instance(AiClient::class, new class implements AiClient
    {
        public function createResponse(array $payload): array
        {
            return ['output' => [], 'output_text' => 'The product is available.', 'usage' => null];
        }
    });

    $this->post(route('bots.tests.run', [$team->slug, $bot->id, $scenario]))
        ->assertRedirect();

    $run = $scenario->runs()->firstOrFail();

    expect($run->status)->toBe(BotTestRunStatus::Passed)
        ->and($run->result_summary['runtime_status'])->toBe('completed')
        ->and(Conversation::query()->count())->toBe(0)
        ->and(SearchRun::query()->count())->toBe(0)
        ->and(ToolRun::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0);
});

test('saved test runs distinguish failed expectations from runtime errors', function () {
    [$user, $team, $bot] = botTestingContext();
    $failedScenario = BotTestScenario::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'expectations' => [['type' => 'response_contains', 'value' => 'missing phrase']],
    ]);
    $errorScenario = BotTestScenario::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'expectations' => [],
    ]);
    $this->actingAs($user);

    $client = new class implements AiClient
    {
        public bool $shouldFail = false;

        public function createResponse(array $payload): array
        {
            if ($this->shouldFail) {
                throw new RuntimeException('simulated provider failure');
            }

            return ['output' => [], 'output_text' => 'A different answer.', 'usage' => null];
        }
    };
    app()->instance(AiClient::class, $client);

    $this->post(route('bots.tests.run', [$team->slug, $bot->id, $failedScenario]));

    $client->shouldFail = true;

    $this->post(route('bots.tests.run', [$team->slug, $bot->id, $errorScenario]));

    expect($failedScenario->runs()->firstOrFail()->status)->toBe(BotTestRunStatus::Failed)
        ->and($errorScenario->runs()->firstOrFail()->status)->toBe(BotTestRunStatus::Error);
});

test('expectation evaluator uses telemetry, trusted block identifiers, and action proposal state', function () {
    $results = app(BotTestService::class)->evaluateExpectations([
        ['type' => 'tool_called', 'value' => 'search_catalog'],
        ['type' => 'tool_not_called', 'value' => 'capture_lead'],
        ['type' => 'response_contains', 'value' => 'AVAILABLE'],
        ['type' => 'response_not_contains', 'value' => 'secret'],
        ['type' => 'block_present', 'value' => 'product_cards'],
        ['type' => 'block_absent', 'value' => 'confirmation'],
        ['type' => 'action_status', 'value' => 'proposed'],
    ], [
        'tools_called' => ['search_catalog'],
        'blocks_returned' => ['product_cards'],
        'action_proposals' => ['capture_lead'],
        'final_text' => 'Available now.',
    ]);

    expect(collect($results)->every(fn (array $result): bool => $result['passed']))->toBeTrue();
});
