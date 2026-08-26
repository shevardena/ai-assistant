<?php

namespace Database\Factories;

use App\Enums\WorkflowStatus;
use App\Enums\WorkflowTriggerType;
use App\Models\Team;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'team_id' => Team::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'status' => WorkflowStatus::Draft->value,
            'trigger_type' => WorkflowTriggerType::LeadCaptured->value,
            'is_enabled' => false,
            'created_by_user_id' => User::factory(),
        ];
    }
}
