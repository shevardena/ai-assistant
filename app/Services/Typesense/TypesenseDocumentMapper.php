<?php

namespace App\Services\Typesense;

use App\Models\DatasetField;
use App\Models\DatasetRecord;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class TypesenseDocumentMapper
{
    /**
     * @param  Collection<int, DatasetField>  $fields
     * @return array<string, int|float|bool|string>
     */
    public function map(DatasetRecord $record, Collection $fields): array
    {
        $document = [
            'id' => (string) $record->id,
            'external_id' => (string) $record->external_id,
            'dataset_record_id' => $record->id,
        ];

        $searchableText = $record->getAttribute('searchable_text');

        if (is_string($searchableText) && $searchableText !== '') {
            $document['searchable_text'] = $searchableText;
        }

        $sourceUpdatedAt = $record->getAttribute('source_updated_at');

        if ($sourceUpdatedAt instanceof CarbonInterface) {
            $document['source_updated_at'] = $sourceUpdatedAt->getTimestamp();
        }

        $payload = $record->getAttribute('payload');

        foreach ($fields as $field) {
            $value = is_array($payload) ? ($payload[$field->key] ?? null) : null;

            if ($value === null) {
                continue;
            }

            $document[$field->key] = $this->value($field, $value);
        }

        return $document;
    }

    private function value(DatasetField $field, mixed $value): int|float|bool|string
    {
        return match ($field->data_type) {
            'string', 'url' => (string) $value,
            'integer' => (int) $value,
            'decimal' => (float) $value,
            'boolean' => (bool) $value,
            'date' => CarbonImmutable::parse((string) $value, 'UTC')->startOfDay()->getTimestamp(),
            'datetime' => CarbonImmutable::parse((string) $value, 'UTC')->utc()->getTimestamp(),
            default => throw new InvalidArgumentException("Unsupported DatasetField type [{$field->data_type}]."),
        };
    }
}
