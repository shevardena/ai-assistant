<?php

namespace Database\Factories;

use App\Models\BillingAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingAccount>
 */
class BillingAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
        ];
    }

    public function consumed(): static
    {
        return $this->afterCreating(function (BillingAccount $account): void {
            $account->forceFill(['free_workspace_consumed_at' => now()])->save();
        });
    }
}
