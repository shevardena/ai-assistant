<?php

namespace App\Services\Imports;

use App\Models\Dataset;
use App\Models\DatasetRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DatasetSnapshotReconciler
{
    private const BATCH_SIZE = 100;

    /**
     * Upsert one batch of already-normalized records.
     *
     * @param  list<array<string, mixed>>  $batch
     * @return array{rowsWritten: int, recordsReactivated: int}
     */
    public function upsertBatch(Dataset $dataset, array $batch): array
    {
        $origin = $this->sourceOrigin($dataset);
        $recordsByExternalId = [];

        foreach ($batch as $record) {
            $record['origin'] = $origin;

            if (is_array($record['payload'] ?? null)) {
                $record['payload'] = json_encode(
                    $record['payload'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
                );
            }

            $recordsByExternalId[(string) $record['external_id']] = $record;
        }

        $records = array_values($recordsByExternalId);
        $externalIds = array_keys($recordsByExternalId);

        if ($records === []) {
            return ['rowsWritten' => 0, 'recordsReactivated' => 0];
        }

        $inactiveRecordIds = DatasetRecord::query()
            ->where('dataset_id', $dataset->id)
            ->where('origin', $origin)
            ->whereIn('external_id', $externalIds)
            ->where('is_active', false)
            ->pluck('id')
            ->all();

        DatasetRecord::upsert(
            $records,
            ['dataset_id', 'external_id'],
            ['origin', 'payload', 'searchable_text', 'checksum', 'source_updated_at', 'updated_at'],
        );

        $recordsReactivated = $inactiveRecordIds === []
            ? 0
            : DatasetRecord::query()
                ->where('dataset_id', $dataset->id)
                ->where('origin', $origin)
                ->whereIn('id', $inactiveRecordIds)
                ->where('is_active', false)
                ->update([
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

        return [
            'rowsWritten' => count($records),
            'recordsReactivated' => $recordsReactivated,
        ];
    }

    /**
     * Deactivate records omitted from a completed full snapshot.
     *
     * @param  array<string, true>  $seenExternalIds
     * @return array{recordsDeactivated: int}
     */
    public function reconcile(Dataset $dataset, array $seenExternalIds): array
    {
        $recordsDeactivated = 0;

        DatasetRecord::query()
            ->where('dataset_id', $dataset->id)
            ->where('origin', $this->sourceOrigin($dataset))
            ->select(['id', 'external_id', 'is_active'])
            ->chunkById(self::BATCH_SIZE, function (Collection $records) use ($seenExternalIds, &$recordsDeactivated): void {
                $idsToDeactivate = $records
                    ->filter(fn (DatasetRecord $record): bool => $record->is_active
                        && ! array_key_exists((string) $record->external_id, $seenExternalIds))
                    ->pluck('id')
                    ->all();

                if ($idsToDeactivate === []) {
                    return;
                }

                $recordsDeactivated += DatasetRecord::query()
                    ->whereIn('id', $idsToDeactivate)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'updated_at' => now(),
                    ]);
            });

        return ['recordsDeactivated' => $recordsDeactivated];
    }

    private function sourceOrigin(Dataset $dataset): string
    {
        $type = $dataset->relationLoaded('dataSource')
            ? $dataset->dataSource?->type
            : $dataset->dataSource()->value('type');

        return match ((string) $type) {
            'file' => 'file_import',
            'graphql_api' => 'graphql_api',
            default => 'rest_api',
        };
    }

    /**
     * Write and reconcile a complete snapshot atomically.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, true>  $seenExternalIds
     * @return array{rowsWritten: int, recordsReactivated: int, recordsDeactivated: int}
     */
    public function writeSnapshot(Dataset $dataset, array $records, array $seenExternalIds): array
    {
        $result = [
            'rowsWritten' => 0,
            'recordsReactivated' => 0,
            'recordsDeactivated' => 0,
        ];

        DB::transaction(function () use ($dataset, $records, $seenExternalIds, &$result): void {
            foreach (array_chunk($records, self::BATCH_SIZE) as $batch) {
                $batchResult = $this->upsertBatch($dataset, $batch);
                $result['rowsWritten'] += $batchResult['rowsWritten'];
                $result['recordsReactivated'] += $batchResult['recordsReactivated'];
            }

            $result['recordsDeactivated'] = $this->reconcile($dataset, $seenExternalIds)['recordsDeactivated'];
        });

        return $result;
    }

    /**
     * Write only the records returned by an incremental change feed.
     *
     * Missing records are intentionally untouched because an incremental
     * response is not an authoritative snapshot.
     *
     * @param  list<array<string, mixed>>  $records
     * @return array{rowsWritten: int, recordsReactivated: int, recordsDeactivated: int}
     */
    public function writeIncremental(Dataset $dataset, array $records): array
    {
        $result = [
            'rowsWritten' => 0,
            'recordsReactivated' => 0,
            'recordsDeactivated' => 0,
        ];

        DB::transaction(function () use ($dataset, $records, &$result): void {
            foreach (array_chunk($records, self::BATCH_SIZE) as $batch) {
                $batchResult = $this->upsertBatch($dataset, $batch);
                $result['rowsWritten'] += $batchResult['rowsWritten'];
                $result['recordsReactivated'] += $batchResult['recordsReactivated'];
            }
        });

        return $result;
    }
}
