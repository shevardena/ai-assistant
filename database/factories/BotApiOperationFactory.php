<?php

namespace Database\Factories;

use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotApiOperation>
 */
class BotApiOperationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'api_operation_id' => ApiOperation::factory(),
            'tool_name' => fake()->unique()->slug(2),
            'is_enabled' => true,
            'settings' => [],
        ];
    }
}
