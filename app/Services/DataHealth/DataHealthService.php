<?php

namespace App\Services\DataHealth;

use App\Enums\DatasetStatus;
use App\Enums\DataSourceStatus;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\DataSource;
use App\Models\SourceRun;
use App\Models\Team;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;

final class DataHealthService
{
    private const PER_PAGE = 25;

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
     * Build a team-scoped data health index payload.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function index(Team $team, array $filters): array
    {
        [$range, $start, $end] = $this->range($filters['range'] ?? null);
        $healthFilter = $this->healthFilter($filters['health'] ?? null);
        $dataSourceFilterProvided = array_key_exists('data_source', $filters) && $filters['data_source'] !== null;
        $dataSourceFilter = $this->dataSourceFilter($team, $filters['data_source'] ?? null);
        $search = $this->searchFilter($filters['search'] ?? null);
        $baseQuery = $this->datasetQuery(
            $team,
            $dataSourceFilter,
            $search,
            $start,
            $end,
            invalidDataSourceFilter: $dataSourceFilterProvided && $dataSourceFilter === null,
        );
        $summaryRows = (clone $baseQuery)->get();
        $healthRows = $this->healthRows($team, $summaryRows);
        $visibleRows = $healthRows->filter(fn (array $row): bool => $healthFilter === 'all' || $row['health'] === $healthFilter);
        $visibleIds = $visibleRows->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $datasets = (clone $baseQuery)
            ->when($visibleIds === [], fn (Builder $query): Builder => $query->whereKey(0))
            ->when($visibleIds !== [], fn (Builder $query): Builder => $query->whereKey($visibleIds))
            ->latest('datasets.updated_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Dataset $dataset): array => $this->listRow($healthRows->get($dataset->id, [])));

        return [
            'filters' => [
                'range' => $range,
                'dataSource' => $dataSourceFilter,
                'health' => $healthFilter,
                'search' => $search !== '' ? $search : null,
            ],
            'dataSourceOptions' => $this->dataSourceOptions($team),
            'summary' => $this->summary($visibleRows),
            'datasets' => $datasets,
        ];
    }

    /**
     * Return the existing Data Health rows for cross-dashboard diagnostics.
     *
     * Improvement Center consumes the same aggregate counts and issue
     * classification as Data Health so the two pages cannot drift apart.
     *
     * @return list<array<string, mixed>>
     */
    public function improvementRows(Team $team, string $range = '30d'): array
    {
        [, $start, $end] = $this->range($range);
        $datasets = $this->datasetQuery($team, null, '', $start, $end)->get();

        $rows = [];

        foreach ($this->healthRows($team, $datasets) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Build a team-scoped detail payload without exposing record contents.
     *
     * @return array<string, mixed>
     */
    public function detail(Team $team, Dataset $dataset): array
    {
        $now = CarbonImmutable::now();
        $start = $now->startOfDay()->subDays(6);
        $scopedDataset = $this->datasetQuery($team, null, '', $start, $now, datasetId: $dataset->id)->first();

        if (! $scopedDataset instanceof Dataset) {
            abort(404);
        }

        $healthRows = $this->healthRows($team, new Collection([$scopedDataset]));
        $health = $healthRows->get($scopedDataset->id);

        if (! is_array($health)) {
            abort(404);
        }
        $scopedDataset->load([
            'dataSource:id,team_id,name,type,status',
            'bots' => function (BelongsToMany $query) use ($team): void {
                $query->where('bots.team_id', $team->id)
                    ->select(['bots.id', 'bots.name', 'bots.slug']);
            },
        ]);

        return [
            'dataset' => [
                ...$health,
                'bots' => $scopedDataset->bots
                    ->map(fn ($bot): array => [
                        'id' => $bot->id,
                        'name' => $bot->name,
                        'slug' => $bot->slug,
                    ])
                    ->values()
                    ->all(),
            ],
            'fieldCoverage' => $health['fieldCoverage'],
            'importHistory' => $this->importHistory($scopedDataset),
        ];
    }

    /**
     * @return Builder<Dataset>
     */
    private function datasetQuery(
        Team $team,
        ?int $dataSourceFilter,
        string $search,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $datasetId = null,
        bool $invalidDataSourceFilter = false,
    ): Builder {
        $query = $team->datasets()->getQuery()
            ->select([
                'datasets.id',
                'datasets.team_id',
                'datasets.data_source_id',
                'datasets.name',
                'datasets.slug',
                'datasets.status',
                'datasets.last_indexed_at',
                'datasets.created_at',
                'datasets.updated_at',
            ])
            ->with([
                'dataSource' => function (Relation $sourceQuery) use ($team): void {
                    $sourceQuery->where('data_sources.team_id', $team->id)
                        ->select(['data_sources.id', 'data_sources.team_id', 'data_sources.name', 'data_sources.type', 'data_sources.status']);
                },
            ])
            ->withCount([
                'records',
                'records as active_records_count' => fn (Builder $recordQuery): Builder => $recordQuery->where('is_active', true),
                'records as inactive_records_count' => fn (Builder $recordQuery): Builder => $recordQuery->where('is_active', false),
                'fields',
                'fields as displayable_fields_count' => fn (Builder $fieldQuery): Builder => $fieldQuery->where('is_displayable', true),
                'fields as searchable_fields_count' => fn (Builder $fieldQuery): Builder => $fieldQuery->where('is_searchable', true),
                'fields as filterable_fields_count' => fn (Builder $fieldQuery): Builder => $fieldQuery->where('is_filterable', true),
                'bots as current_bots_count' => fn (Builder $botQuery): Builder => $botQuery->where('bots.team_id', $team->id),
            ])
            ->selectSub($this->sourceRunValue('finished_at', 'completed'), 'last_success_at')
            ->selectSub($this->lastRunValue(), 'last_run_at')
            ->selectSub($this->sourceRunValue('status', null), 'last_run_status')
            ->selectSub($this->sourceRunValue('finished_at', 'failed'), 'last_failure_at')
            ->selectSub($this->sourceRunValue('error', 'failed'), 'last_failure_error')
            ->selectSub($this->sourceRunValue('started_at', 'completed'), 'last_success_started_at')
            ->selectSub($this->sourceRunValue('rows_written', 'completed'), 'last_success_rows_written')
            ->selectSub($this->sourceRunValue('rows_failed', 'completed'), 'last_success_rows_failed')
            ->selectSub(SourceRun::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('dataset_id', 'datasets.id')
                ->where('status', 'failed')
                ->whereBetween('finished_at', [$start, $end]), 'recent_failed_import_count')
            ->selectSub(SourceRun::query()
                ->selectRaw('COALESCE(SUM(rows_failed), 0)')
                ->whereColumn('dataset_id', 'datasets.id')
                ->whereBetween('finished_at', [$start, $end]), 'recent_failed_row_count')
            ->where(function (Builder $query) use ($team): void {
                $query->whereNull('datasets.data_source_id')
                    ->orWhereIn('datasets.data_source_id', $team->dataSources()->select('id'));
            });

        if ($invalidDataSourceFilter) {
            $query->whereKey(0);
        } elseif ($dataSourceFilter !== null) {
            $query->where('datasets.data_source_id', $dataSourceFilter);
        }

        if ($datasetId !== null) {
            $query->whereKey($datasetId);
        }

        if ($search !== '') {
            $query->whereRaw('LOWER(datasets.name) LIKE ?', ['%'.Str::lower($search).'%']);
        }

        return $query;
    }

    /**
     * @return Builder<SourceRun>
     */
    private function sourceRunValue(string $column, ?string $status): Builder
    {
        $query = SourceRun::query()
            ->select($column)
            ->whereColumn('dataset_id', 'datasets.id')
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
            ->whereColumn('dataset_id', 'datasets.id')
            ->orderByRaw('COALESCE(finished_at, started_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit(1);
    }

    /**
     * @param  Collection<int, Dataset>  $datasets
     * @return SupportCollection<int, array<string, mixed>>
     */
    private function healthRows(Team $team, Collection $datasets): SupportCollection
    {
        $datasetIds = $datasets->modelKeys();
        $datasetIds = array_values(array_map(fn (mixed $id): int => (int) $id, $datasetIds));
        $coverage = $this->coverage($datasetIds);

        $rows = new SupportCollection;

        foreach ($datasets as $dataset) {
            $rows->put($dataset->id, $this->datasetHealthRow(
                $team,
                $dataset,
                $coverage->get($dataset->id, []),
            ));
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $fieldCoverage
     * @return array<string, mixed>
     */
    private function datasetHealthRow(Team $team, Dataset $dataset, array $fieldCoverage): array
    {
        $activeRecords = (int) $dataset->getAttribute('active_records_count');
        $fieldCoverage = array_map(function (array $field) use ($activeRecords): array {
            $field['activeRecords'] = $activeRecords;
            $field['coverage'] = $activeRecords > 0
                ? round(((int) $field['presentCount'] / $activeRecords) * 100, 1)
                : null;

            return $field;
        }, $fieldCoverage);
        $issues = $this->issues($team, $dataset, $fieldCoverage);
        $health = $this->health($dataset, $issues);

        return [
            'id' => $dataset->id,
            'name' => $dataset->name,
            'slug' => $dataset->slug,
            'status' => $dataset->status,
            'statusLabel' => Str::headline((string) $dataset->status),
            'health' => $health,
            'healthLabel' => self::HEALTH_LABELS[$health],
            'dataSource' => $dataset->dataSource === null ? null : [
                'id' => $dataset->dataSource->id,
                'name' => $dataset->dataSource->name,
                'type' => $dataset->dataSource->type,
                'status' => $dataset->dataSource->status,
            ],
            'totalRecords' => (int) $dataset->getAttribute('records_count'),
            'activeRecords' => $activeRecords,
            'inactiveRecords' => (int) $dataset->getAttribute('inactive_records_count'),
            'totalFields' => (int) $dataset->getAttribute('fields_count'),
            'displayableFields' => (int) $dataset->getAttribute('displayable_fields_count'),
            'searchableFields' => (int) $dataset->getAttribute('searchable_fields_count'),
            'filterableFields' => (int) $dataset->getAttribute('filterable_fields_count'),
            'lastSuccessfulImportAt' => $this->isoDate($dataset->getAttribute('last_success_at')),
            'lastImportAt' => $this->isoDate($dataset->getAttribute('last_run_at')),
            'lastImportStatus' => $this->stringValue($dataset->getAttribute('last_run_status')),
            'lastImportRowsWritten' => $this->nullableInt($dataset->getAttribute('last_success_rows_written')),
            'lastImportRowsFailed' => $this->nullableInt($dataset->getAttribute('last_success_rows_failed')),
            'lastImportDurationMs' => $this->importDuration($dataset),
            'recentFailedImportCount' => (int) ($dataset->getAttribute('recent_failed_import_count') ?? 0),
            'recentFailedRowCount' => (int) ($dataset->getAttribute('recent_failed_row_count') ?? 0),
            'issueCount' => count($issues),
            'issues' => $issues,
            'fieldCoverage' => $fieldCoverage,
            'botCount' => (int) $dataset->getAttribute('current_bots_count'),
            'updatedAt' => $this->isoDate($dataset->updated_at),
        ];
    }

    /**
     * @param  list<int>  $datasetIds
     * @return SupportCollection<int, list<array<string, mixed>>>
     */
    private function coverage(array $datasetIds): SupportCollection
    {
        if ($datasetIds === []) {
            return new SupportCollection;
        }

        $fieldTable = (new DatasetField)->getTable();
        $recordTable = (new DatasetRecord)->getTable();

        $rows = DatasetField::query()
            ->from($fieldTable.' as df')
            ->leftJoin($recordTable.' as dr', function (JoinClause $join): void {
                $join->on('dr.dataset_id', '=', 'df.dataset_id')
                    ->where('dr.is_active', true);
            })
            ->whereIn('df.dataset_id', $datasetIds)
            ->select([
                'df.dataset_id',
                'df.id',
                'df.key',
                'df.label',
                'df.data_type',
                'df.position',
                'df.is_displayable',
                'df.is_searchable',
                'df.is_filterable',
            ])
            ->selectRaw("COUNT(dr.id) FILTER (WHERE NULLIF(jsonb_extract_path_text(dr.payload, df.key), '') IS NOT NULL) AS present_count")
            ->groupBy([
                'df.dataset_id',
                'df.id',
                'df.key',
                'df.label',
                'df.data_type',
                'df.position',
                'df.is_displayable',
                'df.is_searchable',
                'df.is_filterable',
            ])
            ->toBase()
            ->get();

        return $rows->groupBy('dataset_id')->mapWithKeys(function (SupportCollection $datasetFields, int|string $datasetId): array {
            $fields = array_values($datasetFields
                ->map(fn (object $field): array => $this->coverageField($field))
                ->sortBy('position')
                ->values()
                ->all());

            return [(int) $datasetId => $fields];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function coverageField(object $field): array
    {
        return [
            'id' => (int) data_get($field, 'id'),
            'key' => (string) data_get($field, 'key'),
            'label' => (string) data_get($field, 'label'),
            'dataType' => (string) data_get($field, 'data_type'),
            'isDisplayable' => (bool) data_get($field, 'is_displayable'),
            'isSearchable' => (bool) data_get($field, 'is_searchable'),
            'isFilterable' => (bool) data_get($field, 'is_filterable'),
            'presentCount' => (int) data_get($field, 'present_count'),
            'position' => (int) data_get($field, 'position'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fieldCoverage
     * @return list<array<string, mixed>>
     */
    private function issues(Team $team, Dataset $dataset, array $fieldCoverage): array
    {
        $issues = [];
        $totalRecords = (int) $dataset->records_count;
        $activeRecords = (int) $dataset->getAttribute('active_records_count');
        $lastFailureAt = $dataset->getAttribute('last_failure_at');
        $lastSuccessAt = $dataset->getAttribute('last_success_at');
        $source = $dataset->dataSource;

        if ($dataset->status === DatasetStatus::Error->value) {
            $issues[] = $this->issue('dataset_error', 'error', 'Dataset is in an error state.');
        }

        if ($source?->status === DataSourceStatus::Error->value) {
            $issues[] = $this->issue('source_error', 'error', 'The connected data source is in an error state.');
        }

        if ($totalRecords === 0) {
            $issues[] = $this->issue('no_records', 'warning', 'No records have been imported yet.');
        } elseif ($activeRecords === 0) {
            $issues[] = $this->issue('no_active_records', 'warning', 'No active records are available to Bots.');
        }

        if ($lastFailureAt !== null && ($lastSuccessAt === null || (string) $lastFailureAt > (string) $lastSuccessAt)) {
            $issues[] = $this->issue('recent_import_failures', 'warning', 'The latest import failed and has not been followed by a successful import.');
        } elseif ((int) ($dataset->getAttribute('recent_failed_import_count') ?? 0) > 0
            || (int) ($dataset->getAttribute('recent_failed_row_count') ?? 0) > 0) {
            $issues[] = $this->issue('recent_import_failures', 'warning', 'A recent import reported failed rows or failed runs.');
        }

        foreach ($fieldCoverage as $field) {
            if (! $field['isDisplayable'] && ! $field['isSearchable'] && ! $field['isFilterable']) {
                continue;
            }

            if ($activeRecords > 0 && $field['presentCount'] === 0) {
                $issues[] = $this->issue(
                    'field_zero_coverage',
                    'warning',
                    sprintf('Field "%s" has no usable values in active records.', $field['label']),
                    field: $field['key'],
                );
            }
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    private function health(Dataset $dataset, array $issues): string
    {
        if ($dataset->data_source_id === null || $dataset->dataSource === null
            || $dataset->dataSource->status === DataSourceStatus::Disabled->value) {
            return 'inactive';
        }

        if ($dataset->status === DatasetStatus::Error->value
            || $dataset->dataSource->status === DataSourceStatus::Error->value) {
            return 'error';
        }

        $lastFailureAt = $dataset->getAttribute('last_failure_at');
        $lastSuccessAt = $dataset->getAttribute('last_success_at');

        if ($lastFailureAt !== null && ($lastSuccessAt === null || (string) $lastFailureAt > (string) $lastSuccessAt)) {
            return 'error';
        }

        if ($dataset->status !== DatasetStatus::Ready->value
            || (int) $dataset->getAttribute('active_records_count') === 0
            || $issues !== []) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * @return array{type: string, severity: string, message: string, field: string|null}
     */
    private function issue(string $type, string $severity, string $message, ?string $field = null): array
    {
        return compact('type', 'severity', 'message', 'field');
    }

    /**
     * @param  SupportCollection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function summary(SupportCollection $rows): array
    {
        return [
            'datasets' => $rows->count(),
            'healthy' => $rows->where('health', 'healthy')->count(),
            'warnings' => $rows->where('health', 'warning')->count(),
            'errors' => $rows->where('health', 'error')->count(),
            'inactive' => $rows->where('health', 'inactive')->count(),
            'records' => (int) $rows->sum('activeRecords'),
            'qualityIssues' => (int) $rows->sum('issueCount'),
        ];
    }

    /**
     * @return list<array{id: int, name: string, type: string, status: string}>
     */
    private function dataSourceOptions(Team $team): array
    {
        return array_values($team->dataSources()
            ->select(['id', 'name', 'type', 'status'])
            ->orderBy('name')
            ->get()
            ->map(fn (DataSource $source): array => [
                'id' => $source->id,
                'name' => $source->name,
                'type' => $source->type,
                'status' => $source->status,
            ])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function importHistory(Dataset $dataset): array
    {
        $history = $dataset->sourceRuns()
            ->select(['id', 'type', 'status', 'rows_read', 'rows_written', 'rows_failed', 'error', 'started_at', 'finished_at', 'created_at'])
            ->latest('created_at')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (SourceRun $run): array => $this->importHistoryRow($run))
            ->all();

        return array_values($history);
    }

    /**
     * @return array<string, mixed>
     */
    private function importHistoryRow(SourceRun $run): array
    {
        return [
            'id' => $run->id,
            'type' => $run->type,
            'status' => $run->status,
            'statusLabel' => $run->status === 'completed' ? 'Completed' : ($run->status === 'failed' ? 'Failed' : Str::headline((string) $run->status)),
            'rowsRead' => $run->rows_read,
            'rowsWritten' => $run->rows_written,
            'rowsFailed' => $run->rows_failed,
            'durationMs' => $this->runDuration($run),
            'errorLabel' => $run->status === 'failed' ? $this->safeErrorLabel($run->error) : null,
            'startedAt' => $this->isoDate($run->started_at),
            'finishedAt' => $this->isoDate($run->finished_at),
        ];
    }

    private function runDuration(SourceRun $run): ?int
    {
        if ($run->started_at === null || $run->finished_at === null) {
            return null;
        }

        return (int) max(0, CarbonImmutable::parse($run->finished_at)->diffInMilliseconds(CarbonImmutable::parse($run->started_at)));
    }

    private function importDuration(Dataset $dataset): ?int
    {
        $startedAt = $dataset->getAttribute('last_success_started_at');
        $finishedAt = $dataset->getAttribute('last_success_at');

        if (! is_string($startedAt) || ! is_string($finishedAt)) {
            return null;
        }

        return (int) max(0, CarbonImmutable::parse($finishedAt)->diffInMilliseconds(CarbonImmutable::parse($startedAt)));
    }

    private function dataSourceFilter(Team $team, mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $id = (int) $value;

        return $team->dataSources()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * Remove detail-only field coverage from list rows.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function listRow(array $row): array
    {
        unset($row['fieldCoverage']);

        return $row;
    }

    private function searchFilter(mixed $value): string
    {
        return is_string($value) ? trim(Str::limit($value, 120, '')) : '';
    }

    private function healthFilter(mixed $value): string
    {
        return is_string($value) && ($value === 'all' || array_key_exists($value, self::HEALTH_LABELS)) ? $value : 'all';
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

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function stringValue(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function isoDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : (is_string($value) && $value !== '' ? $value : null);
    }

    private function safeErrorLabel(mixed $error): string
    {
        $value = Str::lower((string) $error);

        return match (true) {
            Str::contains($value, ['auth', 'unauthoriz', 'forbidden', 'credential']) => 'Authentication failed',
            Str::contains($value, ['timeout', 'timed out']) => 'Timeout',
            Str::contains($value, ['response', 'json', 'normalize', 'invalid']) => 'Invalid response',
            Str::contains($value, ['unavailable', 'connection', 'network', 'http']) => 'Integration unavailable',
            default => 'Import failed',
        };
    }
}
