<?php

namespace App\Services\Imports;

use App\Models\Dataset;
use App\Models\DatasetField;
use App\Services\Imports\Exceptions\RowMappingException;
use Illuminate\Database\Eloquent\Collection;
use JsonException;

class DatasetRecordMapper
{
    public function __construct(
        private readonly SourcePathResolver $sourcePathResolver,
        private readonly DatasetValueNormalizer $valueNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, DatasetField>  $fields
     * @return array{external_id: string, payload: array<string, mixed>, checksum: string, searchable_text: string}
     */
    public function map(Dataset $dataset, array $row, Collection $fields): array
    {
        $primaryKeyPath = $dataset->primary_key_path;
        $externalId = $this->externalId($dataset, $row);

        if ($primaryKeyPath === null || $primaryKeyPath === '') {
            throw new RowMappingException([
                ['field' => 'primary_key_path', 'message' => 'The dataset primary key path is not configured.'],
            ]);
        }

        if ($externalId === null) {
            throw new RowMappingException([
                ['field' => $primaryKeyPath, 'message' => 'The source row does not contain a valid primary key.'],
            ]);
        }

        $payload = [];
        $errors = [];

        foreach ($fields as $field) {
            $value = $this->sourcePathResolver->get($row, $field->source_path);

            if ($value === null || $value === '') {
                $payload[$field->key] = null;

                continue;
            }

            try {
                $payload[$field->key] = $this->valueNormalizer->normalize($field, $value);
            } catch (\Throwable $exception) {
                $errors[] = [
                    'field' => $field->key,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($errors !== []) {
            throw new RowMappingException($errors, $externalId);
        }

        try {
            $encodedPayload = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            throw new RowMappingException([
                ['field' => 'payload', 'message' => 'The mapped payload could not be encoded.'],
            ], $externalId);
        }

        return [
            'external_id' => $externalId,
            'payload' => $payload,
            'checksum' => hash('sha256', $encodedPayload),
            'searchable_text' => $this->searchableText($payload),
        ];
    }

    /**
     * Normalize dashboard values with the same rules used by imports.
     *
     * @param  array<string, mixed>  $values
     * @param  Collection<int, DatasetField>  $fields
     * @return array{external_id: string, payload: array<string, mixed>, checksum: string, searchable_text: string}
     */
    public function mapManual(string $externalId, array $values, Collection $fields): array
    {
        $payload = [];
        $errors = [];

        foreach ($fields as $field) {
            $value = $values[$field->key] ?? null;
            $isMissing = $value === null || (is_string($value) && trim($value) === '');

            if ($isMissing) {
                $payload[$field->key] = null;

                if ((bool) data_get($field->config, 'required', false)) {
                    $errors[] = [
                        'field' => $field->key,
                        'message' => "Field [{$field->key}] is required.",
                    ];
                }

                continue;
            }

            try {
                $payload[$field->key] = $this->valueNormalizer->normalize($field, $value);
            } catch (\Throwable $exception) {
                $errors[] = [
                    'field' => $field->key,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($errors !== []) {
            throw new RowMappingException($errors, $externalId);
        }

        try {
            $encodedPayload = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            throw new RowMappingException([
                ['field' => 'payload', 'message' => 'The mapped payload could not be encoded.'],
            ], $externalId);
        }

        return [
            'external_id' => $externalId,
            'payload' => $payload,
            'checksum' => hash('sha256', $encodedPayload),
            'searchable_text' => $this->searchableText($payload),
        ];
    }

    /**
     * Resolve the configured external identifier without validating mapped fields.
     *
     * This lets the importer retain an existing record when its source row is
     * present but one of the mapped values is invalid.
     *
     * @param  array<string, mixed>  $row
     */
    public function externalId(Dataset $dataset, array $row): ?string
    {
        $primaryKeyPath = $dataset->primary_key_path;

        if (! is_string($primaryKeyPath) || $primaryKeyPath === '') {
            return null;
        }

        $primaryValue = $this->sourcePathResolver->get($row, $primaryKeyPath);

        if ($primaryValue === null || $primaryValue === '' || ! is_scalar($primaryValue)) {
            return null;
        }

        return (string) $primaryValue;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function searchableText(array $payload): string
    {
        return collect($payload)
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => (string) $value)
            ->implode(' ');
    }
}
