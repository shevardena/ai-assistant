<?php

namespace Database\Factories;

use App\Enums\BotTestRunStatus;
use App\Models\Bot;
use App\Models\BotTestRun;
use App\Models\BotTestScenario;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotTestRun>
 */
class BotTestRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'bot_id' => Bot::factory(),
            'scenario_id' => BotTestScenario::factory(),
            'status' => BotTestRunStatus::Passed,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 1,
            'input_snapshot' => 'Test input',
            'response_text' => 'Test response',
            'result_summary' => [],
        ];
    }
}
