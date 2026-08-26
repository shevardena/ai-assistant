<?php

namespace App\Models;

use App\Enums\BotStatus;
use Database\Factories\BotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bot extends Model
{
    /** @use HasFactory<BotFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'business_template' => null,
    ];

    /**
     * Scope the query to published bots.
     *
     * @param  Builder<Bot>  $query
     * @return Builder<Bot>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', BotStatus::Published->value);
    }

    /**
     * Scope the query to bots that may serve the public widget.
     *
     * The ready status is the current runtime state. Published is retained
     * for bots created before the readiness lifecycle was introduced.
     *
     * @param  Builder<Bot>  $query
     * @return Builder<Bot>
     */
    public function scopePubliclyAvailable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BotStatus::Ready->value,
            BotStatus::Published->value,
        ]);
    }

    /**
     * Get the team that owns this bot.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the domains configured for this bot.
     *
     * @return HasMany<BotDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(BotDomain::class);
    }

    /**
     * Get leads captured by this Bot.
     *
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Get the datasets attached to this bot.
     *
     * @return BelongsToMany<Dataset, $this>
     */
    public function datasets(): BelongsToMany
    {
        return $this->belongsToMany(Dataset::class, 'bot_datasets')
            ->withPivot(['priority', 'is_enabled', 'settings'])
            ->withTimestamps();
    }

    /**
     * Get explicit dataset attachment records for this bot.
     *
     * @return HasMany<BotDataset, $this>
     */
    public function botDatasets(): HasMany
    {
        return $this->hasMany(BotDataset::class);
    }

    /**
     * Get API operations exposed to this bot.
     *
     * @return BelongsToMany<ApiOperation, $this>
     */
    public function apiOperations(): BelongsToMany
    {
        return $this->belongsToMany(ApiOperation::class, 'bot_api_operations')
            ->withPivot(['tool_name', 'is_enabled', 'settings'])
            ->withTimestamps();
    }

    /**
     * Get explicit API operation attachment records for this bot.
     *
     * @return HasMany<BotApiOperation, $this>
     */
    public function botApiOperations(): HasMany
    {
        return $this->hasMany(BotApiOperation::class);
    }

    /**
     * Get rules configured for this bot.
     *
     * @return HasMany<BotRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(BotRule::class);
    }

    /**
     * Get card templates configured for this bot.
     *
     * @return HasMany<BotCardTemplate, $this>
     */
    public function cardTemplates(): HasMany
    {
        return $this->hasMany(BotCardTemplate::class);
    }

    /**
     * Get visitors seen by this bot.
     *
     * @return HasMany<WidgetVisitor, $this>
     */
    public function visitors(): HasMany
    {
        return $this->hasMany(WidgetVisitor::class);
    }

    /**
     * Get conversations for this bot.
     *
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Get channel connections for this Bot.
     *
     * @return HasMany<ChannelConnection, $this>
     */
    public function channelConnections(): HasMany
    {
        return $this->hasMany(ChannelConnection::class);
    }

    /**
     * Get search runs for this bot.
     *
     * @return HasMany<SearchRun, $this>
     */
    public function searchRuns(): HasMany
    {
        return $this->hasMany(SearchRun::class);
    }

    /**
     * Get usage events for this bot.
     *
     * @return HasMany<UsageEvent, $this>
     */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    /**
     * Get tool runs created for this bot.
     *
     * @return HasMany<ToolRun, $this>
     */
    public function toolRuns(): HasMany
    {
        return $this->hasMany(ToolRun::class);
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** @return HasMany<SupportTicket, $this> */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /** @return HasMany<BotTestScenario, $this> */
    public function testScenarios(): HasMany
    {
        return $this->hasMany(BotTestScenario::class);
    }

    /** @return HasMany<BotTestRun, $this> */
    public function testRuns(): HasMany
    {
        return $this->hasMany(BotTestRun::class);
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
            'appearance' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
