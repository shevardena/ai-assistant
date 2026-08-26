<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Bot;
use App\Models\Lead;
use App\Models\Team;
use App\Models\ToolRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'team_id' => Team::factory(),
            'bot_id' => Bot::factory(),
            'conversation_id' => null,
            'tool_run_id' => ToolRun::factory(),
            'status' => LeadStatus::New->value,
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'interest_summary' => fake()->sentence(),
            'source' => 'widget',
            'provider_reference' => 'LEAD-'.fake()->numberBetween(1000, 9999),
        ];
    }
}
