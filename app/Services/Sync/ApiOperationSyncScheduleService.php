<?php

namespace App\Services\Sync;

use App\Enums\ApiOperationMode;
use App\Enums\ApiOperationSyncFrequency;
use App\Enums\ApiOperationSyncStrategy;
use App\Models\ApiOperation;
use App\Models\ApiOperationSyncSchedule;
use App\Models\Dataset;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Imports\RestApiImportService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ApiOperationSyncScheduleService
{
    private const CLAIM_MINUTES = 120;

    public function __construct(
        private readonly SyncScheduleCalculator $calculator,
        private readonly RestApiImportService $imports,
    ) {}

    public function ensure(ApiOperation $operation, ?Dataset $dataset = null): ApiOperationSyncSchedule
    {
        $this->assertSchedulable($operation);

        if ($dataset !== null) {
            $this->assertDataset($operation, $dataset);
        }

        return ApiOperationSyncSchedule::query()->firstOrCreate(
            ['api_operation_id' => $operation->id],
            [
                'dataset_id' => $dataset?->id,
                'frequency' => ApiOperationSyncFrequency::Manual,
                'strategy' => ApiOperationSyncStrategy::FullSnapshot,
                'is_enabled' => false,
                'configuration' => [],
            ],
        );
    }

    /**
     * Atomically claim due work. Overdue schedules are claimed once and moved
     * directly to the next future slot instead of replaying missed intervals.
     *
     * @return Collection<int, ApiOperationSyncSchedule>
     */
    public function claimDue(int $limit = 100): Collection
    {
        $ids = ApiOperationSyncSchedule::query()
            ->where('is_enabled', true)
            ->where('frequency', '!=', ApiOperationSyncFrequency::Manual->value)
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->whereNotNull('next_run_at')->where('next_run_at', '<=', now());
                })->orWhere(function ($query): void {
                    $query->whereNotNull('locked_until')->where('locked_until', '<=', now());
                });
            })
            ->orderBy('next_run_at')
            ->limit($limit)
            ->pluck('id');
        $claimed = new Collection;

        foreach ($ids as $id) {
            try {
                $schedule = $this->claim((int) $id, automatic: true);
            } catch (ImportException $exception) {
                ApiOperationSyncSchedule::query()->whereKey($id)->update([
                    'is_enabled' => false,
                    'paused_at' => now(),
                    'next_run_at' => null,
                    'last_error' => Str::limit($exception->getMessage(), 1000),
                    'updated_at' => now(),
                ]);
                $schedule = null;
            }

            if ($schedule !== null) {
                $claimed->push($schedule);
            }
        }

        return $claimed;
    }

    public function claimManual(ApiOperation $operation, ?Dataset $dataset = null): ApiOperationSyncSchedule
    {
        $schedule = $this->ensure($operation, $dataset);

        if ($dataset !== null && $schedule->dataset_id !== null
            && (int) $schedule->dataset_id !== (int) $dataset->id) {
            throw new ImportException('This operation is configured for a different target dataset.');
        }

        $claimed = $this->claim((int) $schedule->id, automatic: false);

        if ($claimed === null) {
            throw new ImportException('This API operation is already synchronizing.');
        }

        if ($dataset !== null && $claimed->dataset_id === null) {
            $claimed->forceFill(['dataset_id' => $dataset->id])->save();
        }

        return $claimed->fresh(['operation.dataSource', 'dataset']);
    }

    /**
     * Run one claimed schedule through the production import pipeline.
     */
    public function run(ApiOperationSyncSchedule $schedule): void
    {
        $schedule->loadMissing(['operation.dataSource', 'dataset']);
        $operation = $schedule->operation;

        try {
            $this->assertSchedulable($operation);
            $this->assertIncrementalConfiguration($operation, $schedule->strategy, (array) $schedule->configuration);
            $dataset = $this->targetDataset($schedule);
            $sourceRun = $this->imports->handle($dataset, $operation, $schedule);
            $metadata = (array) $sourceRun->metadata;

            $schedule->forceFill([
                'last_completed_at' => now(),
                'last_success_at' => now(),
                'consecutive_failures' => 0,
                'last_error' => null,
                'locked_until' => null,
                ...($schedule->strategy !== ApiOperationSyncStrategy::FullSnapshot && array_key_exists('next_checkpoint', $metadata) && $metadata['next_checkpoint'] !== null
                    ? ['checkpoint' => $metadata['next_checkpoint']]
                    : []),
            ])->save();
        } catch (Throwable $exception) {
            $this->fail($schedule, $exception);

            throw $exception;
        }
    }

    public function pause(ApiOperationSyncSchedule $schedule): ApiOperationSyncSchedule
    {
        $this->assertSchedulable($schedule->operation()->firstOrFail());

        return tap($schedule)->forceFill([
            'is_enabled' => false,
            'paused_at' => now(),
            'next_run_at' => null,
        ])->save();
    }

    public function resume(ApiOperationSyncSchedule $schedule): ApiOperationSyncSchedule
    {
        $operation = $schedule->operation()->firstOrFail();
        $this->assertSchedulable($operation);

        if ($schedule->locked_until?->isFuture()) {
            throw new ImportException('This API operation is currently synchronizing.');
        }
        $frequency = $schedule->frequency;

        if ($frequency === ApiOperationSyncFrequency::Manual) {
            return tap($schedule)->forceFill([
                'is_enabled' => false,
                'paused_at' => null,
                'next_run_at' => null,
            ])->save();
        }

        return tap($schedule)->forceFill([
            'is_enabled' => true,
            'paused_at' => null,
            'next_run_at' => $this->calculator->nextFromNow($frequency),
        ])->save();
    }

    public function configure(
        ApiOperationSyncSchedule $schedule,
        ApiOperationSyncFrequency $frequency,
        ApiOperationSyncStrategy $strategy,
        ?Dataset $dataset,
        array $configuration,
    ): ApiOperationSyncSchedule {
        $operation = $schedule->operation()->firstOrFail();
        $this->assertSchedulable($operation);

        if ($schedule->locked_until?->isFuture()) {
            throw new ImportException('This API operation is currently synchronizing.');
        }

        if ($dataset !== null) {
            $this->assertDataset($operation, $dataset);
        }

        $this->assertIncrementalConfiguration($operation, $strategy, $configuration);

        $enabled = $frequency !== ApiOperationSyncFrequency::Manual;

        if ($enabled && $dataset === null && $schedule->dataset_id === null) {
            throw new ImportException('Choose a target dataset before enabling this synchronization.');
        }

        return tap($schedule)->forceFill([
            'dataset_id' => $dataset?->id ?? $schedule->dataset_id,
            'frequency' => $frequency,
            'strategy' => $strategy,
            'is_enabled' => $enabled,
            'paused_at' => null,
            'next_run_at' => $enabled ? $this->calculator->nextFromNow($frequency) : null,
            'configuration' => $configuration,
        ])->save();
    }

    private function claim(int $id, bool $automatic): ?ApiOperationSyncSchedule
    {
        return DB::transaction(function () use ($id, $automatic): ?ApiOperationSyncSchedule {
            $schedule = ApiOperationSyncSchedule::query()
                ->with('operation.dataSource')
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($schedule === null || $schedule->locked_until?->isFuture()) {
                return null;
            }

            $this->assertSchedulable($schedule->operation);

            if ($automatic && (! $schedule->is_enabled
                || $schedule->frequency === ApiOperationSyncFrequency::Manual
                || ($schedule->next_run_at?->isFuture() && $schedule->locked_until === null))) {
                return null;
            }

            $values = [
                'last_started_at' => now(),
                'locked_until' => now()->addMinutes(self::CLAIM_MINUTES),
            ];

            if ($automatic) {
                $values['next_run_at'] = $this->calculator->nextRunAt(
                    $schedule->frequency,
                    $schedule->next_run_at ?? now(),
                );
            }

            $schedule->forceFill($values)->save();

            return $schedule->fresh(['operation.dataSource', 'dataset']);
        });
    }

    private function fail(ApiOperationSyncSchedule $schedule, Throwable $exception): void
    {
        $message = $exception instanceof ImportException
            ? $exception->getMessage()
            : 'The synchronized API operation failed.';

        $schedule->forceFill([
            'last_completed_at' => now(),
            'last_failure_at' => now(),
            'consecutive_failures' => ((int) $schedule->consecutive_failures) + 1,
            'last_error' => Str::limit($message, 1000),
            'locked_until' => null,
        ])->save();
    }

    private function assertSchedulable(ApiOperation $operation): void
    {
        $operation->loadMissing('dataSource');

        if (! $operation->is_enabled
            || $operation->execution_mode !== ApiOperationMode::Read->value
            || $operation->type !== 'query'
            || ($operation->response_mapping['sync_mode'] ?? null) !== ApiOperationSyncStrategy::FullSnapshot->value
            || ! in_array($operation->dataSource?->type, ['rest_api', 'graphql_api'], true)) {
            throw new ImportException('Only enabled synced REST and GraphQL query operations can synchronize.');
        }
    }

    private function assertDataset(ApiOperation $operation, Dataset $dataset): void
    {
        if ((int) $dataset->team_id !== (int) $operation->dataSource?->team_id
            || (int) $dataset->data_source_id !== (int) $operation->data_source_id) {
            throw new ImportException('Choose a dataset belonging to this API operation connection.');
        }
    }

    private function assertIncrementalConfiguration(
        ApiOperation $operation,
        ApiOperationSyncStrategy $strategy,
        array $configuration,
    ): void {
        if ($strategy === ApiOperationSyncStrategy::FullSnapshot) {
            return;
        }

        $values = $configuration[$strategy->value] ?? $configuration;
        $target = is_array($values) ? (string) ($values['target'] ?? 'query') : 'query';
        $name = is_array($values)
            ? (string) ($values['name'] ?? $values['parameter'] ?? $values['variable'] ?? '')
            : '';
        $responsePath = is_array($values)
            ? (string) ($values['response_path'] ?? $values['checkpoint_path'] ?? '')
            : '';

        if ($name === '' || $responsePath === '') {
            throw new ImportException('Configure the incremental checkpoint request and response paths.');
        }

        if ($operation->dataSource?->type === 'graphql_api' && $target !== 'graphql_variable') {
            throw new ImportException('GraphQL incremental checkpoints must use a GraphQL variable.');
        }

        if ($operation->dataSource?->type === 'rest_api' && $target !== 'query') {
            throw new ImportException('REST incremental checkpoints must use a query parameter.');
        }

        $pagination = data_get($operation->response_mapping, 'pagination', []);
        $paginationName = is_array($pagination)
            ? (string) ($pagination['cursor_variable'] ?? $pagination['parameter'] ?? '')
            : '';

        if ($paginationName !== '' && $paginationName === $name) {
            throw new ImportException('The incremental checkpoint must use a different name from the pagination cursor.');
        }
    }

    private function targetDataset(ApiOperationSyncSchedule $schedule): Dataset
    {
        if ($schedule->dataset instanceof Dataset) {
            $this->assertDataset($schedule->operation, $schedule->dataset);

            return $schedule->dataset;
        }

        $datasets = $schedule->operation->dataSource?->datasets()
            ->where('team_id', $schedule->operation->dataSource->team_id)
            ->orderBy('id')
            ->get() ?? new Collection;

        if ($datasets->count() !== 1) {
            throw new ImportException('Choose one target dataset before enabling this synchronization.');
        }

        return $datasets->firstOrFail();
    }
}
