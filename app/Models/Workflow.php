<?php

namespace App\Models;

use App\Enums\WorkflowStatus;
use App\Enums\WorkflowTriggerType;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property WorkflowStatus $status
 * @property WorkflowTriggerType $trigger_type
 */
#[Fillable(['public_id', 'team_id', 'name', 'description', 'status', 'trigger_type', 'is_enabled', 'created_by_user_id'])]
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Workflow $workflow): void {
            $workflow->public_id ??= (string) Str::uuid();
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<WorkflowCondition, $this> */
    public function conditions(): HasMany
    {
        return $this->hasMany(WorkflowCondition::class)->orderBy('position');
    }

    /** @return HasMany<WorkflowAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowAction::class)->orderBy('position');
    }

    /** @return HasMany<WorkflowRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'is_enabled' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => WorkflowStatus::class, 'trigger_type' => WorkflowTriggerType::class, 'is_enabled' => 'boolean'];
    }
}
