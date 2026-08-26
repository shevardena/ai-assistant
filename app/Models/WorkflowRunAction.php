<?php

namespace App\Models;

use App\Enums\WorkflowActionRunStatus;
use App\Enums\WorkflowActionType;
use Database\Factories\WorkflowRunActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property WorkflowActionType $action_type
 * @property WorkflowActionRunStatus $status
 */
#[Fillable(['workflow_run_id', 'workflow_action_id', 'action_type', 'status', 'position', 'safe_summary', 'error_code', 'started_at', 'finished_at'])]
class WorkflowRunAction extends Model
{
    /** @use HasFactory<WorkflowRunActionFactory> */
    use HasFactory;

    /** @return BelongsTo<WorkflowRun, $this> */
    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    /** @return BelongsTo<WorkflowAction, $this> */
    public function workflowAction(): BelongsTo
    {
        return $this->belongsTo(WorkflowAction::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['action_type' => WorkflowActionType::class, 'status' => WorkflowActionRunStatus::class, 'started_at' => 'datetime', 'finished_at' => 'datetime', 'position' => 'integer'];
    }
}
