<?php

namespace App\Models;

use App\Enums\ToolRunStatus;
use Database\Factories\ToolRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property ToolRunStatus $status
 * @property string $runtime_mode
 * @property Carbon|null $completed_at
 */
class ToolRun extends Model
{
    /** @use HasFactory<ToolRunFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * @return BelongsTo<WidgetVisitor, $this>
     */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(WidgetVisitor::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<ApiOperation, $this>
     */
    public function apiOperation(): BelongsTo
    {
        return $this->belongsTo(ApiOperation::class);
    }

    /**
     * Get the lead created by this completed capture action.
     *
     * @return HasOne<Lead, $this>
     */
    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class);
    }

    /** @return HasOne<Appointment, $this> */
    public function appointment(): HasOne
    {
        return $this->hasOne(Appointment::class);
    }

    /** @return HasOne<SupportTicket, $this> */
    public function supportTicket(): HasOne
    {
        return $this->hasOne(SupportTicket::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ToolRunStatus::class,
            'safe_arguments' => 'array',
            'safe_result' => 'array',
            'duration_ms' => 'integer',
            'confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
