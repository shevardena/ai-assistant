<?php

namespace App\Services\Imports;

use App\Enums\ApiOperationSyncStrategy;
use App\Models\ApiOperation;
use App\Models\ApiOperationSyncSchedule;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DataSource;
use App\Models\SourceRun;
use App\Services\Api\ApiResponseInspector;
use App\Services\Api\GraphqlRequestExecutor;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Imports\Exceptions\RowMappingException;
use App\Services\ResourceStatusService;
use App\Services\Teams\TeamNotificationService;
use App\Services\Typesense\TypesenseDatasetSync;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RestApiImportService
{
    public function __construct(
        private readonly DatasetRecordMapper $recordMapper,
        private readonly SourcePathResolver $sourcePathResolver,
        private readonly ApiResponseInspector $responseInspector,
        private readonly RestApiRequestExecutor $requestExecutor,
        private readonly GraphqlRequestExecutor $graphqlRequestExecutor,
        private readonly DatasetSnapshotReconciler $snapshotReconciler,
        private readonly TypesenseDatasetSync $typesenseDatasetSync,
        private readonly ResourceStatusService $resourceStatusService,
        private readonly TeamNotificationService $notifications,
    ) {}

    /**
     * Fetch and import one complete REST API snapshot.
     */
    public function handle(Dataset $dataset, ApiOperation $operation, ?ApiOperationSyncSchedule $schedule = null): SourceRun
    {
        $dataset->load('dataSource');
        $fields = $dataset->fields()->get();
        $dataSource = $dataset->dataSource;

        $this->validateImport($dataset, $dataSource, $operation, $fields, allowEmptyFields: true);

        if ($fields->isEmpty()) {
            $fields = $this->discoverAndCreateFields($dataset, $operation, $dataSource);
        }

        $this->validateImport($dataset, $dataSource, $operation, $fields);

        $sourceRun = $dataset->sourceRuns()->create([
            'data_source_id' => $dataSource->id,
            'type' => 'api_import',
            'status' => 'pending',
            'metadata' => [
                'api_operation_id' => $operation->id,
                'api_operation_key' => $operation->key,
                'pages_fetched' => 0,
                'seen_external_id_count' => 0,
                'duplicate_external_id_count' => 0,
                'http_statuses' => [],
                'strategy' => ($schedule?->strategy ?? ApiOperationSyncStrategy::FullSnapshot)->value,
            ],
            'started_at' => now(),
        ]);
        $sourceRun->update(['status' => 'running']);
        $this->resourceStatusService->markDataSourceSyncing($dataSource);
        $this->resourceStatusService->markDatasetProcessing($dataset);
        $fetched = null;

        try {
            $fetched = $this->fetchRecords($dataset, $operation, $dataSource, $fields, $schedule);

            if ($fetched['rowsRead'] > 0 && $fetched['records'] === []) {
                throw new ImportException('No valid records were imported.');
            }

            DB::transaction(function () use ($dataset, $dataSource, $sourceRun, $fetched): void {
                $result = $fetched['strategy'] === ApiOperationSyncStrategy::FullSnapshot->value
                    ? $this->snapshotReconciler->writeSnapshot(
                        $dataset,
                        $fetched['records'],
                        $fetched['seenExternalIds'],
                    )
                    : $this->snapshotReconciler->writeIncremental($dataset, $fetched['records']);

                $sourceRun->update([
                    'status' => 'completed',
                    'rows_read' => $fetched['rowsRead'],
                    'rows_written' => $result['rowsWritten'],
                    'rows_failed' => $fetched['rowsFailed'],
                    'metadata' => [
                        'api_operation_id' => Arr::get((array) $sourceRun->getAttribute('metadata'), 'api_operation_id'),
                        'api_operation_key' => Arr::get((array) $sourceRun->getAttribute('metadata'), 'api_operation_key'),
                        'pages_fetched' => $fetched['pagesFetched'],
                        'seen_external_id_count' => count($fetched['seenExternalIds']),
                        'duplicate_external_id_count' => 0,
                        'records_deactivated' => $result['recordsDeactivated'],
                        'records_reactivated' => $result['recordsReactivated'],
                        'http_statuses' => $fetched['httpStatuses'],
                        'row_error_count' => $fetched['rowsFailed'],
                        'row_errors' => $fetched['rowErrors'],
                        'strategy' => $fetched['strategy'],
                        'next_checkpoint' => $fetched['nextCheckpoint'],
                    ],
                    'finished_at' => now(),
                ]);

                $this->resourceStatusService->markDataSourceReady($dataSource, now());
                $this->resourceStatusService->markDatasetReady($dataset);

            });

            $this->typesenseDatasetSync->syncAfterImport($dataset, throwOnFailure: $schedule !== null);

            return $sourceRun->fresh();
        } catch (Throwable $exception) {
            if (is_array($fetched)) {
                $sourceRun->update([
                    'rows_read' => $fetched['rowsRead'],
                    'rows_failed' => $fetched['rowsFailed'],
                    'metadata' => [
                        ...(array) $sourceRun->getAttribute('metadata'),
                        'pages_fetched' => $fetched['pagesFetched'],
                        'seen_external_id_count' => count($fetched['seenExternalIds']),
                        'http_statuses' => $fetched['httpStatuses'],
                        'row_error_count' => $fetched['rowsFailed'],
                        'row_errors' => $fetched['rowErrors'],
                    ],
                ]);
            }

            $sourceRun->update([
                'status' => 'failed',
                'error' => Str::limit(
                    $exception instanceof ImportException ? $exception->getMessage() : 'The API import failed.',
                    1000,
                ),
                'finished_at' => now(),
            ]);
            $this->notifications->notifyDataImportFailed($sourceRun->fresh() ?? $sourceRun);
            $this->resourceStatusService->markDataSourceError($dataSource);
            $this->resourceStatusService->markDatasetError($dataset);

            Log::error('REST API import failed.', [
                'source_run_id' => $sourceRun->id,
                'data_source_id' => $dataSource->id,
                'dataset_id' => $dataset->id,
                'api_operation_id' => $operation->id,
                'exception' => $exception,
            ]);

            if ($exception instanceof ImportException) {
                throw $exception;
            }

            throw new ImportException('The API import failed. Please review the import history.', previous: $exception);
        }
    }

    /**
     * @param  Collection<int, DatasetField>  $fields
     * @return array{records: list<array<string, mixed>>, rowsRead: int, rowsFailed: int, rowErrors: list<array{row: int, errors: list<array{field: string, message: string}>}>, seenExternalIds: array<string, true>, pagesFetched: int, httpStatuses: list<int>, strategy: string, nextCheckpoint: mixed}
     */
    private function fetchRecords(
        Dataset $dataset,
        ApiOperation $operation,
        DataSource $dataSource,
        Collection $fields,
        ?ApiOperationSyncSchedule $schedule = null,
    ): array {
        $mapping = $this->mapping($operation, 'response_mapping');
        $recordsPath = $mapping['records_path'] ?? null;
        $pagination = is_array($mapping['pagination'] ?? null) ? $mapping['pagination'] : [];
        $paginationType = $pagination['type'] ?? 'none';
        $maxPages = min(
            max(1, (int) ($pagination['max_pages'] ?? config('rest-sources.max_pages', 100))),
            (int) config('rest-sources.max_pages', 100),
        );
        $maxRecords = max(1, (int) config('rest-sources.max_records', 10000));
        $strategy = $schedule?->strategy ?? ApiOperationSyncStrategy::FullSnapshot;
        $checkpoint = $schedule?->checkpoint;
        $incrementalConfig = $this->incrementalConfiguration($schedule, $strategy);
        $initialCheckpoint = $incrementalConfig['initial_value'] ?? null;
        $requestCheckpoint = $checkpoint ?? ($initialCheckpoint === '' ? null : $initialCheckpoint);
        $query = $dataSource->type === 'graphql_api'
            ? []
            : $this->query($operation, $incrementalConfig, $requestCheckpoint);
        $nextUrl = null;
        $cursor = null;
        $cursorVariable = (string) ($pagination['cursor_variable'] ?? 'after');
        $seenCursors = [];
        $page = 0;
        $records = [];
        $rowsRead = 0;
        $rowsFailed = 0;
        $rowErrors = [];
        $seenExternalIds = [];
        $httpStatuses = [];
        $nextCheckpoint = null;

        if (! is_string($recordsPath) || $recordsPath === '') {
            throw new ImportException('Configure the API response records path before importing.');
        }

        $allowedPagination = $dataSource->type === 'graphql_api'
            ? ['none', 'relay_cursor']
            : ['none', 'page', 'next_url'];

        if (! in_array($paginationType, $allowedPagination, true)) {
            throw new ImportException('The configured API pagination mode is not supported.');
        }

        if ($dataSource->type === 'graphql_api' && $paginationType === 'relay_cursor') {
            if (! is_string($pagination['has_next_path'] ?? null) || $pagination['has_next_path'] === ''
                || ! is_string($pagination['cursor_path'] ?? null) || $pagination['cursor_path'] === '') {
                throw new ImportException('Configure the GraphQL relay pagination paths before importing.');
            }

            if (! is_string($pagination['cursor_variable'] ?? null) || $pagination['cursor_variable'] === '') {
                throw new ImportException('Configure the GraphQL cursor variable before importing.');
            }
        }

        while (true) {
            if ($page >= $maxPages) {
                throw new ImportException('The API pagination limit was exceeded.');
            }

            $page++;
            if ($dataSource->type === 'graphql_api') {
                $response = $this->graphqlRequestExecutor->execute(
                    $operation,
                    $dataSource,
                    variableOverrides: [
                        ...$this->graphqlCheckpointVariables($incrementalConfig, $requestCheckpoint),
                        ...($cursor === null ? [] : [$cursorVariable => $cursor]),
                    ],
                );
                $responseData = $response['data'];
            } else {
                $response = $this->requestExecutor->execute($operation, $dataSource, $nextUrl, $query);
                $responseData = $response['data'];
            }

            $httpStatuses[] = $response['status'];
            $responseCheckpointPath = $incrementalConfig['response_path'] ?? null;

            if (is_string($responseCheckpointPath) && $responseCheckpointPath !== '') {
                $candidate = $this->pathFromGraphqlOrResponse(
                    $responseData,
                    $responseCheckpointPath,
                    $dataSource->type === 'graphql_api',
                );

                if (is_scalar($candidate) && (string) $candidate !== '') {
                    $nextCheckpoint = $candidate;
                }
            }
            $pageRecords = $this->pathFromGraphqlOrResponse($responseData, $recordsPath, $dataSource->type === 'graphql_api');

            if (! is_array($pageRecords)) {
                throw new ImportException('The configured records path did not resolve to an array.');
            }

            if ($pageRecords === []) {
                break;
            }

            foreach ($pageRecords as $row) {
                if (! is_array($row)) {
                    throw new ImportException('Each API record must be a JSON object.');
                }

                $rowsRead++;

                if ($rowsRead > $maxRecords) {
                    throw new ImportException('The API record limit was exceeded.');
                }

                $externalId = $this->recordMapper->externalId($dataset, $row, $fields);

                if ($externalId !== null) {
                    if (array_key_exists($externalId, $seenExternalIds)) {
                        throw new ImportException('The API response contains duplicate external IDs.');
                    }

                    $seenExternalIds[$externalId] = true;
                }

                try {
                    $mapped = $this->recordMapper->map($dataset, $row, $fields);
                    $records[] = $this->recordValues($dataset, $mapped);
                } catch (RowMappingException $exception) {
                    $rowsFailed++;

                    if (count($rowErrors) < 100) {
                        $rowErrors[] = [
                            'row' => $rowsRead,
                            'errors' => $exception->errors,
                        ];
                    }
                }
            }

            if ($paginationType === 'none') {
                break;
            }

            if ($paginationType === 'page') {
                if ($page >= $maxPages) {
                    throw new ImportException('The API pagination limit was exceeded.');
                }

                $parameter = $pagination['parameter'] ?? 'page';
                $start = (int) ($pagination['start'] ?? 1);

                if (! is_string($parameter) || $parameter === '') {
                    throw new ImportException('The API page parameter is invalid.');
                }

                $query[$parameter] = $start + $page;

                continue;
            }

            if ($paginationType === 'relay_cursor') {
                $hasNext = $this->pathFromGraphqlOrResponse(
                    $responseData,
                    (string) $pagination['has_next_path'],
                    true,
                );
                $nextCursor = $this->pathFromGraphqlOrResponse(
                    $responseData,
                    (string) $pagination['cursor_path'],
                    true,
                );

                if ($hasNext !== true) {
                    break;
                }

                if (! is_string($nextCursor) || $nextCursor === '' || in_array($nextCursor, $seenCursors, true)) {
                    break;
                }

                $seenCursors[] = $nextCursor;
                $cursor = $nextCursor;

                continue;
            }

            $nextPath = $pagination['next_path'] ?? null;
            $nextValue = is_string($nextPath) && $nextPath !== ''
                ? $this->sourcePathResolver->get($response['data'], $nextPath)
                : null;

            if ($nextValue === null || $nextValue === '') {
                break;
            }

            if (! is_string($nextValue)) {
                throw new ImportException('The configured next API URL is invalid.');
            }

            $nextUrl = $nextValue;
            $query = [];
        }

        return [
            'records' => $records,
            'rowsRead' => $rowsRead,
            'rowsFailed' => $rowsFailed,
            'rowErrors' => $rowErrors,
            'seenExternalIds' => $seenExternalIds,
            'pagesFetched' => $page,
            'httpStatuses' => array_slice($httpStatuses, 0, 20),
            'strategy' => $strategy->value,
            'nextCheckpoint' => $nextCheckpoint,
        ];
    }

    /** @param array<string, mixed> $response */
    private function pathFromGraphqlOrResponse(array $response, string $path, bool $graphql): mixed
    {
        $value = $this->sourcePathResolver->get($response, $path);

        if ($value !== null || ! $graphql) {
            return $value;
        }

        $data = $response['data'] ?? null;

        return is_array($data)
            ? $this->sourcePathResolver->get($data, $path)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function query(ApiOperation $operation, array $incrementalConfig = [], mixed $checkpoint = null): array
    {
        $mapping = $this->mapping($operation, 'request_mapping');
        $rawQuery = is_array($mapping['fixed']['query'] ?? null)
            ? $mapping['fixed']['query']
            : ($mapping['query'] ?? $mapping);

        if (! is_array($rawQuery)) {
            throw new ImportException('The API query configuration is invalid.');
        }

        $query = [];

        foreach ($rawQuery as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                throw new ImportException('The API query configuration must contain scalar values.');
            }

            $query[$key] = $value;
        }

        $pagination = $this->mapping($operation, 'response_mapping');
        $paginationConfig = is_array($pagination['pagination'] ?? null) ? $pagination['pagination'] : [];

        if (($paginationConfig['type'] ?? null) === 'page') {
            $parameter = $paginationConfig['parameter'] ?? 'page';
            $query[$parameter] = (int) ($paginationConfig['start'] ?? 1);
        }

        if (($incrementalConfig['target'] ?? 'query') === 'query'
            && $checkpoint !== null
            && is_string($incrementalConfig['name'] ?? null)
            && $incrementalConfig['name'] !== '') {
            $query[$incrementalConfig['name']] = $this->formatCheckpoint($checkpoint, $incrementalConfig);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function incrementalConfiguration(
        ?ApiOperationSyncSchedule $schedule,
        ApiOperationSyncStrategy $strategy,
    ): array {
        if ($schedule === null || $strategy === ApiOperationSyncStrategy::FullSnapshot) {
            return [];
        }

        $configuration = (array) $schedule->configuration;
        $configured = $configuration[$strategy->value] ?? $configuration;

        if (! is_array($configured)) {
            return [];
        }

        return [
            'target' => (string) ($configured['target'] ?? ($strategy === ApiOperationSyncStrategy::Cursor ? 'query' : 'query')),
            'name' => (string) ($configured['name'] ?? $configured['parameter'] ?? $configured['variable'] ?? ''),
            'response_path' => (string) ($configured['response_path'] ?? $configured['checkpoint_path'] ?? $configured['next_path'] ?? ''),
            'format' => (string) ($configured['format'] ?? 'iso8601'),
            'initial_value' => $configured['initial_value'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function graphqlCheckpointVariables(array $configuration, mixed $checkpoint): array
    {
        if ($checkpoint === null || ($configuration['target'] ?? null) !== 'graphql_variable') {
            return [];
        }

        $name = $configuration['name'] ?? '';

        return is_string($name) && $name !== ''
            ? [$name => $this->formatCheckpoint($checkpoint, $configuration)]
            : [];
    }

    private function formatCheckpoint(mixed $checkpoint, array $configuration): mixed
    {
        if (is_array($checkpoint) && array_key_exists('value', $checkpoint)) {
            $checkpoint = $checkpoint['value'];
        }

        $format = $configuration['format'] ?? 'iso8601';

        if (in_array($format, ['unix_seconds', 'unix_milliseconds'], true)) {
            if (is_numeric($checkpoint)) {
                return $format === 'unix_milliseconds'
                    ? (int) $checkpoint
                    : (int) $checkpoint;
            }

            if (is_string($checkpoint)) {
                try {
                    $date = CarbonImmutable::parse($checkpoint);

                    return $format === 'unix_milliseconds'
                        ? $date->valueOf()
                        : $date->timestamp;
                } catch (Throwable) {
                    return $checkpoint;
                }
            }
        }

        return $checkpoint;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapping(ApiOperation $operation, string $attribute): array
    {
        $mapping = $operation->getAttribute($attribute);

        return is_array($mapping) ? $mapping : [];
    }

    /**
     * @param  array{external_id: string, payload: array<string, mixed>, checksum: string, searchable_text: string}  $mapped
     * @return array<string, mixed>
     */
    private function recordValues(Dataset $dataset, array $mapped): array
    {
        return [
            'dataset_id' => $dataset->id,
            'external_id' => $mapped['external_id'],
            'payload' => json_encode($mapped['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'searchable_text' => $mapped['searchable_text'],
            'checksum' => $mapped['checksum'],
            'is_active' => true,
            'source_updated_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param  Collection<int, DatasetField>  $fields
     */
    private function validateImport(
        Dataset $dataset,
        ?DataSource $dataSource,
        ApiOperation $operation,
        Collection $fields,
        bool $allowEmptyFields = false,
    ): void {
        if (! $dataSource || $dataSource->team_id !== $dataset->team_id || ! in_array($dataSource->type, ['rest_api', 'graphql_api'], true)) {
            throw new ImportException('Only datasets connected to supported API data sources can be imported.');
        }

        if ($operation->data_source_id !== $dataSource->id || ! $operation->is_enabled) {
            throw new ImportException('The selected API operation does not belong to this dataset data source.');
        }

        if ($fields->isEmpty() && ! $allowEmptyFields) {
            throw new ImportException('Add at least one Dataset Field mapping before importing.');
        }

        if ($dataset->primary_key_path === null || $dataset->primary_key_path === '') {
            throw new ImportException('Configure the dataset primary key path before importing.');
        }

        if ($fields->isNotEmpty()) {
            $this->recordMapper->validatePrimaryKeyMapping($dataset, $fields);
        }

        $syncMode = Arr::get($this->mapping($operation, 'response_mapping'), 'sync_mode');

        if ($syncMode !== 'full_snapshot') {
            throw new ImportException('The API operation is not configured for synchronized imports.');
        }
    }

    /**
     * Discover scalar fields from the first API response for a new dataset.
     *
     * @return Collection<int, DatasetField>
     */
    private function discoverAndCreateFields(
        Dataset $dataset,
        ApiOperation $operation,
        DataSource $dataSource,
    ): Collection {
        $response = $this->requestExecutor->execute(
            $operation,
            $dataSource,
            query: $this->query($operation),
        );
        $recordsPath = Arr::get($this->mapping($operation, 'response_mapping'), 'records_path');
        $inspection = $this->responseInspector->inspect($response['data'], is_string($recordsPath) ? $recordsPath : null);
        $discoveredFields = $inspection['fields'];

        if ($discoveredFields === []) {
            throw new ImportException('The API response does not contain scalar fields that can be mapped automatically.');
        }

        $usedKeys = [];

        return DB::transaction(function () use ($dataset, $discoveredFields, &$usedKeys): Collection {
            $fields = new Collection;

            foreach ($discoveredFields as $position => $discoveredField) {
                $sourcePath = (string) ($discoveredField['path'] ?? '');

                if ($sourcePath === '') {
                    continue;
                }

                $key = $this->uniqueFieldKey($sourcePath, $usedKeys);
                $dataType = $this->datasetFieldType($discoveredField);

                $fields->push($dataset->fields()->create([
                    'source_path' => $sourcePath,
                    'key' => $key,
                    'canonical_name' => $key,
                    'label' => Str::headline($key),
                    'data_type' => $dataType,
                    'semantic_type' => $this->semanticType($key),
                    'description' => null,
                    'is_searchable' => in_array($dataType, ['string', 'url'], true),
                    'is_filterable' => in_array($dataType, ['integer', 'decimal', 'boolean', 'date', 'datetime'], true),
                    'is_sortable' => in_array($dataType, ['integer', 'decimal', 'date', 'datetime'], true),
                    'is_semantic' => false,
                    'is_displayable' => true,
                    'normalizer' => null,
                    'config' => [],
                    'position' => $position,
                ]));
            }

            return $fields;
        });
    }

    /**
     * @param  array<string, mixed>  $discoveredField
     */
    private function datasetFieldType(array $discoveredField): string
    {
        $type = (string) ($discoveredField['type'] ?? 'string');
        $samples = is_array($discoveredField['sample'] ?? null) ? $discoveredField['sample'] : [];

        if ($type === 'number') {
            return 'decimal';
        }

        if ($type === 'string' && $samples !== [] && collect($samples)->every(
            fn (mixed $sample): bool => is_string($sample) && filter_var($sample, FILTER_VALIDATE_URL) !== false,
        )) {
            return 'url';
        }

        return in_array($type, ['string', 'integer', 'boolean'], true) ? $type : 'string';
    }

    /**
     * @param  array<string, true>  $usedKeys
     */
    private function uniqueFieldKey(string $sourcePath, array &$usedKeys): string
    {
        $base = Str::slug(Str::afterLast($sourcePath, '.'), '_');
        $base = $base !== '' ? $base : 'field';
        $key = $base;
        $suffix = 2;

        while (isset($usedKeys[$key])) {
            $key = $base.'_'.$suffix;
            $suffix++;
        }

        $usedKeys[$key] = true;

        return $key;
    }

    private function semanticType(string $key): ?string
    {
        return preg_match('/(?:^|_)(price|cost|amount|total)(?:$|_)/i', $key) === 1
            ? 'price'
            : null;
    }
}
