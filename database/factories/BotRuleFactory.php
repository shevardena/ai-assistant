<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\BotRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotRule>
 */
class BotRuleFactory extends Factory
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
            'name' => fake()->words(3, true),
            'type' => 'instruction',
            'description' => fake()->sentence(),
            'config' => [
                'text' => fake()->sentence(),
            ],
            'priority' => 0,
            'is_enabled' => true,
        ];
    }
}
