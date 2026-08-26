<?php

namespace Database\Factories;

use App\Enums\BotStatus;
use App\Models\Bot;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Bot>
 */
class BotFactory extends Factory
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
            'public_id' => (string) Str::uuid(),
            'name' => fake()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'business_template' => null,
            'status' => BotStatus::Draft->value,
            'default_language' => 'en',
            'instructions' => fake()->sentence(),
            'welcome_message' => 'Hello! How can I help?',
            'fallback_message' => 'I could not find a good answer for that.',
            'settings' => [],
            'appearance' => [],
            'published_at' => null,
        ];
    }

    /**
     * Indicate that the bot is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BotStatus::Published->value,
            'published_at' => now(),
        ]);
    }
}
