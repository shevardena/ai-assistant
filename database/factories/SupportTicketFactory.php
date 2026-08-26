<?php

namespace Database\Factories;

use App\Enums\SupportTicketStatus;
use App\Models\Bot;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\ToolRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['public_id' => (string) Str::uuid(), 'team_id' => Team::factory(), 'bot_id' => Bot::factory(), 'conversation_id' => null, 'tool_run_id' => ToolRun::factory(), 'status' => SupportTicketStatus::Open->value, 'subject' => fake()->sentence(4), 'summary' => fake()->paragraph(), 'customer_name' => fake()->name(), 'customer_email' => fake()->safeEmail(), 'provider_reference' => 'TICKET-'.fake()->numberBetween(1000, 9999), 'external_url' => null];
    }
}
