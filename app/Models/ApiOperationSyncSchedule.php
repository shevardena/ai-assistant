<?php

namespace App\Models;

use App\Enums\ApiOperationSyncFrequency;
use App\Enums\ApiOperationSyncStrategy;
use Database\Factories\ApiOperationSyncScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiOperationSyncSchedule extends Model
{
    /** @use HasFactory<ApiOperationSyncScheduleFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<ApiOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ApiOperation::class, 'api_operation_id');
    }

    /** @return BelongsTo<Dataset, $this> */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'frequency' => ApiOperationSyncFrequency::class,
            'strategy' => ApiOperationSyncStrategy::class,
            'is_enabled' => 'boolean',
            'paused_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_started_at' => 'datetime',
            'last_completed_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'locked_until' => 'datetime',
            'checkpoint' => 'json',
            'configuration' => 'array',
            'consecutive_failures' => 'integer',
        ];
    }
}
