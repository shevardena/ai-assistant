<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Bot;
use App\Models\Team;
use App\Models\ToolRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['public_id' => (string) Str::uuid(), 'team_id' => Team::factory(), 'bot_id' => Bot::factory(), 'conversation_id' => null, 'tool_run_id' => ToolRun::factory(), 'status' => AppointmentStatus::Scheduled->value, 'starts_at' => now()->addDay(), 'ends_at' => null, 'timezone' => 'UTC', 'customer_name' => fake()->name(), 'customer_email' => fake()->safeEmail(), 'customer_phone' => fake()->phoneNumber(), 'provider_reference' => 'APPT-'.fake()->numberBetween(1000, 9999)];
    }
}
