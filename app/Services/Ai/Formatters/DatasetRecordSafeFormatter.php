<?php

namespace App\Services\Ai\Formatters;

use App\Models\Dataset;
use App\Models\DatasetRecord;

class DatasetRecordSafeFormatter
{
    /**
     * Return only scalar or null values from displayable DatasetFields.
     *
     * @return array<string, mixed>
     */
    public function fields(Dataset $dataset, DatasetRecord $record): array
    {
        $payload = $record->getAttribute('payload');
        $payload = is_array($payload) ? $payload : [];
        $fields = $dataset->relationLoaded('fields')
            ? $dataset->fields
            : $dataset->fields()->get();
        $safeFields = [];

        foreach ($fields as $field) {
            if (! $field->is_displayable) {
                continue;
            }

            $value = $payload[$field->key] ?? null;

            if (is_scalar($value) || $value === null) {
                $safeFields[(string) $field->key] = $value;
            }
        }

        return $safeFields;
    }
}
