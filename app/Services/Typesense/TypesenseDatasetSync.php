<?php

namespace App\Services\Typesense;

use App\Models\Dataset;
use App\Models\DatasetRecord;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

class TypesenseDatasetSync
{
    public function __construct(private readonly Container $container) {}

    public function syncAfterImport(Dataset $dataset, bool $throwOnFailure = false): ?TypesenseIndexResult
    {
        if (! (bool) config('search.typesense.sync_after_import', false)) {
            return null;
        }

        try {
            return $this->container->make(TypesenseDatasetIndexer::class)->reindex($dataset);
        } catch (Throwable $exception) {
            Log::error('Typesense Dataset sync failed after import.', [
                'dataset_id' => $dataset->id,
                'collection' => 'dataset_'.$dataset->id,
                'operation' => 'post_import_sync',
                'exception' => $exception,
            ]);

            if ($throwOnFailure) {
                throw $exception;
            }

            return null;
        }
    }

    public function syncRecord(DatasetRecord $record, bool $throwOnFailure = false): void
    {
        $this->runRecordSync($record, 'upsert', $throwOnFailure);
    }

    public function removeRecord(DatasetRecord $record, bool $throwOnFailure = false): void
    {
        $this->runRecordSync($record, 'remove', $throwOnFailure);
    }

    private function runRecordSync(DatasetRecord $record, string $operation, bool $throwOnFailure): void
    {
        if (! (bool) config('search.typesense.sync_after_import', false)) {
            return;
        }

        try {
            $indexer = $this->container->make(TypesenseDatasetIndexer::class);

            if ($operation === 'upsert') {
                $indexer->upsertRecord($record);
            } else {
                $indexer->removeRecord($record);
            }
        } catch (Throwable $exception) {
            Log::error('Typesense Dataset record sync failed.', [
                'dataset_id' => $record->dataset_id,
                'record_id' => $record->id,
                'operation' => $operation,
                'exception' => $exception,
            ]);

            if ($throwOnFailure) {
                throw $exception;
            }
        }
    }
}
