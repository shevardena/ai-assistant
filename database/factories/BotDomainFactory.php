<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\BotDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotDomain>
 */
class BotDomainFactory extends Factory
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
            'domain' => fake()->unique()->domainName(),
            'is_active' => true,
            'verified_at' => null,
        ];
    }
}
