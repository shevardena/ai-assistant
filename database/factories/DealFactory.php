<?php

namespace Database\Factories;

use App\Enums\DealStatus;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
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
            'customer_id' => Customer::factory(),
            'lead_id' => null,
            'pipeline_id' => Pipeline::factory(),
            'stage_id' => PipelineStage::factory(),
            'owner_user_id' => null,
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'value_amount' => fake()->randomFloat(2, 100, 100000),
            'currency' => 'USD',
            'probability' => 50,
            'expected_close_date' => now()->addDays(30)->toDateString(),
            'status' => DealStatus::Open,
            'won_at' => null,
            'lost_at' => null,
            'lost_reason' => null,
            'source' => 'manual',
            'last_activity_at' => now(),
        ];
    }
}
