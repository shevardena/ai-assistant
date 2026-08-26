<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
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
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => TaskStatus::Open,
            'priority' => TaskPriority::Normal,
            'assigned_user_id' => null,
            'created_by_user_id' => null,
            'due_at' => null,
            'completed_at' => null,
            'source' => 'manual',
            'last_activity_at' => now(),
        ];
    }
}
