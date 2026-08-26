<?php

namespace App\Services\Typesense;

use App\Models\DatasetField;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class TypesenseSchemaBuilder
{
    private const FIXED_FIELD_NAMES = [
        'external_id',
        'dataset_record_id',
        'searchable_text',
        'source_updated_at',
    ];

    /**
     * @param  Collection<int, DatasetField>  $fields
     * @return array{name: string, fields: list<array<string, mixed>>}
     */
    public function build(string $collectionName, Collection $fields): array
    {
        $schemaFields = [
            [
                'name' => 'external_id',
                'type' => 'string',
                'optional' => true,
            ],
            [
                'name' => 'dataset_record_id',
                'type' => 'int64',
            ],
            [
                'name' => 'searchable_text',
                'type' => 'string',
                'optional' => true,
            ],
            [
                'name' => 'source_updated_at',
                'type' => 'int64',
                'optional' => true,
            ],
        ];

        foreach ($fields as $field) {
            if (in_array($field->key, self::FIXED_FIELD_NAMES, true)) {
                throw new InvalidArgumentException("Dataset field key [{$field->key}] is reserved for search metadata.");
            }

            $typesenseField = [
                'name' => $field->key,
                'type' => $this->typesenseType($field->data_type),
                'optional' => true,
            ];

            if ($field->is_filterable) {
                $typesenseField['facet'] = true;
            }

            if ($field->is_sortable) {
                $typesenseField['sort'] = true;
            }

            $schemaFields[] = $typesenseField;
        }

        return [
            'name' => $collectionName,
            'fields' => $schemaFields,
        ];
    }

    private function typesenseType(string $dataType): string
    {
        return match ($dataType) {
            'string', 'url' => 'string',
            'integer' => 'int64',
            'decimal' => 'float',
            'boolean' => 'bool',
            'date', 'datetime' => 'int64',
            default => throw new InvalidArgumentException("Unsupported DatasetField type [{$dataType}]."),
        };
    }
}
