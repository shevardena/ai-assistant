<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerFact;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerFact>
 */
class CustomerFactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'customer_id' => Customer::factory(), 'key' => fake()->unique()->slug(2), 'value' => fake()->sentence(), 'value_type' => 'text', 'source' => 'manual', 'confidence' => null, 'last_confirmed_at' => now(), 'created_by_user_id' => null];
    }
}
