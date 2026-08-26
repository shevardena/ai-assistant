<?php

namespace App\Models;

use Database\Factories\BotTestScenarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property array<int, array{type: string, value: string}> $expectations
 * @property bool $is_enabled
 */
class BotTestScenario extends Model
{
    /** @use HasFactory<BotTestScenarioFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (BotTestScenario $scenario): void {
            $scenario->public_id ??= (string) Str::uuid();
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

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<BotTestRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(BotTestRun::class, 'scenario_id');
    }

    /** @return HasOne<BotTestRun, $this> */
    public function latestRun(): HasOne
    {
        return $this->hasOne(BotTestRun::class, 'scenario_id')->latestOfMany();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expectations' => 'array', 'is_enabled' => 'boolean'];
    }
}
