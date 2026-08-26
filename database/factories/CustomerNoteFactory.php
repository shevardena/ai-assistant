<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerNote>
 */
class CustomerNoteFactory extends Factory
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
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
