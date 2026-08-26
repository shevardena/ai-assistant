<?php

namespace App\Services;

use App\Enums\BotTestExpectationType;
use App\Enums\BotTestRunStatus;
use App\Enums\RuntimeMode;
use App\Models\Bot;
use App\Models\BotTestRun;
use App\Models\BotTestScenario;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiSearchOrchestrator;
use App\Services\Ai\AiSearchResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Throwable;

final class BotTestService
{
    private const MAX_EXPECTATIONS = 12;

    private const MAX_RUNS = 20;

    public function __construct(private readonly AiSearchOrchestrator $orchestrator) {}

    /**
     * @return array{scenarios: LengthAwarePaginator<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function index(Team $team, Bot $bot): array
    {
        $scenarios = BotTestScenario::query()
            ->where('team_id', $team->id)
            ->where('bot_id', $bot->id)
            ->with('latestRun')
            ->withCount('runs')
            ->latest()
            ->paginate(20)
            ->through(fn (BotTestScenario $scenario): array => $this->scenarioData($scenario));

        return [
            'scenarios' => $scenarios,
            'summary' => $this->summaryForBot($team, $bot),
        ];
    }

    /**
     * @return array{scenario: array<string, mixed>, runs: list<array<string, mixed>>}
     */
    public function show(Team $team, Bot $bot, BotTestScenario $scenario): array
    {
        $scenario = $this->scopeScenario($team, $bot, $scenario);

        return [
            'scenario' => $this->scenarioData($scenario->load('latestRun')),
            'runs' => array_values($scenario->runs()
                ->latest()
                ->limit(self::MAX_RUNS)
                ->get()
                ->map(fn (BotTestRun $run): array => $this->runData($run))
                ->values()
                ->all()),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Team $team, Bot $bot, User $user, array $attributes): BotTestScenario
    {
        return $bot->testScenarios()->create([
            ...$this->normalizedAttributes($attributes),
            'team_id' => $team->id,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Team $team, Bot $bot, BotTestScenario $scenario, array $attributes): BotTestScenario
    {
        $scenario = $this->scopeScenario($team, $bot, $scenario);
        $scenario->update([
            ...$this->normalizedAttributes($attributes),
        ]);

        return $scenario->fresh() ?? $scenario;
    }

    public function delete(Team $team, Bot $bot, BotTestScenario $scenario): void
    {
        $this->scopeScenario($team, $bot, $scenario)->delete();
    }

    public function run(Team $team, Bot $bot, User $user, BotTestScenario $scenario): BotTestRun
    {
        $scenario = $this->scopeScenario($team, $bot, $scenario);
        $startedAt = now();
        $startedClock = hrtime(true);
        $run = $scenario->runs()->create([
            'team_id' => $team->id,
            'bot_id' => $bot->id,
            'created_by' => $user->id,
            'status' => BotTestRunStatus::Error,
            'started_at' => $startedAt,
            'input_snapshot' => $scenario->input_message,
            'result_summary' => ['runtime_status' => 'started'],
        ]);

        try {
            $response = $this->orchestrator->run(
                bot: $bot,
                message: $scenario->input_message,
                mode: RuntimeMode::Test,
            );
            $trace = $this->trace($response);
            $expectations = $this->evaluateExpectations($this->scenarioExpectations($scenario), $trace);
            $passed = collect($expectations)->every(
                static fn (array $expectation): bool => $expectation['passed'] === true,
            );

            $run->update([
                'status' => $passed ? BotTestRunStatus::Passed : BotTestRunStatus::Failed,
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedClock),
                'response_text' => $response->answer,
                'result_summary' => [
                    ...$trace,
                    'runtime_status' => 'completed',
                    'expectation_results' => $expectations,
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $run->update([
                'status' => BotTestRunStatus::Error,
                'finished_at' => now(),
                'duration_ms' => $this->durationMs($startedClock),
                'result_summary' => [
                    'runtime_status' => 'error',
                    'message' => 'The test could not complete.',
                ],
            ]);
        }

        return $run->fresh() ?? $run;
    }

    /**
     * @return array{total: int, enabled: int, passing: int, failing: int, not_run: int}
     */
    public function summaryForBot(Team $team, Bot $bot): array
    {
        $scenarios = BotTestScenario::query()
            ->where('team_id', $team->id)
            ->where('bot_id', $bot->id)
            ->with('latestRun')
            ->get();

        return [
            'total' => $scenarios->count(),
            'enabled' => $scenarios->where('is_enabled', true)->count(),
            'passing' => $scenarios->filter(fn (BotTestScenario $scenario): bool => $scenario->latestRun?->status === BotTestRunStatus::Passed)->count(),
            'failing' => $scenarios->filter(fn (BotTestScenario $scenario): bool => in_array($scenario->latestRun?->status, [BotTestRunStatus::Failed, BotTestRunStatus::Error], true))->count(),
            'not_run' => $scenarios->filter(fn (BotTestScenario $scenario): bool => $scenario->latestRun === null)->count(),
        ];
    }

    /**
     * Evaluate only deterministic, server-defined expectation types.
     *
     * @param  list<array{type: string, value: string}>  $expectations
     * @param  array{tools_called: list<string>, blocks_returned: list<string>, action_proposals: list<string>, final_text: string}  $trace
     * @return list<array{type: string, expected: string, passed: bool, actual: string|list<string>}>
     */
    public function evaluateExpectations(array $expectations, array $trace): array
    {
        return array_map(function (array $expectation) use ($trace): array {
            $type = $expectation['type'];
            $expected = trim($expectation['value']);
            $expectedLower = Str::lower($expected);
            $tools = $trace['tools_called'];
            $blocks = $trace['blocks_returned'];
            $toolsLower = array_map(Str::lower(...), $tools);
            $blocksLower = array_map(Str::lower(...), $blocks);
            $actual = $trace['final_text'];
            $passed = false;

            switch ($type) {
                case BotTestExpectationType::ToolCalled->value:
                    $passed = in_array($expectedLower, $toolsLower, true);
                    $actual = $tools;
                    break;
                case BotTestExpectationType::ToolNotCalled->value:
                    $passed = ! in_array($expectedLower, $toolsLower, true);
                    $actual = $tools;
                    break;
                case BotTestExpectationType::ResponseContains->value:
                    $passed = Str::contains(Str::lower($trace['final_text']), $expectedLower);
                    break;
                case BotTestExpectationType::ResponseNotContains->value:
                    $passed = ! Str::contains(Str::lower($trace['final_text']), $expectedLower);
                    break;
                case BotTestExpectationType::BlockPresent->value:
                    $passed = in_array($expectedLower, $blocksLower, true);
                    $actual = $blocks;
                    break;
                case BotTestExpectationType::BlockAbsent->value:
                    $passed = ! in_array($expectedLower, $blocksLower, true);
                    $actual = $blocks;
                    break;
                case BotTestExpectationType::ActionStatus->value:
                    $actual = $trace['action_proposals'];
                    $passed = $expectedLower === ($actual === [] ? 'not_proposed' : 'proposed')
                        || in_array($expectedLower, array_map(Str::lower(...), $actual), true);
                    break;
            }

            return [
                'type' => $type,
                'expected' => $expected,
                'passed' => $passed,
                'actual' => $actual,
            ];
        }, array_slice($expectations, 0, self::MAX_EXPECTATIONS));
    }

    /**
     * @return array{tools_called: list<string>, blocks_returned: list<string>, action_proposals: list<string>, final_text: string}
     */
    private function trace(AiSearchResponse $response): array
    {
        return [
            'tools_called' => array_values(array_unique(array_map(
                static fn (array $outcome): string => $outcome['tool'],
                $response->toolOutcomes,
            ))),
            'blocks_returned' => array_values(array_unique(array_map(
                static fn (array $block): string => (string) ($block['type'] ?? ''),
                $response->blocks,
            ))),
            'action_proposals' => array_values(array_unique($response->actionProposals)),
            'final_text' => $response->answer,
        ];
    }

    private function scopeScenario(Team $team, Bot $bot, BotTestScenario $scenario): BotTestScenario
    {
        return BotTestScenario::query()
            ->whereKey($scenario->id)
            ->where('team_id', $team->id)
            ->where('bot_id', $bot->id)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function scenarioData(BotTestScenario $scenario): array
    {
        return [
            'publicId' => $scenario->public_id,
            'name' => $scenario->name,
            'inputMessage' => $scenario->input_message,
            'isEnabled' => (bool) $scenario->is_enabled,
            'expectations' => $scenario->expectations ?? [],
            'runCount' => (int) ($scenario->runs_count ?? $scenario->runs()->count()),
            'latestRun' => $scenario->latestRun instanceof BotTestRun ? $this->runData($scenario->latestRun) : null,
            'createdAt' => $scenario->created_at?->toISOString(),
            'updatedAt' => $scenario->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function runData(BotTestRun $run): array
    {
        return [
            'publicId' => $run->public_id,
            'status' => $run->status->value,
            'startedAt' => $run->started_at?->toISOString(),
            'finishedAt' => $run->finished_at?->toISOString(),
            'durationMs' => $run->duration_ms,
            'responseText' => $run->response_text,
            'resultSummary' => $run->result_summary ?? [],
        ];
    }

    private function durationMs(int $startedAt): int
    {
        return max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{name: string, input_message: string, is_enabled: bool, expectations: list<array{type: string, value: string}>}
     */
    private function normalizedAttributes(array $attributes): array
    {
        $expectations = $attributes['expectations'] ?? [];
        $normalizedExpectations = [];

        if (is_array($expectations)) {
            foreach ($expectations as $expectation) {
                if (is_array($expectation)
                    && is_string($expectation['type'] ?? null)
                    && is_string($expectation['value'] ?? null)) {
                    $normalizedExpectations[] = [
                        'type' => $expectation['type'],
                        'value' => $expectation['value'],
                    ];
                }
            }
        }

        return [
            'name' => is_string($attributes['name'] ?? null) ? $attributes['name'] : '',
            'input_message' => is_string($attributes['input_message'] ?? null) ? $attributes['input_message'] : '',
            'is_enabled' => (bool) ($attributes['is_enabled'] ?? true),
            'expectations' => $normalizedExpectations,
        ];
    }

    /** @return list<array{type: string, value: string}> */
    private function scenarioExpectations(BotTestScenario $scenario): array
    {
        $expectations = $scenario->getAttribute('expectations');

        if (! is_array($expectations)) {
            return [];
        }

        $normalized = [];

        foreach ($expectations as $expectation) {
            if (is_array($expectation)
                && is_string($expectation['type'] ?? null)
                && is_string($expectation['value'] ?? null)) {
                $normalized[] = [
                    'type' => $expectation['type'],
                    'value' => $expectation['value'],
                ];
            }
        }

        return $normalized;
    }
}
