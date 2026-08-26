<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerIdentity>
 */
class CustomerIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'customer_id' => Customer::factory(), 'type' => 'email', 'value' => fake()->safeEmail(), 'normalized_value' => fake()->unique()->safeEmail(), 'provider' => null, 'provider_external_id' => null, 'is_primary' => true, 'is_verified' => false];
    }
}
