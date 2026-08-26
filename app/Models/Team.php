<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Bot> $bots
 * @property-read Collection<int, Dataset> $datasets
 * @property-read Collection<int, DataSource> $dataSources
 * @property-read Collection<int, UsageEvent> $usageEvents
 * @property-read Collection<int, ToolRun> $toolRuns
 * @property-read Collection<int, Conversation> $conversations
 * @property-read Collection<int, Appointment> $appointments
 * @property-read Collection<int, SupportTicket> $supportTickets
 * @property-read Collection<int, ConversationTag> $conversationTags
 * @property-read TeamSubscription|null $subscription
 * @property-read Collection<int, Customer> $customers
 * @property-read Collection<int, Pipeline> $pipelines
 * @property-read Collection<int, Deal> $deals
 * @property-read Collection<int, Task> $tasks
 */
#[Fillable(['name', 'slug', 'is_personal'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }
        });
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get bots owned by this team.
     *
     * @return HasMany<Bot, $this>
     */
    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }

    /**
     * Get leads captured by this team.
     *
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** @return HasMany<Pipeline, $this> */
    public function pipelines(): HasMany
    {
        return $this->hasMany(Pipeline::class);
    }

    /** @return HasMany<PipelineStage, $this> */
    public function pipelineStages(): HasMany
    {
        return $this->hasMany(PipelineStage::class);
    }

    /** @return HasMany<Deal, $this> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<CustomerIdentity, $this> */
    public function customerIdentities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    /** @return HasMany<CustomerCustomField, $this> */
    public function customerCustomFields(): HasMany
    {
        return $this->hasMany(CustomerCustomField::class);
    }

    /** @return HasMany<CustomerFact, $this> */
    public function customerFacts(): HasMany
    {
        return $this->hasMany(CustomerFact::class);
    }

    /** @return HasMany<CustomerSegment, $this> */
    public function customerSegments(): HasMany
    {
        return $this->hasMany(CustomerSegment::class);
    }

    /** @return HasMany<CustomerActivity, $this> */
    public function customerActivities(): HasMany
    {
        return $this->hasMany(CustomerActivity::class);
    }

    /** @return HasMany<CustomerTag, $this> */
    public function customerTags(): HasMany
    {
        return $this->hasMany(CustomerTag::class);
    }

    /**
     * Get conversations belonging to the team's Bots.
     *
     * @return HasManyThrough<Conversation, Bot, $this>
     */
    public function conversations(): HasManyThrough
    {
        return $this->hasManyThrough(Conversation::class, Bot::class);
    }

    /**
     * Get channel connections owned by this team.
     *
     * @return HasMany<ChannelConnection, $this>
     */
    public function channelConnections(): HasMany
    {
        return $this->hasMany(ChannelConnection::class);
    }

    /**
     * Get data sources owned by this team.
     *
     * @return HasMany<DataSource, $this>
     */
    public function dataSources(): HasMany
    {
        return $this->hasMany(DataSource::class);
    }

    /**
     * Get datasets owned by this team.
     *
     * @return HasMany<Dataset, $this>
     */
    public function datasets(): HasMany
    {
        return $this->hasMany(Dataset::class);
    }

    /**
     * Get usage events owned by this team.
     *
     * @return HasMany<UsageEvent, $this>
     */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    /**
     * Get tool runs owned by this team.
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

    /** @return HasMany<ConversationTag, $this> */
    public function conversationTags(): HasMany
    {
        return $this->hasMany(ConversationTag::class);
    }

    /**
     * Get the team's current subscription.
     *
     * @return HasOne<TeamSubscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(TeamSubscription::class);
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

    /** @return HasMany<Workflow, $this> */
    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    /** @return HasMany<WorkflowRun, $this> */
    public function workflowRuns(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
