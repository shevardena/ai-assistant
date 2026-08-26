<?php

namespace App\Models;

use App\Enums\ConversationChannel;
use App\Enums\ConversationHandoffStatus;
use App\Enums\ConversationStatus;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property ConversationHandoffStatus $handoff_status
 * @property ConversationChannel $channel
 * @property ConversationStatus $conversation_status
 * @property-read Collection<int, ConversationTag> $tags
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'handoff_status' => ConversationHandoffStatus::Ai->value,
        'channel' => ConversationChannel::Website->value,
    ];

    /**
     * Get the bot this conversation belongs to.
     *
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<ChannelConnection, $this> */
    public function channelConnection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class);
    }

    /**
     * Get the visitor for this conversation.
     *
     * @return BelongsTo<WidgetVisitor, $this>
     */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(WidgetVisitor::class, 'visitor_id');
    }

    /**
     * Get the Team member who took over this conversation.
     *
     * @return BelongsTo<User, $this>
     */
    public function handoffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handoff_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return HasMany<ConversationNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(ConversationNote::class);
    }

    /** @return BelongsToMany<ConversationTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ConversationTag::class, 'conversation_tag');
    }

    /**
     * Get the structured state for this conversation.
     *
     * @return HasOne<ConversationState, $this>
     */
    public function state(): HasOne
    {
        return $this->hasOne(ConversationState::class);
    }

    /**
     * Get messages in this conversation.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get search runs from this conversation.
     *
     * @return HasMany<SearchRun, $this>
     */
    public function searchRuns(): HasMany
    {
        return $this->hasMany(SearchRun::class);
    }

    /**
     * Get usage events from this conversation.
     *
     * @return HasMany<UsageEvent, $this>
     */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    /**
     * Get write-action runs proposed in this conversation.
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

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<SupportTicket, $this> */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /**
     * Get leads captured in this conversation.
     *
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ConversationChannel::class,
            'conversation_status' => ConversationStatus::class,
            'metadata' => 'array',
            'last_message_at' => 'datetime',
            'handoff_status' => ConversationHandoffStatus::class,
            'handoff_requested_at' => 'datetime',
            'handoff_started_at' => 'datetime',
        ];
    }
}
