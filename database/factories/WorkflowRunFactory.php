<?php

namespace Database\Factories;

use App\Enums\WorkflowRunStatus;
use App\Enums\WorkflowTriggerType;
use App\Models\Team;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkflowRun>
 */
class WorkflowRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['public_id' => (string) Str::uuid(), 'team_id' => Team::factory(), 'workflow_id' => Workflow::factory(), 'trigger_type' => WorkflowTriggerType::LeadCaptured->value, 'status' => WorkflowRunStatus::Completed->value, 'started_at' => now(), 'finished_at' => now(), 'duration_ms' => 1, 'trigger_reference' => 'lead:'.Str::uuid(), 'error_code' => null, 'origin_workflow_run_id' => null, 'depth' => 0];
    }
}
