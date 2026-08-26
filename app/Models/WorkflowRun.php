<?php

namespace App\Models;

use App\Enums\WorkflowRunStatus;
use App\Enums\WorkflowTriggerType;
use Database\Factories\WorkflowRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property WorkflowTriggerType $trigger_type
 * @property WorkflowRunStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['public_id', 'team_id', 'workflow_id', 'trigger_type', 'status', 'started_at', 'finished_at', 'duration_ms', 'trigger_reference', 'error_code', 'origin_workflow_run_id', 'depth'])]
class WorkflowRun extends Model
{
    /** @use HasFactory<WorkflowRunFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (WorkflowRun $run): void {
            $run->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return BelongsTo<WorkflowRun, $this> */
    public function originWorkflowRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_workflow_run_id', 'public_id');
    }

    /** @return HasMany<WorkflowRunAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowRunAction::class)->orderBy('position');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['trigger_type' => WorkflowTriggerType::class, 'status' => WorkflowRunStatus::class, 'started_at' => 'datetime', 'finished_at' => 'datetime', 'duration_ms' => 'integer', 'depth' => 'integer'];
    }
}
