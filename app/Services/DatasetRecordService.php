<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Services\Imports\DatasetRecordMapper;
use App\Services\Imports\Exceptions\RowMappingException;
use App\Services\Typesense\TypesenseDatasetSync;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DatasetRecordService
{
    public function __construct(
        private readonly DatasetRecordMapper $recordMapper,
        private readonly TypesenseDatasetSync $typesenseDatasetSync,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function create(Dataset $dataset, array $values): DatasetRecord
    {
        $record = DB::transaction(function () use ($dataset, $values): DatasetRecord {
            $fields = $dataset->fields()->get();
            $externalId = 'manual_'.Str::uuid()->toString();
            $mapped = $this->map($externalId, $values, $fields);

            return $dataset->records()->create([
                ...$this->recordAttributes($mapped),
                'origin' => 'manual',
                'is_active' => true,
                'source_updated_at' => null,
            ]);
        });

        $this->typesenseDatasetSync->syncRecord($record->load('dataset'));

        return $record;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(DatasetRecord $record, array $values): DatasetRecord
    {
        $record->loadMissing('dataset');
        $fields = $record->dataset->fields()->get();
        $mapped = $this->map($record->external_id, $values, $fields);

        DB::transaction(function () use ($record, $mapped): void {
            $record->update($this->recordAttributes($mapped));
        });

        $updatedRecord = $record->fresh()->load('dataset');

        if ($updatedRecord->is_active) {
            $this->typesenseDatasetSync->syncRecord($updatedRecord);
        } else {
            $this->typesenseDatasetSync->removeRecord($updatedRecord);
        }

        return $updatedRecord;
    }

    public function deactivate(DatasetRecord $record): DatasetRecord
    {
        $record->update(['is_active' => false]);
        $this->typesenseDatasetSync->removeRecord($record->load('dataset'));

        return $record->fresh();
    }

    public function activate(DatasetRecord $record): DatasetRecord
    {
        $record->update(['is_active' => true]);
        $this->typesenseDatasetSync->syncRecord($record->load('dataset'));

        return $record->fresh();
    }

    public function delete(DatasetRecord $record): void
    {
        abort_unless($record->origin === 'manual', 422, 'Source-managed records can only be deactivated.');

        $record->load('dataset');
        $record->delete();
        $this->typesenseDatasetSync->removeRecord($record);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  Collection<int, DatasetField>  $fields
     * @return array{external_id: string, payload: array<string, mixed>, checksum: string, searchable_text: string}
     */
    private function map(string $externalId, array $values, Collection $fields): array
    {
        try {
            return $this->recordMapper->mapManual($externalId, $values, $fields);
        } catch (RowMappingException $exception) {
            $messages = [];

            foreach ($exception->errors as $error) {
                $messages['values.'.$error['field']][] = $error['message'];
            }

            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @param  array{external_id: string, payload: array<string, mixed>, checksum: string, searchable_text: string}  $mapped
     * @return array<string, mixed>
     */
    private function recordAttributes(array $mapped): array
    {
        return [
            'external_id' => $mapped['external_id'],
            'payload' => $mapped['payload'],
            'searchable_text' => $mapped['searchable_text'],
            'checksum' => $mapped['checksum'],
        ];
    }
}
