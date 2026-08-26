<?php

namespace App\Models;

use App\Enums\BotTestRunStatus;
use Database\Factories\BotTestRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property BotTestRunStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property array<string, mixed> $result_summary
 */
class BotTestRun extends Model
{
    /** @use HasFactory<BotTestRunFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (BotTestRun $run): void {
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

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /** @return BelongsTo<BotTestScenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(BotTestScenario::class, 'scenario_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BotTestRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'result_summary' => 'array',
        ];
    }
}
