<?php

namespace App\Services\Actions;

use App\Enums\ToolRunStatus;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\ToolRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class ActionHistoryService
{
    private const PER_PAGE = 25;

    public function __construct(private readonly ActionPresentationService $presentation) {}

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
        $actionOptions = $this->actionOptions($team);
        $action = $this->action($filters['action'] ?? null, $actionOptions);
        $status = $this->status($filters['status'] ?? null);
        $search = trim(Str::limit((string) ($filters['search'] ?? ''), 120, ''));
        $baseQuery = $this->baseQuery($team, $selectedBot, $range, $action, $status, $search);
        $actions = (clone $baseQuery)
            ->select([
                'id',
                'team_id',
                'bot_id',
                'conversation_id',
                'action_reference',
                'tool_name',
                'status',
                'error_code',
                'duration_ms',
                'confirmed_at',
                'started_at',
                'completed_at',
                'failed_at',
                'cancelled_at',
                'created_at',
            ])
            ->with([
                'bot:id,name,slug',
                'conversation:id,public_id,visitor_id,metadata',
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'filters' => [
                'bot' => $selectedBot?->slug,
                'range' => $range['key'],
                'action' => $action,
                'status' => $status,
                'search' => $search !== '' ? $search : null,
            ],
            'botOptions' => $bots->map(fn (Bot $bot): array => [
                'id' => $bot->id,
                'name' => $bot->name,
                'slug' => $bot->slug,
            ])->values()->all(),
            'actionOptions' => collect($actionOptions)->map(fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
            ])->values()->all(),
            'statusOptions' => array_map(fn (ToolRunStatus $status): array => [
                'key' => $status->value,
                'label' => $this->presentation->statusLabel($status),
            ], ToolRunStatus::cases()),
            'summary' => $this->summary($baseQuery),
            'actions' => $actions->through(fn (ToolRun $run): array => $this->listItem($run)),
        ];
    }

    /**
     * @return array{action: array<string, mixed>}
     */
    public function detail(Team $team, string $actionReference): array
    {
        $run = $this->teamQuery($team)
            ->where('action_reference', $actionReference)
            ->with([
                'bot:id,name,slug',
                'conversation:id,public_id,visitor_id,metadata',
            ])
            ->firstOrFail();

        return ['action' => $this->detailPayload($run)];
    }

    /**
     * @return array<string, string>
     */
    private function actionOptions(Team $team): array
    {
        $names = ToolRun::query()
            ->whereIn('bot_id', $team->bots()->select('id'))
            ->where('team_id', $team->id)
            ->distinct()
            ->orderBy('tool_name')
            ->pluck('tool_name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();
        $names = array_values(array_unique([...array_keys($this->presentation->labels()), ...$names]));
        sort($names);

        return array_combine($names, array_map(
            fn (string $name): string => $this->presentation->label($name),
            $names,
        )) ?: [];
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
     * @param  array<string, string>  $options
     */
    private function action(mixed $value, array $options): ?string
    {
        return is_string($value) && array_key_exists($value, $options) ? $value : null;
    }

    private function status(mixed $value): ?string
    {
        return is_string($value) && ToolRunStatus::tryFrom($value) instanceof ToolRunStatus
            ? $value
            : null;
    }

    /**
     * @return array{key: string, start: CarbonImmutable|null, end: CarbonImmutable|null}
     */
    private function range(mixed $value): array
    {
        $key = is_string($value) && in_array($value, ['all', 'today', '7d', '30d', '90d'], true)
            ? $value
            : '30d';

        if ($key === 'all') {
            return ['key' => $key, 'start' => null, 'end' => null];
        }

        $now = CarbonImmutable::now();
        $days = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90][$key];

        return [
            'key' => $key,
            'start' => $now->startOfDay()->subDays($days - 1),
            'end' => $now,
        ];
    }

    /**
     * @param  array{key: string, start: CarbonImmutable|null, end: CarbonImmutable|null}  $range
     * @return Builder<ToolRun>
     */
    private function baseQuery(
        Team $team,
        ?Bot $bot,
        array $range,
        ?string $action,
        ?string $status,
        string $search,
    ): Builder {
        $query = $this->teamQuery($team);

        if ($bot !== null) {
            $query->where('bot_id', $bot->id);
        }

        if ($range['start'] !== null && $range['end'] !== null) {
            $query->whereBetween('created_at', [$range['start'], $range['end']]);
        }

        if ($action !== null) {
            $query->where('tool_name', $action);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $pattern = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw('LOWER(action_reference::text) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(COALESCE(error_code, \'\')) LIKE ?', [$pattern])
                    ->orWhereHas('bot', fn (Builder $botQuery): Builder => $botQuery
                        ->whereRaw('LOWER(name) LIKE ?', [$pattern]));
            });
        }

        return $query;
    }

    /**
     * @return Builder<ToolRun>
     */
    private function teamQuery(Team $team): Builder
    {
        return ToolRun::query()
            ->where('team_id', $team->id)
            ->whereIn('bot_id', $team->bots()->select('id'));
    }

    /**
     * @param  Builder<ToolRun>  $query
     * @return array{total: int, completed: int, failed: int, cancelled: int, pending: int, successRate: float|null}
     */
    private function summary(Builder $query): array
    {
        $row = (clone $query)->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed', [ToolRunStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS failed', [ToolRunStatus::Failed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled', [ToolRunStatus::Cancelled->value])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) AS pending', [
                ToolRunStatus::PendingConfirmation->value,
                ToolRunStatus::Confirmed->value,
                ToolRunStatus::Executing->value,
            ])
            ->first();
        $completed = (int) ($row->completed ?? 0);
        $failed = (int) ($row->failed ?? 0);
        $cancelled = (int) ($row->cancelled ?? 0);
        $terminal = $completed + $failed + $cancelled;

        return [
            'total' => (int) ($row->total ?? 0),
            'completed' => $completed,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'pending' => (int) ($row->pending ?? 0),
            'successRate' => $terminal > 0 ? round(($completed / $terminal) * 100, 1) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listItem(ToolRun $run): array
    {
        return [
            'actionReference' => $run->action_reference,
            'tool' => $run->tool_name,
            'label' => $this->presentation->label($run->tool_name),
            'status' => $this->statusValue($run),
            'statusLabel' => $this->presentation->statusLabel($run->status),
            'bot' => [
                'id' => $run->bot->id,
                'name' => $run->bot->name,
                'slug' => $run->bot->slug,
            ],
            'conversationReference' => $run->conversation?->public_id,
            'createdAt' => $this->isoDate($run->created_at),
            'completedAt' => $this->isoDate($run->completed_at),
            'durationMs' => $run->duration_ms,
            'errorSummary' => $this->presentation->errorSummary($run->error_code),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(ToolRun $run): array
    {
        return [
            ...$this->listItem($run),
            'confirmedAt' => $this->isoDate($run->confirmed_at),
            'startedAt' => $this->isoDate($run->started_at),
            'failedAt' => $this->isoDate($run->failed_at),
            'cancelledAt' => $this->isoDate($run->cancelled_at),
            'result' => [
                'summary' => $this->presentation->resultSummary(
                    $run->tool_name,
                    is_array($run->getAttribute('safe_result')) ? $run->getAttribute('safe_result') : null,
                ),
            ],
            'conversation' => $this->conversationPayload($run->conversation),
            'lifecycle' => $this->lifecycle($run),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function conversationPayload(?Conversation $conversation): ?array
    {
        if ($conversation === null) {
            return null;
        }

        return [
            'reference' => $conversation->public_id,
            'source' => $conversation->visitor_id !== null
                ? 'widget'
                : (data_get($conversation->metadata, 'source') === 'dashboard_preview' ? 'preview' : 'conversation'),
        ];
    }

    /**
     * @return list<array{key: string, label: string, at: string|null}>
     */
    private function lifecycle(ToolRun $run): array
    {
        $status = $this->statusValue($run);
        $steps = [
            ['key' => 'proposed', 'label' => 'Proposed', 'at' => $this->isoDate($run->created_at)],
        ];

        if ($run->confirmed_at !== null) {
            $steps[] = ['key' => 'confirmed', 'label' => 'Confirmed', 'at' => $this->isoDate($run->confirmed_at)];
        }

        if ($run->started_at !== null) {
            $steps[] = ['key' => 'executing', 'label' => 'Executing', 'at' => $this->isoDate($run->started_at)];
        }

        $terminalTimestamp = match ($status) {
            ToolRunStatus::Completed->value => $run->completed_at,
            ToolRunStatus::Failed->value => $run->failed_at,
            ToolRunStatus::Cancelled->value => $run->cancelled_at,
            default => null,
        };

        if ($terminalTimestamp !== null) {
            $steps[] = [
                'key' => $status,
                'label' => $this->presentation->statusLabel($status),
                'at' => $this->isoDate($terminalTimestamp),
            ];
        }

        return $steps;
    }

    private function statusValue(ToolRun $run): string
    {
        $status = $run->getAttribute('status');

        return $status instanceof ToolRunStatus ? $status->value : (string) $status;
    }

    private function isoDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}
