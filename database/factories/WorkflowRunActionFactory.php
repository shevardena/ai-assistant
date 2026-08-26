<?php

namespace Database\Factories;

use App\Enums\WorkflowActionRunStatus;
use App\Enums\WorkflowActionType;
use App\Models\WorkflowAction;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowRunAction>
 */
class WorkflowRunActionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['workflow_run_id' => WorkflowRun::factory(), 'workflow_action_id' => WorkflowAction::factory(), 'action_type' => WorkflowActionType::SendInAppNotification->value, 'status' => WorkflowActionRunStatus::Completed->value, 'position' => 0, 'safe_summary' => 'Sent an in-app notification.', 'error_code' => null, 'started_at' => now(), 'finished_at' => now()];
    }
}
