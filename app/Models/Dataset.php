<?php

namespace App\Models;

use App\Enums\DatasetStatus;
use Database\Factories\DatasetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dataset extends Model
{
    /** @use HasFactory<DatasetFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Scope the query to ready datasets.
     *
     * @param  Builder<Dataset>  $query
     * @return Builder<Dataset>
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', DatasetStatus::Ready->value);
    }

    /**
     * Scope the query to datasets intended for knowledge or FAQ retrieval.
     *
     * @param  Builder<Dataset>  $query
     * @return Builder<Dataset>
     */
    public function scopeKnowledge(Builder $query): Builder
    {
        return $query->whereIn('entity_type', ['faq', 'knowledge']);
    }

    /**
     * Scope the query to datasets intended for catalog or product retrieval.
     *
     * @param  Builder<Dataset>  $query
     * @return Builder<Dataset>
     */
    public function scopeCatalog(Builder $query): Builder
    {
        return $query->whereIn('entity_type', self::catalogEntityTypes());
    }

    /**
     * Return entity types that can provide catalog records.
     *
     * @return list<string>
     */
    public static function catalogEntityTypes(): array
    {
        return ['catalog', 'product', 'car', 'hotel', 'property', 'generic'];
    }

    /**
     * Get the team that owns this dataset.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the source that created this dataset.
     *
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /**
     * Get fields configured for this dataset.
     *
     * @return HasMany<DatasetField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(DatasetField::class)->orderBy('position');
    }

    /**
     * Get records in this dataset.
     *
     * @return HasMany<DatasetRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(DatasetRecord::class);
    }

    /**
     * Get bots attached to this dataset.
     *
     * @return BelongsToMany<Bot, $this>
     */
    public function bots(): BelongsToMany
    {
        return $this->belongsToMany(Bot::class, 'bot_datasets')
            ->withPivot(['priority', 'is_enabled', 'settings'])
            ->withTimestamps();
    }

    /**
     * Get explicit bot attachment records for this dataset.
     *
     * @return HasMany<BotDataset, $this>
     */
    public function botDatasets(): HasMany
    {
        return $this->hasMany(BotDataset::class);
    }

    /**
     * Get card templates for this dataset.
     *
     * @return HasMany<BotCardTemplate, $this>
     */
    public function cardTemplates(): HasMany
    {
        return $this->hasMany(BotCardTemplate::class);
    }

    /**
     * Get source import or sync runs for this dataset.
     *
     * @return HasMany<SourceRun, $this>
     */
    public function sourceRuns(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }

    /**
     * Get search runs against this dataset.
     *
     * @return HasMany<SearchRun, $this>
     */
    public function searchRuns(): HasMany
    {
        return $this->hasMany(SearchRun::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'schema_version' => 'integer',
            'last_indexed_at' => 'datetime',
        ];
    }
}
