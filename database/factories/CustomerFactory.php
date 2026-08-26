<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();
        $email = fake()->unique()->safeEmail();
        $phone = fake()->phoneNumber();

        return [
            'team_id' => Team::factory(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => $firstName.' '.$lastName,
            'email' => $email,
            'normalized_email' => strtolower($email),
            'phone' => $phone,
            'normalized_phone' => preg_replace('/\D+/', '', $phone),
            'company' => fake()->company(),
            'status' => CustomerStatus::New->value,
            'source' => 'manual',
            'last_activity_at' => now(),
        ];
    }
}
