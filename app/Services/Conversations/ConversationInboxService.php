<?php

namespace App\Services\Conversations;

use App\Enums\ConversationChannel;
use App\Enums\ConversationHandoffStatus;
use App\Enums\ConversationStatus;
use App\Enums\TeamPermission;
use App\Models\Appointment;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\ConversationTag;
use App\Models\Lead;
use App\Models\Message;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Models\WidgetVisitor;
use App\Services\Actions\ActionPresentationService;
use App\Services\Channels\ChannelRegistry;
use App\Services\Teams\TeamAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

final class ConversationInboxService
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly ActionPresentationService $presentation,
        private readonly TeamAuthorizationService $authorization,
        private readonly ChannelRegistry $channels,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function index(Team $team, array $filters, User $user): array
    {
        $bots = $team->bots()
            ->select(['id', 'name', 'slug'])
            ->orderBy('name')
            ->get();
        $selectedBot = $this->resolveBot($bots, $filters['bot'] ?? null);
        $range = $this->range($filters['range'] ?? 'all');
        $source = $this->source($filters['source'] ?? 'customer');
        $handoff = $this->handoff($filters['handoff'] ?? 'all');
        $channel = $this->channel($filters['channel'] ?? 'all');
        $status = $this->status($filters['status'] ?? 'all');
        $assignee = $this->assignee($team, $user, $filters['assignee'] ?? 'all');
        $tag = $this->tag($filters['tag'] ?? null);
        $search = trim((string) ($filters['search'] ?? ''));
        $queueQuery = $this->conversationQuery($team, $selectedBot, $range, $source, $search, 'all', $channel, $status, $assignee['key'], $assignee['userId'], $tag);
        $conversations = $this->conversationQuery($team, $selectedBot, $range, $source, $search, $handoff, $channel, $status, $assignee['key'], $assignee['userId'], $tag)
            ->with(['bot:id,name,slug', 'assignedTo:id,name', 'tags:id,public_id,name,slug'])
            ->withCount([
                'messages as message_count' => fn (Builder $query): Builder => $query
                    ->whereIn('role', ['user', 'assistant']),
            ])
            ->addSelect([
                'latest_user_preview' => Message::query()
                    ->select('content')
                    ->whereColumn('conversation_id', 'conversations.id')
                    ->where('role', 'user')
                    ->latest('id')
                    ->limit(1),
            ])
            ->orderByDesc('conversation_activity_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'filters' => [
                'bot' => $selectedBot?->slug,
                'range' => $range['key'],
                'source' => $source,
                'handoff' => $handoff,
                'channel' => $channel === null ? 'all' : $channel->value,
                'status' => $status === null ? 'all' : $status->value,
                'assignee' => $assignee['key'],
                'tag' => $tag,
                'search' => $search !== '' ? $search : null,
            ],
            'botOptions' => $bots->map(fn (Bot $bot): array => [
                'name' => $bot->name,
                'slug' => $bot->slug,
            ])->values()->all(),
            'assignableUsers' => $this->authorization->membersWithPermission($team, TeamPermission::ConversationsReply)
                ->map(fn (User $member): array => [
                    'reference' => (string) $member->getKey(),
                    'name' => $member->name,
                ])->values()->all(),
            'tagOptions' => $team->conversationTags()
                ->select(['public_id', 'name', 'slug'])
                ->orderBy('name')
                ->get()
                ->map(fn (ConversationTag $conversationTag): array => [
                    'reference' => $conversationTag->public_id,
                    'name' => $conversationTag->name,
                    'slug' => $conversationTag->slug,
                ])->values()->all(),
            'handoffSummary' => [
                'needsAttention' => (clone $queueQuery)->where('handoff_status', ConversationHandoffStatus::Requested->value)->count(),
                'humanActive' => (clone $queueQuery)->where('handoff_status', ConversationHandoffStatus::Human->value)->count(),
            ],
            'channelOptions' => array_map(
                static fn ($definition): array => [
                    'key' => $definition->key->value,
                    'name' => $definition->name,
                ],
                $this->channels->implemented(),
            ),
            'permissions' => [
                'canReply' => $this->authorization->can($user, $team, TeamPermission::ConversationsReply),
                'canManageHandoff' => $this->authorization->can($user, $team, TeamPermission::ConversationsHandoff),
                'canManage' => $this->authorization->can($user, $team, TeamPermission::ConversationsManage),
            ],
            'conversations' => $conversations->through(
                fn (Conversation $conversation): array => $this->summary($conversation),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Team $team, Conversation $conversation, User $user): array
    {
        $conversation = $this->conversationQuery($team)
            ->whereKey($conversation->getKey())
            ->with([
                'bot:id,name,slug',
                'customer:id,display_name,email,phone,status,owner_id',
                'customer.owner:id,name',
                'visitor:id,bot_id,external_customer_id,first_seen_at,last_seen_at',
                'handoffUser:id,name',
                'assignedTo:id,name',
                'tags:id,public_id,name,slug',
                'notes' => fn (Relation $query): Relation => $query->with('user:id,name')->latest('id'),
                'messages' => function (Relation $query): void {
                    $query->select([
                        'id',
                        'conversation_id',
                        'role',
                        'type',
                        'content',
                        'metadata',
                        'created_at',
                    ])
                        ->whereIn('role', ['user', 'assistant', 'system'])
                        ->orderBy('id');
                },
            ])
            ->firstOrFail();

        $visitor = $conversation->visitor;

        return [
            'assignableUsers' => $this->authorization->membersWithPermission($team, TeamPermission::ConversationsReply)
                ->map(fn (User $member): array => [
                    'reference' => (string) $member->getKey(),
                    'name' => $member->name,
                ])->values()->all(),
            'tagOptions' => $team->conversationTags()
                ->select(['public_id', 'name', 'slug'])
                ->orderBy('name')
                ->get()
                ->map(fn (ConversationTag $tag): array => [
                    'reference' => $tag->public_id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ])->values()->all(),
            'permissions' => [
                'canManage' => $this->authorization->can($user, $team, TeamPermission::ConversationsManage),
            ],
            'conversation' => [
                'reference' => $conversation->public_id,
                'channel' => $conversation->channel->value,
                'channelName' => $this->channels->find($conversation->channel)->name,
                'subject' => $conversation->channel === ConversationChannel::Email
                    ? data_get($conversation->metadata, 'email_subject')
                    : null,
                'sender' => $conversation->channel === ConversationChannel::Email
                    ? $this->maskedEmail($conversation->external_user_reference)
                    : null,
                'source' => $this->displaySource($conversation),
                'status' => (string) $conversation->status,
                'conversationStatus' => $conversation->conversation_status->value,
                'conversationStatusLabel' => $this->statusLabel($conversation->conversation_status),
                'assignee' => $conversation->assignedTo === null ? null : [
                    'reference' => (string) $conversation->assignedTo->getKey(),
                    'name' => $conversation->assignedTo->name,
                ],
                'tags' => $conversation->tags->map(fn (ConversationTag $tag): array => [
                    'reference' => $tag->public_id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ])->values()->all(),
                'notes' => $conversation->notes->map(fn ($note): array => [
                    'reference' => $note->public_id,
                    'body' => $note->body,
                    'author' => $note->user?->name,
                    'createdAt' => $this->isoDate($note->created_at),
                ])->values()->all(),
                'handoff' => [
                    'status' => $conversation->handoff_status->value,
                    'label' => $this->handoffLabel($conversation->handoff_status),
                    'reason' => $conversation->handoff_reason,
                    'requestedAt' => $this->isoDate($conversation->handoff_requested_at),
                    'startedAt' => $this->isoDate($conversation->handoff_started_at),
                    'takenOverBy' => $conversation->handoffUser?->name,
                    'canTakeOver' => $conversation->handoff_status === ConversationHandoffStatus::Requested
                        && $this->authorization->can($user, $team, TeamPermission::ConversationsHandoff),
                    'canReply' => $conversation->handoff_status === ConversationHandoffStatus::Human
                        && $this->authorization->can($user, $team, TeamPermission::ConversationsReply),
                    'canReturnToAi' => in_array($conversation->handoff_status, [
                        ConversationHandoffStatus::Requested,
                        ConversationHandoffStatus::Human,
                    ], true) && $this->authorization->can($user, $team, TeamPermission::ConversationsHandoff),
                ],
                'createdAt' => $this->isoDate($conversation->created_at),
                'lastMessageAt' => $this->isoDate($conversation->last_message_at),
                'bot' => [
                    'name' => $conversation->bot->name,
                    'slug' => $conversation->bot->slug,
                ],
                'messages' => $conversation->messages
                    ->map(fn (Message $message): array => [
                        'role' => $message->role,
                        'type' => $message->type,
                        'content' => (string) $message->content,
                        'createdAt' => $this->isoDate($message->created_at),
                        'source' => data_get($message->metadata, 'source') === 'human_agent'
                            ? 'human'
                            : ($message->role === 'system' ? 'system' : null),
                        'sender' => data_get($message->metadata, 'source') === 'human_agent'
                            ? 'Support Team'
                            : null,
                        'blocks' => $this->messageBlocks($message),
                    ])
                    ->values()
                    ->all(),
                'searchesCount' => $conversation->searchRuns()->count(),
                'actions' => $conversation->toolRuns()
                    ->where('team_id', $team->id)
                    ->select(['action_reference', 'tool_name', 'status'])
                    ->orderBy('id')
                    ->get()
                    ->map(fn (mixed $run): array => [
                        'actionReference' => (string) $run->action_reference,
                        'name' => $this->presentation->label((string) $run->tool_name),
                        'status' => $this->presentation->statusLabel($run->status),
                    ])
                    ->values()
                    ->all(),
                'visitor' => [
                    'label' => $visitor?->external_customer_id !== null
                        ? 'Known customer'
                        : 'Anonymous visitor',
                    'firstSeenAt' => $this->isoDate($visitor?->first_seen_at),
                    'lastSeenAt' => $this->isoDate($visitor?->last_seen_at),
                    'conversationCount' => $visitor === null
                        ? null
                        : Conversation::query()
                            ->where('visitor_id', $visitor->getKey())
                            ->whereIn('bot_id', $team->bots()->select('id'))
                            ->count(),
                ],
                'customer' => $this->customerProfile($team, $conversation, $visitor),
                'related' => $this->relatedRecords($team, $conversation),
            ],
        ];
    }

    /**
     * @param  Collection<int, Bot>  $bots
     */
    private function resolveBot(Collection $bots, mixed $filter): ?Bot
    {
        if (! is_string($filter) || $filter === '') {
            return null;
        }

        return $bots->first(fn (Bot $bot): bool => $bot->slug === $filter);
    }

    /**
     * @return array{key: string, start: CarbonImmutable|null, end: CarbonImmutable|null}
     */
    private function range(mixed $value): array
    {
        $key = is_string($value) && in_array($value, ['all', 'today', '7d', '30d'], true)
            ? $value
            : 'all';

        if ($key === 'all') {
            return ['key' => $key, 'start' => null, 'end' => null];
        }

        $now = CarbonImmutable::now();
        $days = ['today' => 1, '7d' => 7, '30d' => 30][$key];

        return [
            'key' => $key,
            'start' => $now->startOfDay()->subDays($days - 1),
            'end' => $now,
        ];
    }

    private function source(mixed $value): string
    {
        return is_string($value) && in_array($value, ['customer', 'preview', 'all'], true)
            ? $value
            : 'customer';
    }

    private function handoff(mixed $value): string
    {
        return is_string($value) && in_array($value, ['all', 'needs_attention', 'human'], true)
            ? $value
            : 'all';
    }

    private function status(mixed $value): ?ConversationStatus
    {
        if (! is_string($value) || $value === 'all') {
            return null;
        }

        return ConversationStatus::tryFrom($value);
    }

    /** @return array{key: string, userId: int|null} */
    private function assignee(Team $team, User $user, mixed $value): array
    {
        if (! is_string($value) || $value === '' || $value === 'all') {
            return ['key' => 'all', 'userId' => null];
        }

        if ($value === 'unassigned') {
            return ['key' => 'unassigned', 'userId' => null];
        }

        $memberId = $value === 'me' ? $user->getKey() : (ctype_digit($value) ? (int) $value : null);

        if ($memberId === null || ! $team->members()->whereKey($memberId)->exists()) {
            return ['key' => 'all', 'userId' => null];
        }

        return ['key' => $value === 'me' ? 'me' : (string) $memberId, 'userId' => (int) $memberId];
    }

    private function tag(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, 120, '');
    }

    private function channel(mixed $value): ?ConversationChannel
    {
        if (! is_string($value) || $value === 'all') {
            return null;
        }

        $channel = ConversationChannel::tryFrom($value);

        if ($channel === null || $this->channels->find($channel)?->implemented !== true) {
            return null;
        }

        return $channel;
    }

    /**
     * @param  array{key: string, start: CarbonImmutable|null, end: CarbonImmutable|null}  $range
     * @return Builder<Conversation>
     */
    private function conversationQuery(
        Team $team,
        ?Bot $bot = null,
        ?array $range = null,
        string $source = 'all',
        string $search = '',
        string $handoff = 'all',
        ?ConversationChannel $channel = null,
        ?ConversationStatus $status = null,
        string $assignee = 'all',
        ?int $assigneeUserId = null,
        ?string $tag = null,
    ): Builder {
        $query = Conversation::query()
            ->select('conversations.*')
            ->selectRaw('COALESCE(last_message_at, conversations.created_at) AS conversation_activity_at')
            ->whereIn('bot_id', $team->bots()->select('id'));

        if ($bot !== null) {
            $query->where('bot_id', $bot->getKey());
        }

        if ($channel !== null) {
            $query->where('channel', $channel->value);
        }

        if ($range !== null && $range['start'] !== null && $range['end'] !== null) {
            $query->whereBetween('conversations.created_at', [$range['start'], $range['end']]);
        }

        $this->applySource($query, $source);
        $this->applyHandoff($query, $handoff);
        $this->applyStatus($query, $status);
        $this->applyAssignee($query, $assignee, $assigneeUserId);
        $this->applyTag($query, $tag);
        $this->applySearch($query, $search);

        return $query;
    }

    /**
     * @param  Builder<Conversation>  $query
     */
    private function applyHandoff(Builder $query, string $handoff): void
    {
        if ($handoff === 'needs_attention') {
            $query->where('handoff_status', ConversationHandoffStatus::Requested->value);
        } elseif ($handoff === 'human') {
            $query->where('handoff_status', ConversationHandoffStatus::Human->value);
        }
    }

    /** @param Builder<Conversation> $query */
    private function applyStatus(Builder $query, ?ConversationStatus $status): void
    {
        if ($status !== null) {
            $query->where('conversation_status', $status->value);
        }
    }

    /** @param Builder<Conversation> $query */
    private function applyAssignee(Builder $query, string $assignee, ?int $assigneeUserId): void
    {
        if ($assignee === 'unassigned') {
            $query->whereNull('assigned_to_user_id');
        } elseif ($assigneeUserId !== null) {
            $query->where('assigned_to_user_id', $assigneeUserId);
        }
    }

    /** @param Builder<Conversation> $query */
    private function applyTag(Builder $query, ?string $tag): void
    {
        if ($tag !== null) {
            $query->whereHas('tags', fn (Builder $tagQuery): Builder => $tagQuery
                ->where('slug', $tag)
                ->orWhere('public_id', $tag));
        }
    }

    /**
     * @param  Builder<Conversation>  $query
     */
    private function applySource(Builder $query, string $source): void
    {
        if ($source === 'customer') {
            $query->where(function (Builder $query): void {
                $query->whereNotNull('visitor_id')
                    ->orWhereJsonContains('metadata->source', 'customer');
            });

            return;
        }

        if ($source === 'preview') {
            $query->whereNull('visitor_id')
                ->whereJsonContains('metadata->source', 'dashboard_preview');

            return;
        }

        $query->where(function (Builder $query): void {
            $query->whereNotNull('visitor_id')
                ->orWhereJsonContains('metadata->source', 'customer')
                ->orWhere(function (Builder $query): void {
                    $query->whereNull('visitor_id')
                        ->whereJsonContains('metadata->source', 'dashboard_preview');
                });
        });
    }

    /**
     * @param  Builder<Conversation>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = mb_strtolower(Str::limit($search, 120, ''));
        $pattern = '%'.$term.'%';

        $query->where(function (Builder $query) use ($pattern, $search): void {
            if (Str::isUuid($search)) {
                $query->where('public_id', $search)
                    ->orWhereHas('bot', fn (Builder $botQuery): Builder => $botQuery
                        ->whereRaw('LOWER(name) LIKE ?', [$pattern]))
                    ->orWhereHas('messages', fn (Builder $messageQuery): Builder => $messageQuery
                        ->whereIn('role', ['user', 'assistant', 'system'])
                        ->whereRaw('LOWER(content) LIKE ?', [$pattern]));

                return;
            }

            $query->whereHas('bot', fn (Builder $botQuery): Builder => $botQuery
                ->whereRaw('LOWER(name) LIKE ?', [$pattern]))
                ->orWhereHas('messages', fn (Builder $messageQuery): Builder => $messageQuery
                    ->whereIn('role', ['user', 'assistant', 'system'])
                    ->whereRaw('LOWER(content) LIKE ?', [$pattern]));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Conversation $conversation): array
    {
        return [
            'reference' => $conversation->public_id,
            'channel' => $conversation->channel->value,
            'subject' => $conversation->channel === ConversationChannel::Email
                ? data_get($conversation->metadata, 'email_subject')
                : null,
            'bot' => [
                'name' => $conversation->bot->name,
                'slug' => $conversation->bot->slug,
            ],
            'source' => $this->displaySource($conversation),
            'messageCount' => (int) $conversation->getAttribute('message_count'),
            'lastMessageAt' => $this->isoDate($conversation->last_message_at),
            'preview' => Str::limit(trim(strip_tags((string) ($conversation->latest_user_preview ?? ''))), 160),
            'conversationStatus' => $conversation->conversation_status->value,
            'conversationStatusLabel' => $this->statusLabel($conversation->conversation_status),
            'assignee' => $conversation->assignedTo === null ? null : [
                'reference' => (string) $conversation->assignedTo->getKey(),
                'name' => $conversation->assignedTo->name,
            ],
            'tags' => $conversation->tags->map(fn (ConversationTag $tag): array => [
                'reference' => $tag->public_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->values()->all(),
            'handoffStatus' => $conversation->handoff_status->value,
            'handoffLabel' => $this->handoffLabel($conversation->handoff_status),
        ];
    }

    private function handoffLabel(ConversationHandoffStatus $status): string
    {
        return match ($status) {
            ConversationHandoffStatus::Requested => 'Needs human',
            ConversationHandoffStatus::Human => 'Human active',
            ConversationHandoffStatus::Ai => 'AI',
        };
    }

    private function statusLabel(ConversationStatus $status): string
    {
        return match ($status) {
            ConversationStatus::Open => 'Open',
            ConversationStatus::Pending => 'Pending',
            ConversationStatus::Resolved => 'Resolved',
            ConversationStatus::Closed => 'Closed',
        };
    }

    private function displaySource(Conversation $conversation): string
    {
        return data_get($conversation->metadata, 'source') === 'dashboard_preview'
            ? 'preview'
            : 'widget';
    }

    private function isoDate(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }

    private function maskedEmail(?string $email): ?string
    {
        if (! is_string($email) || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', strtolower($email), 2);

        return ($local === '' ? '•' : Str::substr($local, 0, 1).'•••').'@'.$domain;
    }

    private function maskedPhone(?string $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        return $digits === '' ? null : '•••• '.Str::substr($digits, -4);
    }

    /** @return array<string, mixed> */
    private function customerProfile(Team $team, Conversation $conversation, ?WidgetVisitor $visitor): array
    {
        if ($conversation->customer !== null) {
            return [
                'channel' => $conversation->channel->value,
                'label' => $conversation->customer->name,
                'identity' => $conversation->customer->email ?? $conversation->customer->phone,
                'email' => $conversation->customer->email,
                'phone' => $conversation->customer->phone,
                'status' => $conversation->customer->status->value,
                'owner' => $conversation->customer->owner?->name,
                'profileUrl' => route('customers.show', [$team->slug, $conversation->customer->id]),
                'firstSeenAt' => $this->isoDate($conversation->customer->created_at),
                'lastSeenAt' => $this->isoDate($conversation->customer->last_activity_at),
                'conversationCount' => $conversation->customer->conversations()->count(),
            ];
        }

        $identity = match ($conversation->channel) {
            ConversationChannel::Email => $this->maskedEmail($conversation->external_user_reference),
            ConversationChannel::Sms, ConversationChannel::WhatsApp => $this->maskedPhone($conversation->external_user_reference),
            default => null,
        };
        $conversationCount = $visitor !== null
            ? Conversation::query()->where('visitor_id', $visitor->getKey())->whereIn('bot_id', $team->bots()->select('id'))->count()
            : Conversation::query()
                ->whereIn('bot_id', $team->bots()->select('id'))
                ->where('channel', $conversation->channel->value)
                ->where('external_user_reference', $conversation->external_user_reference)
                ->count();

        return [
            'channel' => $conversation->channel->value,
            'label' => $visitor?->external_customer_id !== null ? 'Known customer' : 'Customer',
            'identity' => $identity,
            'email' => null,
            'phone' => null,
            'status' => null,
            'owner' => null,
            'profileUrl' => null,
            'firstSeenAt' => $this->isoDate($visitor->first_seen_at ?? $conversation->created_at),
            'lastSeenAt' => $this->isoDate($visitor->last_seen_at ?? $conversation->last_message_at),
            'conversationCount' => $conversationCount,
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function relatedRecords(Team $team, Conversation $conversation): array
    {
        return [
            'leads' => array_values($conversation->leads()->select(['public_id', 'name', 'email', 'status'])->latest('id')->get()
                ->map(fn (Lead $lead): array => [
                    'reference' => (string) $lead->public_id,
                    'label' => (string) ($lead->name ?: $lead->email ?: 'Lead'),
                    'status' => (string) $lead->getRawOriginal('status'),
                    'url' => route('leads.show', [$team->slug, $lead->public_id]),
                ])->values()->all()),
            'appointments' => array_values($conversation->appointments()->select(['public_id', 'status', 'starts_at'])->latest('id')->get()
                ->map(fn (Appointment $appointment): array => [
                    'reference' => (string) $appointment->public_id,
                    'label' => 'Appointment',
                    'status' => (string) $appointment->getRawOriginal('status'),
                    'url' => route('appointments.show', [$team->slug, $appointment->public_id]),
                ])->values()->all()),
            'supportTickets' => array_values($conversation->supportTickets()->select(['public_id', 'subject', 'status'])->latest('id')->get()
                ->map(fn (SupportTicket $ticket): array => [
                    'reference' => (string) $ticket->public_id,
                    'label' => (string) ($ticket->subject ?: 'Support ticket'),
                    'status' => (string) $ticket->getRawOriginal('status'),
                    'url' => route('support-tickets.show', [$team->slug, $ticket->public_id]),
                ])->values()->all()),
            'actions' => array_values($conversation->toolRuns()->select(['action_reference', 'tool_name', 'status'])->latest('id')->get()
                ->map(fn (ToolRun $run): array => [
                    'reference' => (string) $run->action_reference,
                    'label' => $this->presentation->label((string) $run->tool_name),
                    'status' => $this->presentation->statusLabel($run->status),
                    'url' => route('actions.show', [$team->slug, $run->action_reference]),
                ])->values()->all()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messageBlocks(Message $message): array
    {
        return $this->conversationService->messageBlocks($message);
    }
}
