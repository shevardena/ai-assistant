<?php

namespace App\Services\Imports;

use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\SourceFile;
use App\Models\SourceRun;
use App\Services\Imports\Contracts\SourceFileParser;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Imports\Exceptions\RowMappingException;
use App\Services\Imports\Parsers\CsvFileParser;
use App\Services\Imports\Parsers\JsonFileParser;
use App\Services\Imports\Parsers\XlsxFileParser;
use App\Services\ResourceStatusService;
use App\Services\Teams\TeamNotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class FileImportService
{
    private const BATCH_SIZE = 100;

    private const MAX_ROW_ERROR_SAMPLES = 50;

    /**
     * @var array<string, SourceFileParser>
     */
    private array $parsers;

    public function __construct(
        CsvFileParser $csvFileParser,
        JsonFileParser $jsonFileParser,
        XlsxFileParser $xlsxFileParser,
        private readonly DatasetRecordMapper $recordMapper,
        private readonly DatasetSnapshotReconciler $snapshotReconciler,
        private readonly ResourceStatusService $resourceStatusService,
        private readonly TeamNotificationService $notifications,
    ) {
        $this->parsers = [
            'csv' => $csvFileParser,
            'json' => $jsonFileParser,
            'xlsx' => $xlsxFileParser,
        ];
    }

    /**
     * Import one uploaded source file into a dataset.
     */
    public function handle(Dataset $dataset, int $sourceFileId): SourceRun
    {
        $dataset->load('dataSource');
        $sourceFile = SourceFile::query()->find($sourceFileId);
        $fields = $dataset->fields()->get();

        $this->validateImport($dataset, $sourceFile, $fields);

        if (! $sourceFile instanceof SourceFile) {
            throw new ImportException('The selected source file could not be found.');
        }

        $dataSource = $dataset->dataSource;
        $extension = $this->extension($sourceFile);
        $parser = $this->parsers[$extension];
        $sourceRun = $dataset->sourceRuns()->create([
            'data_source_id' => $dataSource->id,
            'type' => 'import',
            'status' => 'pending',
            'metadata' => [
                'source_file_id' => $sourceFile->id,
                'source_file_name' => $sourceFile->original_name,
                'parser' => $extension,
            ],
        ]);

        Log::info('dataset_import.started', [
            'dataset_id' => $dataset->id,
            'source_run_id' => $sourceRun->id,
            'source_file_id' => $sourceFile->id,
            'source_file_name' => $sourceFile->original_name,
            'parser' => $extension,
        ]);

        $sourceFile->update(['status' => 'processing']);

        try {
            $sourceRun->update([
                'status' => 'running',
                'started_at' => now(),
            ]);
            $this->resourceStatusService->markDataSourceSyncing($dataSource);
            $this->resourceStatusService->markDatasetProcessing($dataset);

            $this->validateUniqueExternalIds($dataset, $fields, $parser, $sourceFile);
            $result = $this->processRows($dataset, $fields, $parser, $sourceFile);
            $status = $this->importStatus($result['rowsRead'], $result['rowsWritten'], $result['rowsFailed']);
            $error = match ($status) {
                'partial' => 'Import completed with some invalid rows. Review the import diagnostics.',
                'validation_failed' => 'No valid records were imported. Review the import diagnostics.',
                default => null,
            };
            $reconciliation = ['recordsDeactivated' => 0];

            if (in_array($status, ['completed', 'partial'], true)) {
                $reconciliation = $this->reconcileSnapshot($dataset, $result['seenExternalIds']);
            }

            if (in_array($status, ['completed', 'partial'], true)) {
                $this->resourceStatusService->markDataSourceReady($dataSource, now());
                $this->resourceStatusService->markDatasetReady($dataset);
            } else {
                $this->resourceStatusService->markDataSourceError($dataSource);
                $this->resourceStatusService->markDatasetError($dataset);
            }

            $sourceRun->update([
                'status' => $status,
                'rows_read' => $result['rowsRead'],
                'rows_written' => $result['rowsWritten'],
                'rows_failed' => $result['rowsFailed'],
                'error' => $error,
                'metadata' => [
                    'source_file_id' => $sourceFile->id,
                    'source_file_name' => $sourceFile->original_name,
                    'parser' => $extension,
                    'row_errors' => $result['rowErrors'],
                    'row_error_count' => $result['rowsFailed'],
                    'error_summary' => $this->errorSummary(
                        $result['rowErrors'],
                        $result['rowsFailed'],
                        $result['errorTypeCounts'],
                    ),
                    'seen_external_id_count' => count($result['seenExternalIds']),
                    'duplicate_external_id_count' => $result['duplicateExternalIdCount'],
                    'records_deactivated' => $reconciliation['recordsDeactivated'],
                    'records_reactivated' => $result['recordsReactivated'],
                ],
                'finished_at' => now(),
            ]);
            $sourceFile->update(['status' => in_array($status, ['completed', 'partial'], true) ? 'ready' : 'failed']);

            if ($result['rowsFailed'] > 0) {
                $this->notifications->notifyDataImportFailed($sourceRun->fresh() ?? $sourceRun);
            }

            Log::info('dataset_import.completed', [
                'dataset_id' => $dataset->id,
                'source_run_id' => $sourceRun->id,
                'source_file_id' => $sourceFile->id,
                'status' => $status,
                'rows_read' => $result['rowsRead'],
                'rows_written' => $result['rowsWritten'],
                'rows_failed' => $result['rowsFailed'],
                'error_type_count' => count($result['errorTypeCounts']),
            ]);

            return $sourceRun->fresh();
        } catch (Throwable $exception) {
            Log::error('dataset_import.failed', [
                'dataset_id' => $dataset->id,
                'source_run_id' => $sourceRun->id,
                'source_file_id' => $sourceFile->id,
                'stage' => $this->failureStage($exception),
                'error_code' => $this->errorCode($exception),
                'exception' => $exception,
            ]);

            try {
                $sourceRun->update([
                    'status' => 'failed',
                    'error' => Str::limit($exception instanceof ImportException ? $exception->getMessage() : 'The import failed.', 1000),
                    'metadata' => [
                        ...(array) $sourceRun->metadata,
                        'failure_stage' => $this->failureStage($exception),
                        'error_summary' => [
                            'total_errors' => 1,
                            'error_types' => [$this->errorCode($exception) => 1],
                            'samples' => [],
                        ],
                    ],
                    'finished_at' => now(),
                ]);
                $sourceFile->update(['status' => 'failed']);
                $this->resourceStatusService->markDataSourceError($dataSource);
                $this->resourceStatusService->markDatasetError($dataset);
                $this->notifications->notifyDataImportFailed($sourceRun->fresh() ?? $sourceRun);
            } catch (Throwable $stateException) {
                Log::error('dataset_import.failure_state_update_failed', [
                    'dataset_id' => $dataset->id,
                    'source_run_id' => $sourceRun->id,
                    'source_file_id' => $sourceFile->id,
                    'exception' => $stateException,
                ]);
            }

            Log::error('File import failed.', [
                'source_run_id' => $sourceRun->id,
                'dataset_id' => $dataset->id,
                'source_file_id' => $sourceFile->id,
                'exception' => $exception,
            ]);

            if ($exception instanceof ImportException) {
                throw $exception;
            }

            throw new ImportException('The file import failed. Please review the import history.', previous: $exception);
        }
    }

    /**
     * Reject duplicate identifiers before any DatasetRecord upserts begin.
     *
     * The parser is intentionally replayed for the processing pass. This keeps
     * fatal duplicate errors from leaving earlier batches partially imported.
     *
     * @param  Collection<int, DatasetField>  $fields
     */
    private function validateUniqueExternalIds(
        Dataset $dataset,
        Collection $fields,
        SourceFileParser $parser,
        SourceFile $sourceFile,
    ): void {
        $seenExternalIds = [];

        foreach ($parser->rows($sourceFile) as $row) {
            $externalId = $this->recordMapper->externalId($dataset, $row, $fields);

            if ($externalId === null) {
                continue;
            }

            if (array_key_exists($externalId, $seenExternalIds)) {
                throw new ImportException(
                    'The source file contains duplicate external IDs.',
                    stage: 'validation',
                    errorCode: 'duplicate_external_id',
                );
            }

            $seenExternalIds[$externalId] = true;
        }
    }

    /**
     * @param  Collection<int, DatasetField>  $fields
     * @return array{rowsRead: int, rowsWritten: int, rowsFailed: int, rowErrors: list<array{row: int, errors: list<array<string, mixed>>}>, errorTypeCounts: array<string, int>, seenExternalIds: array<string, true>, duplicateExternalIdCount: int, recordsReactivated: int}
     */
    private function processRows(
        Dataset $dataset,
        Collection $fields,
        SourceFileParser $parser,
        SourceFile $sourceFile,
    ): array {
        $rowsRead = 0;
        $rowsWritten = 0;
        $rowsFailed = 0;
        $rowErrors = [];
        $errorTypeCounts = [];
        $batch = [];
        $seenExternalIds = [];
        $duplicateExternalIdCount = 0;
        $recordsReactivated = 0;

        foreach ($parser->rows($sourceFile) as $row) {
            $rowsRead++;
            $externalId = $this->recordMapper->externalId($dataset, $row, $fields);

            if ($externalId !== null) {
                if (array_key_exists($externalId, $seenExternalIds)) {
                    $duplicateExternalIdCount++;
                }

                $seenExternalIds[$externalId] = true;
            }

            try {
                $mapped = $this->recordMapper->map($dataset, $row, $fields);
                $batch[] = $this->recordValues($dataset, $mapped);

                if (count($batch) >= self::BATCH_SIZE) {
                    $batchResult = $this->writeBatch($dataset, $batch);
                    $rowsWritten += $batchResult['rowsWritten'];
                    $recordsReactivated += $batchResult['recordsReactivated'];
                    $batch = [];
                }
            } catch (RowMappingException $exception) {
                $rowsFailed++;

                foreach ($exception->errors as $error) {
                    $errorCode = $error['error_code'];
                    $errorTypeCounts[$errorCode] = ($errorTypeCounts[$errorCode] ?? 0) + 1;
                }

                if (count($rowErrors) < self::MAX_ROW_ERROR_SAMPLES) {
                    $rowErrors[] = [
                        'row' => $rowsRead,
                        'errors' => $exception->errors,
                    ];

                    foreach ($exception->errors as $error) {
                        Log::warning('dataset_import.row_failed', [
                            'dataset_id' => $dataset->id,
                            'source_file_id' => $sourceFile->id,
                            'row' => $rowsRead,
                            'stage' => $error['stage'],
                            'source_field' => $error['source_field'],
                            'mapped_key' => $error['mapped_key'],
                            'raw_value' => $error['raw_value'],
                            'normalized_value' => $error['normalized_value'],
                            'error_code' => $error['error_code'],
                            'message' => $error['message'],
                        ]);
                    }
                }
            }
        }

        if ($batch !== []) {
            $batchResult = $this->writeBatch($dataset, $batch);
            $rowsWritten += $batchResult['rowsWritten'];
            $recordsReactivated += $batchResult['recordsReactivated'];
        }

        return [
            'rowsRead' => $rowsRead,
            'rowsWritten' => $rowsWritten,
            'rowsFailed' => $rowsFailed,
            'rowErrors' => $rowErrors,
            'errorTypeCounts' => $errorTypeCounts,
            'seenExternalIds' => $seenExternalIds,
            'duplicateExternalIdCount' => $duplicateExternalIdCount,
            'recordsReactivated' => $recordsReactivated,
        ];
    }

    private function importStatus(int $rowsRead, int $rowsWritten, int $rowsFailed): string
    {
        if ($rowsRead === 0 || ($rowsWritten > 0 && $rowsFailed === 0)) {
            return 'completed';
        }

        if ($rowsWritten > 0 && $rowsFailed > 0) {
            return 'partial';
        }

        return $rowsRead > 0 && $rowsFailed === $rowsRead
            ? 'validation_failed'
            : 'failed';
    }

    /**
     * @param  list<array{row: int, errors: list<array<string, mixed>>}>  $rowErrors
     * @param  array<string, int>  $errorTypeCounts
     * @return array{total_errors: int, error_types: array<string, int>, samples: list<array<string, mixed>>}
     */
    private function errorSummary(array $rowErrors, int $rowsFailed, array $errorTypeCounts = []): array
    {
        $errorTypes = $errorTypeCounts;
        $samples = [];

        foreach ($rowErrors as $rowError) {
            foreach ($rowError['errors'] as $error) {
                $errorCode = (string) ($error['error_code'] ?? 'row_mapping_failed');
                if (! array_key_exists($errorCode, $errorTypes)) {
                    $errorTypes[$errorCode] = 0;
                }
                $samples[] = [
                    'row' => $rowError['row'],
                    ...$error,
                ];
            }
        }

        return [
            'total_errors' => max($rowsFailed, array_sum($errorTypes), count($samples)),
            'error_types' => $errorTypes,
            'samples' => array_slice($samples, 0, self::MAX_ROW_ERROR_SAMPLES),
        ];
    }

    private function failureStage(Throwable $exception): string
    {
        return $exception instanceof ImportException ? $exception->stage : 'persistence';
    }

    private function errorCode(Throwable $exception): string
    {
        return $exception instanceof ImportException ? $exception->errorCode : 'unexpected_import_failure';
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
     * @param  list<array<string, mixed>>  $batch
     * @return array{rowsWritten: int, recordsReactivated: int}
     */
    private function writeBatch(Dataset $dataset, array $batch): array
    {
        return $this->snapshotReconciler->upsertBatch($dataset, $batch);
    }

    /**
     * Reconcile a successfully processed file as the complete Dataset snapshot.
     *
     * @param  array<string, true>  $seenExternalIds
     * @return array{recordsDeactivated: int}
     */
    private function reconcileSnapshot(Dataset $dataset, array $seenExternalIds): array
    {
        $recordsDeactivated = 0;

        DB::transaction(function () use ($dataset, $seenExternalIds, &$recordsDeactivated): void {
            $recordsDeactivated = $this->snapshotReconciler->reconcile($dataset, $seenExternalIds)['recordsDeactivated'];
        });

        return [
            'recordsDeactivated' => $recordsDeactivated,
        ];
    }

    /**
     * @param  Collection<int, DatasetField>  $fields
     */
    private function validateImport(Dataset $dataset, ?SourceFile $sourceFile, Collection $fields): void
    {
        $dataSource = $dataset->dataSource;

        if (! $dataSource || $dataSource->team_id !== $dataset->team_id || $dataSource->type !== 'file') {
            throw new ImportException('Only datasets connected to file data sources can be imported.');
        }

        if (! $sourceFile || $sourceFile->data_source_id !== $dataSource->id) {
            throw new ImportException('The selected source file does not belong to this dataset data source.');
        }

        if ($fields->isEmpty()) {
            throw new ImportException('Add at least one Dataset Field mapping before importing.');
        }

        if ($dataset->primary_key_path === null || $dataset->primary_key_path === '') {
            throw new ImportException('Configure the dataset primary key path before importing.');
        }

        $this->recordMapper->validatePrimaryKeyMapping($dataset, $fields);

        $disk = Storage::disk($sourceFile->disk);

        if (! $disk->exists($sourceFile->path)) {
            throw new ImportException('The selected source file is no longer available in storage.');
        }

        if (! array_key_exists($this->extension($sourceFile), $this->parsers)) {
            throw new ImportException('The selected source file format is not supported.');
        }
    }

    private function extension(SourceFile $sourceFile): string
    {
        $metadata = (array) $sourceFile->metadata;
        $extension = Arr::get($metadata, 'extension', pathinfo($sourceFile->original_name, PATHINFO_EXTENSION));

        return Str::lower((string) $extension);
    }
}
