<?php

namespace Database\Factories;

use App\Models\CustomerSegment;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerSegment>
 */
class CustomerSegmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'name' => fake()->unique()->words(2, true), 'description' => fake()->sentence(), 'filter_definition' => ['filters' => [['field' => 'status', 'operator' => 'equals', 'value' => 'active']]], 'created_by_user_id' => null];
    }
}
