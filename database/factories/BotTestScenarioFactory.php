<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\BotTestScenario;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotTestScenario>
 */
class BotTestScenarioFactory extends Factory
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
            'name' => fake()->sentence(3),
            'input_message' => fake()->sentence(),
            'is_enabled' => true,
            'expectations' => [],
        ];
    }
}
