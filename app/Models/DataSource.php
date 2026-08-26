<?php

namespace App\Models;

use App\Enums\DataSourceStatus;
use Database\Factories\DataSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataSource extends Model
{
    /** @use HasFactory<DataSourceFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Scope the query to ready data sources.
     *
     * @param  Builder<DataSource>  $query
     * @return Builder<DataSource>
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', DataSourceStatus::Ready->value);
    }

    /**
     * Get the team that owns this data source.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get credentials for this data source.
     *
     * @return HasMany<DataSourceCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(DataSourceCredential::class);
    }

    /**
     * Get files uploaded for this data source.
     *
     * @return HasMany<SourceFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(SourceFile::class);
    }

    /**
     * Get API operations configured for this data source.
     *
     * @return HasMany<ApiOperation, $this>
     */
    public function apiOperations(): HasMany
    {
        return $this->hasMany(ApiOperation::class);
    }

    /**
     * Get datasets created from this data source.
     *
     * @return HasMany<Dataset, $this>
     */
    public function datasets(): HasMany
    {
        return $this->hasMany(Dataset::class);
    }

    /**
     * Get import or sync runs for this data source.
     *
     * @return HasMany<SourceRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
