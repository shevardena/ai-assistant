<?php

namespace Database\Factories;

use App\Enums\ApiOperationMode;
use App\Enums\RuntimeMode;
use App\Enums\ToolRunStatus;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\Team;
use App\Models\ToolRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ToolRun>
 */
class ToolRunFactory extends Factory
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
            'visitor_id' => null,
            'conversation_id' => null,
            'message_id' => null,
            'api_operation_id' => ApiOperation::factory(),
            'action_reference' => (string) Str::uuid(),
            'tool_name' => fake()->unique()->slug(2),
            'execution_mode' => ApiOperationMode::Write->value,
            'runtime_mode' => RuntimeMode::Normal->value,
            'status' => ToolRunStatus::PendingConfirmation->value,
            'idempotency_key' => (string) Str::uuid(),
            'safe_arguments' => [],
            'safe_result' => null,
            'error_code' => null,
            'duration_ms' => null,
            'confirmed_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
        ];
    }
}
