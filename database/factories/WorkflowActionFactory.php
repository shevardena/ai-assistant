<?php

namespace Database\Factories;

use App\Enums\WorkflowActionType;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowAction>
 */
class WorkflowActionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['workflow_id' => Workflow::factory(), 'type' => WorkflowActionType::SendInAppNotification->value, 'config' => ['permission' => 'leads.view', 'title' => 'Workflow notification', 'message' => 'A workflow ran.'], 'position' => 0];
    }
}
