<?php

namespace App\Services\KnowledgeGaps;

use App\Enums\KnowledgeGapReason;
use App\Enums\KnowledgeGapStatus;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\KnowledgeGap;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiSearchResponse;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class KnowledgeGapService
{
    private const PER_PAGE = 25;

    private const MAX_OCCURRENCES_PER_GROUP = 10;

    private const GROUP_STATUS_SQL = "CASE WHEN SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) > 0 THEN 'open' WHEN SUM(CASE WHEN status = 'ignored' THEN 1 ELSE 0 END) > 0 THEN 'ignored' ELSE 'resolved' END";

    /**
     * @var list<string>
     */
    private const REASONS = ['no_knowledge_match', 'no_results'];

    /**
     * Record at most one conservative gap occurrence for a completed user turn.
     */
    public function recordFromResponse(
        Bot $bot,
        Conversation $conversation,
        Message $message,
        AiSearchResponse $response,
    ): ?KnowledgeGap {
        $reason = $this->reasonFor($response);
        $question = $this->normalizeQuestion((string) $message->content);

        if ($reason === null
            || $message->role !== 'user'
            || $question === ''
            || data_get($conversation->metadata, 'source') === 'dashboard_preview'
            || (int) $conversation->bot_id !== (int) $bot->id
            || (int) $bot->team_id !== (int) $conversation->bot()->value('team_id')) {
            return null;
        }

        $hash = hash('sha256', $question);
        $groupReference = hash('sha256', $bot->id.'|'.$hash);
        $status = KnowledgeGap::query()
            ->where('team_id', $bot->team_id)
            ->where('group_reference', $groupReference)
            ->where('status', KnowledgeGapStatus::Ignored->value)
            ->exists()
            ? KnowledgeGapStatus::Ignored
            : KnowledgeGapStatus::Open;

        $attributes = [
            'team_id' => $bot->team_id,
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'resolved_by' => null,
            'reason' => $reason->value,
            'normalized_question' => $question,
            'normalized_hash' => $hash,
            'group_reference' => $groupReference,
            'status' => $status->value,
            'resolved_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $inserted = KnowledgeGap::query()->insertOrIgnore([$attributes]);

        if ($inserted === 0) {
            return null;
        }

        return KnowledgeGap::query()
            ->where('message_id', $message->id)
            ->first();
    }

    /**
     * Normalize only presentation-insensitive differences for grouping.
     */
    public function normalizeQuestion(string $question): string
    {
        $normalized = mb_strtolower(trim($question));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = rtrim($normalized, " \t\n\r\0\x0B.!?…");

        return mb_substr($normalized, 0, 500);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function index(Team $team, array $filters): array
    {
        $bots = $team->bots()
            ->select(['id', 'name', 'slug'])
            ->orderBy('name')
            ->get();
        $selectedBot = $this->resolveBot($bots, $filters['bot'] ?? null);
        $range = $this->range($filters['range'] ?? '30d');
        $status = $this->status($filters['status'] ?? 'open');
        $reason = $this->reason($filters['reason'] ?? null);
        $search = trim((string) ($filters['search'] ?? ''));
        $summaryBase = $this->baseQuery($team, $selectedBot, $range, $reason, $search);
        $groupedSummary = $this->groupedQuery($summaryBase);
        $groups = $this->groupedQuery($summaryBase);

        if ($status !== 'all') {
            $groups->havingRaw(self::GROUP_STATUS_SQL.' = ?', [$status]);
        }

        $groups = $groups
            ->with(['bot:id,name,slug'])
            ->orderByDesc('last_asked_at')
            ->orderByDesc('group_reference')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
        $occurrences = $this->occurrences($team, $groups->getCollection());

        return [
            'filters' => [
                'bot' => $selectedBot?->slug,
                'range' => $range['key'],
                'status' => $status,
                'reason' => $reason,
                'search' => $search !== '' ? $search : null,
            ],
            'botOptions' => $bots->map(fn (Bot $bot): array => [
                'name' => $bot->name,
                'slug' => $bot->slug,
            ])->values()->all(),
            'summary' => [
                'openGaps' => $this->subqueryCount($groupedSummary, 'group_status', KnowledgeGapStatus::Open->value),
                'affectedConversations' => (clone $summaryBase)
                    ->distinct()
                    ->count('conversation_id'),
                'repeatedQuestions' => $this->subqueryCountGreaterThan($groupedSummary, 'occurrence_count', 1),
            ],
            'gaps' => $groups->through(
                fn (KnowledgeGap $gap): array => $this->groupPayload($gap, $occurrences),
            ),
        ];
    }

    public function updateStatus(
        Team $team,
        string $groupReference,
        KnowledgeGapStatus $status,
        User $user,
    ): void {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $groupReference)) {
            throw (new ModelNotFoundException)->setModel(KnowledgeGap::class);
        }

        $query = KnowledgeGap::query()
            ->where('team_id', $team->id)
            ->where('group_reference', $groupReference);

        if (! $query->exists()) {
            throw (new ModelNotFoundException)->setModel(KnowledgeGap::class);
        }

        $query->update([
            'status' => $status->value,
            'resolved_at' => $status === KnowledgeGapStatus::Resolved ? now() : null,
            'resolved_by' => $status === KnowledgeGapStatus::Resolved ? $user->id : null,
        ]);
    }

    private function reasonFor(AiSearchResponse $response): ?KnowledgeGapReason
    {
        $candidate = null;

        foreach ($response->toolOutcomes as $outcome) {
            $kind = $outcome['outcome'];

            if (in_array($kind, ['knowledge_success', 'catalog_success', 'partial_success', 'failed', 'non_knowledge_failure'], true)) {
                return null;
            }

            if ($kind === 'no_knowledge_match') {
                $candidate = KnowledgeGapReason::NoKnowledgeMatch;
            } elseif ($kind === 'no_results' && $candidate === null) {
                $candidate = KnowledgeGapReason::NoResults;
            }
        }

        return $candidate;
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
     * @return array{key: string, start: CarbonImmutable, end: CarbonImmutable}
     */
    private function range(mixed $value): array
    {
        $key = is_string($value) && in_array($value, ['today', '7d', '30d', '90d'], true)
            ? $value
            : '30d';
        $now = CarbonImmutable::now();
        $days = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90][$key];

        return [
            'key' => $key,
            'start' => $now->startOfDay()->subDays($days - 1),
            'end' => $now,
        ];
    }

    private function status(mixed $value): string
    {
        return is_string($value) && in_array($value, ['open', 'resolved', 'ignored', 'all'], true)
            ? $value
            : KnowledgeGapStatus::Open->value;
    }

    private function reason(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::REASONS, true)
            ? $value
            : null;
    }

    /**
     * @param  array{key: string, start: CarbonImmutable, end: CarbonImmutable}  $range
     * @return Builder<KnowledgeGap>
     */
    private function baseQuery(
        Team $team,
        ?Bot $bot,
        array $range,
        ?string $reason,
        string $search,
    ): Builder {
        $query = KnowledgeGap::query()
            ->where('team_id', $team->id)
            ->whereBetween('created_at', [$range['start'], $range['end']]);

        if ($bot !== null) {
            $query->where('bot_id', $bot->id);
        }

        if ($reason !== null) {
            $query->where('reason', $reason);
        }

        if ($search !== '') {
            $query->whereRaw('LOWER(normalized_question) LIKE ?', ['%'.mb_strtolower(Str::limit($search, 120, '')).'%']);
        }

        return $query;
    }

    /**
     * @param  Builder<KnowledgeGap>  $base
     * @return Builder<KnowledgeGap>
     */
    private function groupedQuery(Builder $base): Builder
    {
        return (clone $base)
            ->select(['bot_id', 'normalized_hash', 'group_reference'])
            ->selectRaw('MIN(normalized_question) AS normalized_question')
            ->selectRaw('MIN(reason) AS reason')
            ->selectRaw('COUNT(*) AS occurrence_count')
            ->selectRaw('COUNT(DISTINCT conversation_id) AS conversation_count')
            ->selectRaw('MAX(created_at) AS last_asked_at')
            ->selectRaw(self::GROUP_STATUS_SQL.' AS group_status')
            ->groupBy(['bot_id', 'normalized_hash', 'group_reference']);
    }

    /**
     * @param  Builder<KnowledgeGap>  $query
     */
    private function subqueryCount(Builder $query, string $column, string $value): int
    {
        return (int) KnowledgeGap::query()
            ->fromSub($query, 'knowledge_gap_groups')
            ->where($column, $value)
            ->count();
    }

    /**
     * @param  Builder<KnowledgeGap>  $query
     */
    private function subqueryCountGreaterThan(Builder $query, string $column, int $value): int
    {
        return (int) KnowledgeGap::query()
            ->fromSub($query, 'knowledge_gap_groups')
            ->where($column, '>', $value)
            ->count();
    }

    /**
     * @param  Collection<int, KnowledgeGap>  $groups
     * @return array<string, Collection<int, KnowledgeGap>>
     */
    private function occurrences(Team $team, Collection $groups): array
    {
        $references = $groups->pluck('group_reference')->filter()->values()->all();

        if ($references === []) {
            return [];
        }

        return KnowledgeGap::query()
            ->where('team_id', $team->id)
            ->whereIn('group_reference', $references)
            ->with([
                'bot:id,name,slug',
                'conversation:id,public_id',
            ])
            ->select([
                'id',
                'bot_id',
                'conversation_id',
                'group_reference',
                'normalized_question',
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('group_reference')
            ->map(fn (Collection $items): Collection => $items->take(self::MAX_OCCURRENCES_PER_GROUP))
            ->all();
    }

    /**
     * @param  array<string, Collection<int, KnowledgeGap>>  $occurrences
     * @return array<string, mixed>
     */
    private function groupPayload(KnowledgeGap $group, array $occurrences): array
    {
        $reference = (string) $group->getAttribute('group_reference');
        $items = $occurrences[$reference] ?? collect();

        return [
            'groupReference' => $reference,
            'question' => (string) $group->getAttribute('normalized_question'),
            'reason' => $group->getAttribute('reason') instanceof KnowledgeGapReason
                ? $group->getAttribute('reason')->value
                : (string) $group->getAttribute('reason'),
            'status' => (string) $group->getAttribute('group_status'),
            'occurrenceCount' => (int) $group->getAttribute('occurrence_count'),
            'conversationCount' => (int) $group->getAttribute('conversation_count'),
            'lastAskedAt' => $this->isoDate($group->getAttribute('last_asked_at')),
            'bot' => [
                'name' => $group->bot->name,
                'slug' => $group->bot->slug,
            ],
            'occurrences' => $items->map(fn (KnowledgeGap $gap): array => [
                'question' => (string) $gap->normalized_question,
                'askedAt' => $this->isoDate($gap->created_at),
                'conversationReference' => $gap->conversation->public_id,
            ])->values()->all(),
            'occurrencesCapped' => (int) $group->getAttribute('occurrence_count') > self::MAX_OCCURRENCES_PER_GROUP,
        ];
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_string($value) ? CarbonImmutable::parse($value)->toISOString() : null;
    }
}
