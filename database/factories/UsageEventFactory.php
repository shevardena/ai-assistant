<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\UsageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageEvent>
 */
class UsageEventFactory extends Factory
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
            'bot_id' => null,
            'conversation_id' => null,
            'type' => 'message',
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'input_tokens' => fake()->numberBetween(0, 1000),
            'output_tokens' => fake()->numberBetween(0, 1000),
            'cost_usd' => fake()->randomFloat(8, 0, 1),
            'quantity' => 1,
            'metadata' => [],
        ];
    }
}
