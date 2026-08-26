<?php

namespace App\Models;

use App\Enums\WorkflowConditionOperator;
use App\Enums\WorkflowConditionType;
use Database\Factories\WorkflowConditionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property WorkflowConditionType $type
 * @property WorkflowConditionOperator $operator
 * @property mixed $value
 */
#[Fillable(['workflow_id', 'type', 'operator', 'value', 'position'])]
class WorkflowCondition extends Model
{
    /** @use HasFactory<WorkflowConditionFactory> */
    use HasFactory;

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => WorkflowConditionType::class, 'operator' => WorkflowConditionOperator::class, 'value' => 'array', 'position' => 'integer'];
    }
}
