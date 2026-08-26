<?php

namespace App\Services\Integrations;

use App\Enums\ApiOperationMode;
use App\Enums\ApiOperationSyncFrequency;
use App\Enums\DataSourceStatus;
use App\Enums\ToolRunStatus;
use App\Models\ApiOperation;
use App\Models\ApiOperationSyncSchedule;
use App\Models\DataSource;
use App\Models\SourceRun;
use App\Models\Team;
use App\Models\ToolRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class IntegrationHealthService
{
    /**
     * @var array<string, int>
     */
    private const RANGE_DAYS = [
        'today' => 1,
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
    ];

    /**
     * @var array<string, string>
     */
    private const HEALTH_LABELS = [
        'healthy' => 'Healthy',
        'warning' => 'Warning',
        'error' => 'Error',
        'inactive' => 'Inactive',
    ];

    /**
     * Build a privacy-safe health payload for one team.
     *
     * Read API calls are intentionally represented as unavailable because the
     * current runtime does not persist per-operation read telemetry.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function index(Team $team, array $filters): array
    {
        [$range, $start, $end] = $this->range($filters['range'] ?? null);
        $dataSourceFilter = $this->integerFilter($filters['data_source'] ?? null);
        $healthFilter = $this->healthFilter($filters['health'] ?? null);
        $dataSources = $this->dataSources($team, $dataSourceFilter, $start, $end);
        $operationMetrics = $this->operationMetrics($team, $dataSources, $start, $end);
        $items = $dataSources
            ->map(fn (DataSource $dataSource): array => $this->dataSourceItem($team, $dataSource, $operationMetrics))
            ->filter(fn (array $item): bool => $healthFilter === 'all' || $item['health'] === $healthFilter)
            ->values()
            ->all();

        return [
            'filters' => [
                'range' => $range,
                'dataSource' => $dataSourceFilter,
                'health' => $healthFilter,
            ],
            'dataSourceOptions' => $team->dataSources()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get()
                ->map(fn (DataSource $dataSource): array => [
                    'id' => $dataSource->id,
                    'name' => $dataSource->name,
                ])
                ->values()
                ->all(),
            'healthOptions' => collect(self::HEALTH_LABELS)
                ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'summary' => $this->summary(array_values($items)),
            'items' => $items,
            'operations' => $operationMetrics,
            'recentFailures' => $this->recentFailures($team, array_values($dataSources->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()), $start, $end),
        ];
    }

    /**
     * Build one data source detail page, scoped to the selected team.
     *
     * @return array<string, mixed>
     */
    public function detail(Team $team, DataSource $dataSource): array
    {
        $now = CarbonImmutable::now();
        $start = $now->startOfDay()->subDays(6);
        $scopedDataSource = $this->dataSources($team, $dataSource->id, $start, $now)->first();

        if (! $scopedDataSource instanceof DataSource) {
            abort(404);
        }
        $operationMetrics = $this->operationMetrics($team, new Collection([$scopedDataSource]), $start, $now);
        $item = $this->dataSourceItem($team, $scopedDataSource, $operationMetrics);

        return [
            'dataSource' => $item,
            'operations' => $operationMetrics,
            'recentFailures' => $this->recentFailures($team, [$scopedDataSource->id], $start, $now),
        ];
    }

    /**
     * @return Collection<int, DataSource>
     */
    private function dataSources(Team $team, ?int $filter, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $query = $team->dataSources()
            ->select(['data_sources.id', 'data_sources.team_id', 'data_sources.name', 'data_sources.type', 'data_sources.status', 'data_sources.last_synced_at', 'data_sources.created_at', 'data_sources.updated_at'])
            ->selectSub($this->sourceRunValue('finished_at', 'completed'), 'last_success_at')
            ->selectSub($this->sourceRunValue('started_at', 'completed'), 'last_success_started_at')
            ->selectSub($this->lastRunValue(), 'last_run_at')
            ->selectSub($this->sourceRunValue('status', null), 'last_run_status')
            ->selectSub($this->sourceRunValue('finished_at', 'failed'), 'last_failure_at')
            ->selectSub($this->sourceRunValue('error', 'failed'), 'last_failure_error')
            ->selectSub($this->sourceRunValue('rows_read', 'completed'), 'last_rows_read')
            ->selectSub($this->sourceRunValue('rows_written', 'completed'), 'last_rows_written')
            ->selectSub($this->sourceRunValue('rows_failed', 'completed'), 'last_rows_failed')
            ->selectSub(SourceRun::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('data_source_id', 'data_sources.id')
                ->where('status', 'failed')
                ->whereBetween('finished_at', [$start, $end]), 'recent_failure_count')
            ->withCount('datasets')
            ->with([
                'datasets:id,data_source_id,name,slug',
                'datasets.bots:id,name,slug,team_id',
                'apiOperations:id,data_source_id,key,name,type,execution_mode,is_enabled',
                'apiOperations.botApiOperations:id,api_operation_id,bot_id,tool_name,is_enabled',
                'apiOperations.botApiOperations.bot:id,name,slug,team_id',
            ]);

        if ($filter !== null) {
            $query->whereKey($filter);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * @return Builder<SourceRun>
     */
    private function sourceRunValue(string $column, ?string $status): Builder
    {
        $query = SourceRun::query()
            ->select($column)
            ->whereColumn('data_source_id', 'data_sources.id')
            ->orderByRaw('COALESCE(finished_at, started_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit(1);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query;
    }

    /**
     * @return Builder<SourceRun>
     */
    private function lastRunValue(): Builder
    {
        return SourceRun::query()
            ->selectRaw('COALESCE(finished_at, started_at, created_at)')
            ->whereColumn('data_source_id', 'data_sources.id')
            ->orderByRaw('COALESCE(finished_at, started_at, created_at) DESC')
            ->limit(1);
    }

    /**
     * @param  Collection<int, DataSource>  $dataSources
     * @return array<int, array<string, mixed>>
     */
    private function operationMetrics(Team $team, Collection $dataSources, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $sourceIds = $dataSources->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($sourceIds === []) {
            return [];
        }

        $telemetry = ToolRun::query()
            ->where('team_id', $team->id)
            ->where('execution_mode', ApiOperationMode::Write->value)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('api_operation_id', ApiOperation::query()->whereIn('data_source_id', $sourceIds)->select('id'))
            ->select('api_operation_id')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS calls', [
                ToolRunStatus::Completed->value,
                ToolRunStatus::Failed->value,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS successes', [ToolRunStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS failures', [ToolRunStatus::Failed->value])
            ->selectRaw('AVG(CASE WHEN duration_ms IS NOT NULL THEN duration_ms END) AS average_duration_ms')
            ->selectRaw('MAX(completed_at) AS last_success_at')
            ->selectRaw('MAX(failed_at) AS last_failure_at')
            ->groupBy('api_operation_id')
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->api_operation_id);

        $operationRows = ApiOperation::query()
            ->whereIn('data_source_id', $sourceIds)
            ->with([
                'dataSource:id,team_id,name',
                'botApiOperations:id,api_operation_id,bot_id,tool_name,is_enabled',
                'botApiOperations.bot:id,name,slug,team_id',
                'syncSchedule',
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn (ApiOperation $operation): bool => $operation->botApiOperations->contains(
                fn ($attachment): bool => $attachment->bot !== null && (int) $attachment->bot->team_id === (int) $team->id,
            ))
            ->map(function (ApiOperation $operation) use ($telemetry): array {
                $attachments = $operation->botApiOperations
                    ->filter(fn ($attachment): bool => $attachment->bot !== null)
                    ->filter(fn ($attachment): bool => (int) $attachment->bot->team_id === (int) $operation->dataSource->team_id)
                    ->values();
                $bots = $attachments
                    ->map(fn ($attachment): array => [
                        'id' => $attachment->bot->id,
                        'name' => $attachment->bot->name,
                        'slug' => $attachment->bot->slug,
                        'enabled' => (bool) $attachment->is_enabled,
                    ])
                    ->unique('id')
                    ->values()
                    ->all();
                $row = $telemetry->get($operation->id);
                $mode = (string) $operation->execution_mode;
                $available = $mode === ApiOperationMode::Write->value;
                $calls = $available && $row !== null ? (int) $row->calls : null;
                $successes = $available && $row !== null ? (int) $row->successes : null;
                $failures = $available && $row !== null ? (int) $row->failures : null;
                $terminal = ($successes ?? 0) + ($failures ?? 0);

                return [
                    'id' => $operation->id,
                    'key' => $operation->key,
                    'name' => $operation->name,
                    'source' => [
                        'id' => $operation->dataSource->id,
                        'name' => $operation->dataSource->name,
                    ],
                    'mode' => $mode,
                    'enabled' => (bool) $operation->is_enabled && collect($bots)->contains('enabled', true),
                    'bots' => $bots,
                    'telemetryAvailable' => $available,
                    'telemetryMessage' => $available ? null : 'Read-operation usage telemetry is not persisted yet.',
                    'calls' => $calls,
                    'successes' => $successes,
                    'failures' => $failures,
                    'failureRate' => $terminal > 0 ? round(($failures / $terminal) * 100, 1) : ($available ? null : null),
                    'averageDurationMs' => $available && $row !== null && $row->average_duration_ms !== null
                        ? round((float) $row->average_duration_ms, 1)
                        : null,
                    'lastSuccessAt' => $available && $row !== null ? $this->isoDate($row->last_success_at) : null,
                    'lastFailureAt' => $available && $row !== null ? $this->isoDate($row->last_failure_at) : null,
                    'sync' => $operation->syncSchedule === null ? null : [
                        'frequency' => $operation->syncSchedule->frequency->value,
                        'strategy' => $operation->syncSchedule->strategy->value,
                        'enabled' => $operation->syncSchedule->is_enabled,
                        'nextRunAt' => $this->isoDate($operation->syncSchedule->next_run_at),
                        'lastSuccessAt' => $this->isoDate($operation->syncSchedule->last_success_at),
                        'lastFailureAt' => $this->isoDate($operation->syncSchedule->last_failure_at),
                        'consecutiveFailures' => $operation->syncSchedule->consecutive_failures,
                        'state' => $this->syncState($operation->syncSchedule),
                    ],
                ];
            })
            ->all();

        return array_values($operationRows);
    }

    private function syncState(ApiOperationSyncSchedule $schedule): string
    {
        if ($schedule->frequency === ApiOperationSyncFrequency::Manual) {
            return 'manual';
        }

        if (! $schedule->is_enabled) {
            return 'paused';
        }

        if ($schedule->locked_until?->isFuture()) {
            return 'running';
        }

        if ($schedule->consecutive_failures > 0) {
            return 'failing';
        }

        if ($schedule->next_run_at?->isPast()) {
            return 'stale';
        }

        return 'scheduled';
    }

    /**
     * @param  array<int, array<string, mixed>>  $operationMetrics
     * @return array<string, mixed>
     */
    private function dataSourceItem(Team $team, DataSource $dataSource, array $operationMetrics): array
    {
        $lastRunAt = $dataSource->getAttribute('last_run_at');
        $lastSuccessAt = $dataSource->getAttribute('last_success_at');
        $lastFailureAt = $dataSource->getAttribute('last_failure_at');
        $recentFailureCount = (int) ($dataSource->getAttribute('recent_failure_count') ?? 0);
        $operations = array_values(array_filter(
            $operationMetrics,
            fn (array $operation): bool => $operation['source']['id'] === $dataSource->id,
        ));
        $bots = $this->affectedBots($team, $dataSource, $operations);
        $datasets = $dataSource->datasets
            ->map(fn ($dataset): array => [
                'id' => $dataset->id,
                'name' => $dataset->name,
                'slug' => $dataset->slug,
            ])
            ->values()
            ->all();
        $health = $this->health(
            $dataSource,
            $dataSource->getAttribute('last_run_status'),
            $lastSuccessAt,
            $lastFailureAt,
            $recentFailureCount,
        );

        return [
            'id' => $dataSource->id,
            'name' => $dataSource->name,
            'type' => $dataSource->type,
            'status' => $dataSource->status,
            'statusLabel' => Str::headline((string) $dataSource->status),
            'health' => $health,
            'healthLabel' => self::HEALTH_LABELS[$health],
            'lastSyncedAt' => $this->isoDate($dataSource->last_synced_at),
            'lastRunAt' => $this->isoDate($lastRunAt),
            'lastSuccessfulRunAt' => $this->isoDate($lastSuccessAt),
            'lastFailureAt' => $this->isoDate($lastFailureAt),
            'lastFailureLabel' => $lastFailureAt === null ? null : $this->safeErrorLabel($dataSource->getAttribute('last_failure_error')),
            'recentFailureCount' => $recentFailureCount,
            'rowsRead' => $this->nullableInteger($dataSource->getAttribute('last_rows_read')),
            'rowsWritten' => $this->nullableInteger($dataSource->getAttribute('last_rows_written')),
            'rowsFailed' => $this->nullableInteger($dataSource->getAttribute('last_rows_failed')),
            'lastRunDurationMs' => $this->sourceRunDuration($dataSource),
            'datasets' => $datasets,
            'bots' => $bots,
            'operationCount' => count($operations),
            'readTelemetryAvailable' => count(array_filter($operations, fn (array $operation): bool => $operation['mode'] === ApiOperationMode::Read->value)) === 0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $operations
     * @return array<int, array<string, mixed>>
     */
    private function affectedBots(Team $team, DataSource $dataSource, array $operations): array
    {
        $bots = [];

        foreach ($dataSource->datasets as $dataset) {
            foreach ($dataset->bots as $bot) {
                if ((int) $bot->team_id !== (int) $team->id) {
                    continue;
                }

                $bots[(int) $bot->id] = ['id' => $bot->id, 'name' => $bot->name, 'slug' => $bot->slug];
            }
        }

        foreach ($operations as $operation) {
            foreach ($operation['bots'] as $bot) {
                $bots[(int) $bot['id']] = [
                    'id' => $bot['id'],
                    'name' => $bot['name'],
                    'slug' => $bot['slug'],
                ];
            }
        }

        uasort($bots, fn (array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name']));

        return array_values($bots);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function summary(array $items): array
    {
        return [
            'integrations' => count($items),
            'healthy' => count(array_filter($items, fn (array $item): bool => $item['health'] === 'healthy')),
            'warnings' => count(array_filter($items, fn (array $item): bool => $item['health'] === 'warning')),
            'errors' => count(array_filter($items, fn (array $item): bool => $item['health'] === 'error')),
            'inactive' => count(array_filter($items, fn (array $item): bool => $item['health'] === 'inactive')),
            'recentFailures' => array_sum(array_map(fn (array $item): int => (int) $item['recentFailureCount'], $items)),
        ];
    }

    /**
     * @param  array<int>  $dataSourceIds
     * @return array<int, array<string, mixed>>
     */
    private function recentFailures(Team $team, array $dataSourceIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($dataSourceIds === []) {
            return [];
        }

        $imports = SourceRun::query()
            ->whereIn('data_source_id', $dataSourceIds)
            ->where('status', 'failed')
            ->whereBetween('finished_at', [$start, $end])
            ->with(['dataSource:id,name', 'dataset:id,name,slug'])
            ->select(['id', 'data_source_id', 'dataset_id', 'status', 'rows_read', 'rows_written', 'rows_failed', 'error', 'finished_at', 'created_at'])
            ->latest('finished_at')
            ->limit(20)
            ->get()
            ->map(fn (SourceRun $run): array => [
                'id' => $run->id,
                'kind' => 'import',
                'source' => ['id' => $run->dataSource->id, 'name' => $run->dataSource->name],
                'dataset' => $run->dataset === null ? null : ['id' => $run->dataset->id, 'name' => $run->dataset->name, 'slug' => $run->dataset->slug],
                'operation' => null,
                'bot' => null,
                'status' => $run->status,
                'errorCode' => $this->safeErrorCode($run->error),
                'errorLabel' => $this->safeErrorLabel($run->error),
                'at' => $this->isoDate($run->finished_at ?? $run->created_at),
                'actionReference' => null,
            ]);

        $actions = ToolRun::query()
            ->where('team_id', $team->id)
            ->where('execution_mode', ApiOperationMode::Write->value)
            ->where('status', ToolRunStatus::Failed->value)
            ->whereBetween('failed_at', [$start, $end])
            ->whereIn('api_operation_id', ApiOperation::query()->whereIn('data_source_id', $dataSourceIds)->select('id'))
            ->with(['bot:id,name,slug', 'apiOperation:id,data_source_id,name,key', 'apiOperation.dataSource:id,name'])
            ->select(['id', 'api_operation_id', 'bot_id', 'action_reference', 'status', 'error_code', 'failed_at', 'created_at'])
            ->latest('failed_at')
            ->limit(20)
            ->get()
            ->map(fn (ToolRun $run): array => [
                'id' => $run->id,
                'kind' => 'action',
                'source' => ['id' => $run->apiOperation->data_source_id, 'name' => $run->apiOperation->dataSource->name],
                'dataset' => null,
                'operation' => ['id' => $run->apiOperation->id, 'name' => $run->apiOperation->name, 'key' => $run->apiOperation->key],
                'bot' => $run->bot === null ? null : ['id' => $run->bot->id, 'name' => $run->bot->name, 'slug' => $run->bot->slug],
                'status' => ToolRunStatus::from((string) $run->getRawOriginal('status'))->value,
                'errorCode' => $this->safeErrorCode($run->error_code),
                'errorLabel' => $this->safeErrorLabel($run->error_code),
                'at' => $this->isoDate($run->failed_at ?? $run->created_at),
                'actionReference' => $run->action_reference,
            ]);

        return collect(array_merge($imports->all(), $actions->all()))
            ->sortByDesc('at')
            ->take(20)
            ->values()
            ->all();
    }

    private function health(DataSource $dataSource, ?string $lastRunStatus, mixed $lastSuccessAt, mixed $lastFailureAt, int $recentFailureCount): string
    {
        if ($dataSource->status === DataSourceStatus::Disabled->value) {
            return 'inactive';
        }

        if ($dataSource->status === DataSourceStatus::Error->value
            || ($lastFailureAt !== null && ($lastSuccessAt === null || (string) $lastFailureAt > (string) $lastSuccessAt))) {
            return 'error';
        }

        if (in_array($dataSource->status, [DataSourceStatus::Pending->value, DataSourceStatus::Syncing->value], true)
            || $recentFailureCount > 0
            || (in_array($dataSource->type, ['rest_api', 'graphql_api'], true)
                && $lastSuccessAt === null
                && $dataSource->last_synced_at === null)) {
            return 'warning';
        }

        if ($dataSource->status === DataSourceStatus::Ready->value && ($lastSuccessAt !== null || $dataSource->last_synced_at !== null)) {
            return 'healthy';
        }

        return $lastRunStatus === null ? 'warning' : 'healthy';
    }

    private function sourceRunDuration(DataSource $dataSource): ?int
    {
        $startedAt = $dataSource->getAttribute('last_success_started_at');
        $finishedAt = $dataSource->getAttribute('last_success_finished_at');

        if (! is_string($startedAt) || ! is_string($finishedAt)) {
            return null;
        }

        return (int) max(0, CarbonImmutable::parse($finishedAt)->diffInMilliseconds(CarbonImmutable::parse($startedAt)));
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function integerFilter(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /**
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function range(mixed $value): array
    {
        $key = is_string($value) && array_key_exists($value, self::RANGE_DAYS) ? $value : '7d';
        $now = CarbonImmutable::now();
        $days = self::RANGE_DAYS[$key];

        return [$key, $now->startOfDay()->subDays($days - 1), $now];
    }

    private function healthFilter(mixed $value): string
    {
        return is_string($value) && (array_key_exists($value, self::HEALTH_LABELS) || $value === 'all') ? $value : 'all';
    }

    private function safeErrorCode(mixed $error): string
    {
        $value = Str::lower((string) $error);

        return match (true) {
            Str::contains($value, ['auth', 'unauthoriz', 'forbidden', 'credential']) => 'authentication_failed',
            Str::contains($value, ['timeout', 'timed out']) => 'timeout',
            Str::contains($value, ['response', 'json', 'normalize', 'invalid']) => 'invalid_response',
            Str::contains($value, ['unavailable', 'connection', 'network', 'http']) => 'integration_unavailable',
            default => 'unknown',
        };
    }

    private function safeErrorLabel(mixed $error): string
    {
        return Str::headline(str_replace('_', ' ', $this->safeErrorCode($error)));
    }

    private function isoDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : (is_string($value) && $value !== '' ? $value : null);
    }
}
