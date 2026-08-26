<?php

namespace App\Models;

use App\Enums\WorkflowActionType;
use Database\Factories\WorkflowActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property WorkflowActionType $type
 * @property array<string, mixed>|null $config
 */
#[Fillable(['workflow_id', 'type', 'config', 'position'])]
class WorkflowAction extends Model
{
    /** @use HasFactory<WorkflowActionFactory> */
    use HasFactory;

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => WorkflowActionType::class, 'config' => 'array', 'position' => 'integer'];
    }
}
