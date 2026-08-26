<?php

namespace Database\Factories;

use App\Enums\WorkspaceProvisioningStatus;
use App\Models\User;
use App\Models\WorkspaceProvisioning;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceProvisioning>
 */
class WorkspaceProvisioningFactory extends Factory
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
            'team_name' => fake()->company(),
            'plan_key' => 'pro',
            'status' => WorkspaceProvisioningStatus::Pending,
            'expires_at' => now()->addHour(),
        ];
    }
}
