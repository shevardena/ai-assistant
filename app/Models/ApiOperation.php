<?php

namespace App\Models;

use App\Enums\ApiOperationMode;
use Database\Factories\ApiOperationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ApiOperation extends Model
{
    /** @use HasFactory<ApiOperationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Scope the query to enabled API operations.
     *
     * @param  Builder<ApiOperation>  $query
     * @return Builder<ApiOperation>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope the query to operations that are safe for read-only runtime use.
     *
     * @param  Builder<ApiOperation>  $query
     * @return Builder<ApiOperation>
     */
    public function scopeRead(Builder $query): Builder
    {
        return $query->where('execution_mode', ApiOperationMode::Read->value);
    }

    /**
     * Get the data source this API operation belongs to.
     *
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /**
     * Get bots that can call this API operation.
     *
     * @return BelongsToMany<Bot, $this>
     */
    public function bots(): BelongsToMany
    {
        return $this->belongsToMany(Bot::class, 'bot_api_operations')
            ->withPivot(['tool_name', 'is_enabled', 'settings'])
            ->withTimestamps();
    }

    /**
     * Get explicit bot attachment records for this API operation.
     *
     * @return HasMany<BotApiOperation, $this>
     */
    public function botApiOperations(): HasMany
    {
        return $this->hasMany(BotApiOperation::class);
    }

    /**
     * Get tool runs that executed this API operation.
     *
     * @return HasMany<ToolRun, $this>
     */
    public function toolRuns(): HasMany
    {
        return $this->hasMany(ToolRun::class);
    }

    /**
     * Get the schedule for this synchronized operation.
     *
     * @return HasOne<ApiOperationSyncSchedule, $this>
     */
    public function syncSchedule(): HasOne
    {
        return $this->hasOne(ApiOperationSyncSchedule::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_schema' => 'array',
            'request_mapping' => 'array',
            'response_mapping' => 'array',
            'headers' => 'array',
            'timeout_ms' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }
}
