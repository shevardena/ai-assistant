<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerActivity;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerActivity>
 */
class CustomerActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'customer_id' => Customer::factory(), 'actor_id' => null, 'type' => 'note_added', 'title' => 'Internal note added', 'description' => null, 'occurred_at' => now(), 'related_type' => null, 'related_id' => null, 'related_url' => null, 'metadata' => null];
    }
}
