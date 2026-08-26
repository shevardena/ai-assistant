<?php

namespace App\Services\Analytics;

use App\Enums\ToolRunStatus;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SearchRun;
use App\Models\Team;
use App\Models\ToolRun;
use App\Services\Actions\ActionPresentationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TeamAnalyticsService
{
    public function __construct(private readonly ActionPresentationService $presentation) {}

    /**
     * @var array<string, int>
     */
    private const RANGE_DAYS = [
        'today' => 1,
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
    ];

    private const POSTGRES_DATE_BUCKET = "DATE_TRUNC('day', created_at)::date";

    private const DEFAULT_DATE_BUCKET = 'DATE(created_at)';

    /**
     * Build a privacy-safe analytics payload for one Team and bounded period.
     *
     * ToolRun rows represent one persisted action proposal and its lifecycle;
     * they are counted once by status rather than once per lifecycle transition.
     * Business outcome metrics count completed write rows only.
     *
     * @return array<string, mixed>
     */
    public function dashboard(Team $team, string $range = '30d', ?string $botFilter = null): array
    {
        [$start, $end] = $this->period($range);
        $bots = $team->bots()
            ->select(['id', 'name', 'slug'])
            ->orderBy('name')
            ->get();
        $selectedBot = $this->resolveBot($bots, $botFilter);
        $botIds = $this->botIds($bots, $selectedBot);

        $conversations = $this->conversationQuery($botIds, $start, $end);
        $messages = $this->messageQuery($botIds, $start, $end);
        $searches = $this->searchQuery($botIds, $start, $end);
        $toolRuns = $this->toolRunQuery($team, $botIds, $start, $end);
        $successfulSearches = (clone $searches)->where('status', 'completed');

        $conversationCount = (clone $conversations)->count();
        $searchCount = (clone $searches)->count();
        $completedActionCount = (clone $toolRuns)
            ->where('status', ToolRunStatus::Completed->value)
            ->count();
        $failedActionCount = (clone $toolRuns)
            ->where('status', ToolRunStatus::Failed->value)
            ->count();
        $cancelledActionCount = (clone $toolRuns)
            ->where('status', ToolRunStatus::Cancelled->value)
            ->count();
        $terminalActionCount = $completedActionCount + $failedActionCount + $cancelledActionCount;
        $averageResultCount = $successfulSearches->avg('result_count');
        $actionBreakdown = $this->actionBreakdown(clone $toolRuns);

        return [
            'filters' => [
                'range' => $range,
                'bot' => $selectedBot?->slug,
            ],
            'botOptions' => $bots->map(fn (Bot $bot): array => [
                'name' => $bot->name,
                'slug' => $bot->slug,
            ])->values()->all(),
            'summary' => [
                'conversations' => $conversationCount,
                'visitors' => (clone $conversations)
                    ->whereNotNull('visitor_id')
                    ->select('visitor_id')
                    ->distinct()
                    ->count('visitor_id'),
                'messages' => $messages->count(),
                'searches' => $searchCount,
                'zeroResultSearches' => (clone $successfulSearches)->where('result_count', 0)->count(),
                'averageResultCount' => $averageResultCount === null ? null : round((float) $averageResultCount, 1),
                'actionsProposed' => (clone $toolRuns)->count(),
                'completedActions' => $completedActionCount,
                'failedActions' => $failedActionCount,
                'cancelledActions' => $cancelledActionCount,
                'actionSuccessRate' => $terminalActionCount > 0
                    ? round(($completedActionCount / $terminalActionCount) * 100, 1)
                    : null,
                'leadsCaptured' => $this->completedActionCount($toolRuns, 'capture_lead'),
                'supportTickets' => $this->completedActionCount($toolRuns, 'create_support_ticket'),
                'appointmentsBooked' => $this->completedActionCount($toolRuns, 'book_appointment'),
                'addToCart' => $this->completedActionCount($toolRuns, 'add_to_cart'),
            ],
            'timeseries' => [
                'conversations' => $this->conversationTimeseries($botIds, $start, $end),
            ],
            'capabilities' => $this->capabilityUsage($searchCount, $actionBreakdown),
            'actions' => $actionBreakdown,
            'bots' => $this->botBreakdown($team, $bots, $botIds, $start, $end),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(string $range): array
    {
        $days = self::RANGE_DAYS[$range] ?? self::RANGE_DAYS['30d'];
        $end = now();
        $start = $end->startOfDay()->subDays($days - 1);

        return [$start, $end];
    }

    /**
     * @param  Collection<int, Bot>  $bots
     */
    private function resolveBot(Collection $bots, ?string $botFilter): ?Bot
    {
        if ($botFilter === null) {
            return null;
        }

        return $bots->first(fn (Bot $bot): bool => $bot->slug === $botFilter
            || (ctype_digit($botFilter) && (int) $botFilter === (int) $bot->id));
    }

    /**
     * @param  Collection<int, Bot>  $bots
     * @return list<int>
     */
    private function botIds(Collection $bots, ?Bot $selectedBot): array
    {
        if ($selectedBot instanceof Bot) {
            return [$selectedBot->id];
        }

        return array_values($bots->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * @param  list<int>  $botIds
     * @return Builder<Conversation>
     */
    private function conversationQuery(array $botIds, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return Conversation::query()
            ->whereIn('bot_id', $botIds)
            ->whereBetween('created_at', [$start, $end]);
    }

    /**
     * @param  list<int>  $botIds
     * @return Builder<Message>
     */
    private function messageQuery(array $botIds, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return Message::query()
            ->whereIn('conversation_id', Conversation::query()
                ->whereIn('bot_id', $botIds)
                ->select('id'))
            ->whereIn('role', ['user', 'assistant'])
            ->whereBetween('messages.created_at', [$start, $end]);
    }

    /**
     * @param  list<int>  $botIds
     * @return Builder<SearchRun>
     */
    private function searchQuery(array $botIds, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return SearchRun::query()
            ->whereIn('bot_id', $botIds)
            ->whereBetween('created_at', [$start, $end]);
    }

    /**
     * @param  list<int>  $botIds
     * @return Builder<ToolRun>
     */
    private function toolRunQuery(Team $team, array $botIds, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return ToolRun::query()
            ->where('team_id', $team->id)
            ->whereIn('bot_id', $botIds)
            ->whereBetween('created_at', [$start, $end]);
    }

    /**
     * @param  Builder<ToolRun>  $query
     * @return list<array{key: string, label: string, completed: int, failed: int, cancelled: int}>
     */
    private function actionBreakdown(Builder $query): array
    {
        $rows = $query->toBase()
            ->select('tool_name')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed', [ToolRunStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS failed', [ToolRunStatus::Failed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled', [ToolRunStatus::Cancelled->value])
            ->groupBy('tool_name')
            ->orderByDesc('completed')
            ->orderBy('tool_name')
            ->get();

        $breakdown = [];

        foreach ($this->presentation->labels() as $key => $label) {
            $row = $rows->firstWhere('tool_name', $key);
            $breakdown[] = [
                'key' => $key,
                'label' => $label,
                'completed' => (int) ($row === null ? 0 : $row->completed),
                'failed' => (int) ($row === null ? 0 : $row->failed),
                'cancelled' => (int) ($row === null ? 0 : $row->cancelled),
            ];
        }

        foreach ($rows as $row) {
            if (array_key_exists((string) $row->tool_name, $this->presentation->labels())) {
                continue;
            }

            $breakdown[] = [
                'key' => (string) $row->tool_name,
                'label' => Str::headline((string) $row->tool_name),
                'completed' => (int) $row->completed,
                'failed' => (int) $row->failed,
                'cancelled' => (int) $row->cancelled,
            ];
        }

        return $breakdown;
    }

    /**
     * @param  Builder<ToolRun>  $query
     */
    private function completedActionCount(Builder $query, string $toolName): int
    {
        return (clone $query)
            ->where('tool_name', $toolName)
            ->where('status', ToolRunStatus::Completed->value)
            ->count();
    }

    /**
     * @param  list<array{key: string, label: string, completed: int, failed: int, cancelled: int}>  $actions
     * @return list<array{key: string, label: string, count: int}>
     */
    private function capabilityUsage(int $searchCount, array $actions): array
    {
        $capabilities = [[
            'key' => 'search_catalog',
            'label' => 'Catalog search',
            'count' => $searchCount,
        ]];

        foreach ($actions as $action) {
            if ($action['completed'] === 0 && $action['failed'] === 0 && $action['cancelled'] === 0) {
                continue;
            }

            $capabilities[] = [
                'key' => $action['key'],
                'label' => $action['label'],
                'count' => $action['completed'],
            ];
        }

        return $capabilities;
    }

    /**
     * @param  list<int>  $botIds
     * @return list<array{date: string, value: int}>
     */
    private function conversationTimeseries(array $botIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $expression = $this->dateBucketExpression();
        $values = $this->conversationQuery($botIds, $start, $end)->toBase()
            ->selectRaw("{$expression} AS bucket")
            ->selectRaw('COUNT(*) AS value')
            ->groupByRaw($expression)
            ->orderByRaw($expression)
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->bucket => (int) $row->value]);
        $series = [];

        for ($date = $start->startOfDay(); $date <= $end->startOfDay(); $date = $date->addDay()) {
            $key = $date->toDateString();
            $series[] = ['date' => $key, 'value' => (int) ($values[$key] ?? 0)];
        }

        return $series;
    }

    /**
     * @param  Collection<int, Bot>  $bots
     * @param  list<int>  $botIds
     * @return list<array{name: string, slug: string, conversations: int, messages: int, searches: int, completedActions: int}>
     */
    private function botBreakdown(Team $team, Collection $bots, array $botIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $conversationCounts = $this->groupedCount($this->conversationQuery($botIds, $start, $end), 'bot_id');
        $messageCounts = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereIn('conversations.bot_id', $botIds)
            ->whereIn('messages.role', ['user', 'assistant'])
            ->whereBetween('messages.created_at', [$start, $end])
            ->select('conversations.bot_id')
            ->selectRaw('COUNT(messages.id) AS value')
            ->groupBy('conversations.bot_id')
            ->pluck('value', 'bot_id');
        $searchCounts = $this->groupedCount($this->searchQuery($botIds, $start, $end), 'bot_id');
        $actionCounts = $this->groupedCount(
            $this->toolRunQueryForBotIds($team, $botIds, $start, $end)->where('status', ToolRunStatus::Completed->value),
            'bot_id',
        );

        return array_values($bots
            ->filter(fn (Bot $bot): bool => in_array((int) $bot->id, $botIds, true))
            ->map(fn (Bot $bot): array => [
                'name' => $bot->name,
                'slug' => $bot->slug,
                'conversations' => (int) ($conversationCounts[$bot->id] ?? 0),
                'messages' => (int) ($messageCounts[$bot->id] ?? 0),
                'searches' => (int) ($searchCounts[$bot->id] ?? 0),
                'completedActions' => (int) ($actionCounts[$bot->id] ?? 0),
            ])
            ->values()
            ->all());
    }

    /**
     * @param  Builder<Conversation>|Builder<SearchRun>|Builder<ToolRun>  $query
     * @return Collection<int|string, mixed>
     */
    private function groupedCount(Builder $query, string $groupColumn): Collection
    {
        return $query
            ->select($groupColumn)
            ->selectRaw('COUNT(*) AS value')
            ->groupBy($groupColumn)
            ->pluck('value', $groupColumn);
    }

    /**
     * @param  list<int>  $botIds
     * @return Builder<ToolRun>
     */
    private function toolRunQueryForBotIds(Team $team, array $botIds, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return ToolRun::query()
            ->where('team_id', $team->id)
            ->whereIn('bot_id', $botIds)
            ->whereBetween('created_at', [$start, $end]);
    }

    /**
     * @return literal-string
     */
    private function dateBucketExpression(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? self::POSTGRES_DATE_BUCKET
            : self::DEFAULT_DATE_BUCKET;
    }
}
