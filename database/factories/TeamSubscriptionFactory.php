<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Team;
use App\Models\TeamSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamSubscription>
 */
class TeamSubscriptionFactory extends Factory
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
            'plan_key' => 'legacy',
            'status' => SubscriptionStatus::Active->value,
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->startOfMonth()->addMonth(),
            'cancel_at_period_end' => false,
        ];
    }
}
