<?php

namespace App\Services\Imports;

use App\Models\Dataset;
use App\Models\DatasetField;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Imports\Exceptions\RowMappingException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

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

        if ($primaryKeyPath === null || $primaryKeyPath === '') {
            throw new RowMappingException([
                $this->failure(
                    stage: 'mapping',
                    sourceField: 'primary_key_path',
                    mappedKey: null,
                    rawValue: null,
                    errorCode: 'missing_primary_key_mapping',
                    message: 'The dataset primary key path is not configured.',
                ),
            ]);
        }

        $primaryKeyField = $this->primaryKeyField($primaryKeyPath, $fields);

        if (! $primaryKeyField instanceof DatasetField) {
            throw new RowMappingException([
                $this->failure(
                    stage: 'mapping',
                    sourceField: null,
                    mappedKey: $primaryKeyPath,
                    rawValue: null,
                    errorCode: 'primary_key_not_mapped',
                    message: "Configured primary key [{$primaryKeyPath}] is not a mapped dataset field.",
                ),
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
            } catch (Throwable $exception) {
                $errors[] = [
                    ...$this->failure(
                        stage: 'normalization',
                        sourceField: $field->source_path,
                        mappedKey: $field->key,
                        rawValue: $value,
                        errorCode: $this->normalizationErrorCode($field, $exception),
                        message: Str::limit($exception->getMessage(), 300),
                    ),
                ];
            }
        }

        $canonicalPrimaryValue = $this->canonicalPrimaryValue($payload, $primaryKeyPath);
        $externalId = $this->canonicalExternalId($canonicalPrimaryValue);

        if (is_string($canonicalPrimaryValue)) {
            $canonicalPrimaryKey = $this->canonicalPath($primaryKeyPath);
            $payload[$canonicalPrimaryKey] = trim($canonicalPrimaryValue);
            $canonicalPrimaryValue = $payload[$canonicalPrimaryKey];
            $externalId = $this->canonicalExternalId($canonicalPrimaryValue);
        }

        if ($errors !== []) {
            throw new RowMappingException($errors, $externalId);
        }

        if ($externalId === null) {
            throw new RowMappingException([
                $this->primaryKeyFailure($row, $primaryKeyPath, $primaryKeyField, $canonicalPrimaryValue),
            ]);
        }

        try {
            $encodedPayload = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            throw new RowMappingException([
                $this->failure(
                    stage: 'mapping',
                    sourceField: null,
                    mappedKey: 'payload',
                    rawValue: null,
                    errorCode: 'payload_encoding_failed',
                    message: 'The mapped payload could not be encoded.',
                ),
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
                    $errors[] = $this->failure(
                        stage: 'validation',
                        sourceField: $field->source_path,
                        mappedKey: $field->key,
                        rawValue: null,
                        errorCode: 'required_field_missing',
                        message: "Field [{$field->key}] is required.",
                    );
                }

                continue;
            }

            try {
                $payload[$field->key] = $this->valueNormalizer->normalize($field, $value);
            } catch (Throwable $exception) {
                $errors[] = [
                    ...$this->failure(
                        stage: 'normalization',
                        sourceField: $field->source_path,
                        mappedKey: $field->key,
                        rawValue: $value,
                        errorCode: $this->normalizationErrorCode($field, $exception),
                        message: Str::limit($exception->getMessage(), 300),
                    ),
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
                $this->failure(
                    stage: 'mapping',
                    sourceField: null,
                    mappedKey: 'payload',
                    rawValue: null,
                    errorCode: 'payload_encoding_failed',
                    message: 'The mapped payload could not be encoded.',
                ),
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
     * @param  Collection<int, DatasetField>  $fields
     */
    public function externalId(Dataset $dataset, array $row, Collection $fields): ?string
    {
        $primaryKeyPath = $dataset->primary_key_path;

        if (! is_string($primaryKeyPath) || $primaryKeyPath === '') {
            return null;
        }

        $primaryKeyField = $this->primaryKeyField($primaryKeyPath, $fields);

        if (! $primaryKeyField instanceof DatasetField) {
            return null;
        }

        $primaryValue = $this->sourcePathResolver->get($row, $primaryKeyField->source_path);

        if ($primaryValue === null || $primaryValue === '' || ! is_scalar($primaryValue)) {
            return null;
        }

        try {
            $normalizedValue = $this->valueNormalizer->normalize($primaryKeyField, $primaryValue);
        } catch (Throwable) {
            return null;
        }

        return $this->canonicalExternalId($normalizedValue);
    }

    /**
     * Ensure the configured primary key is represented by a canonical field.
     *
     * @param  Collection<int, DatasetField>  $fields
     */
    public function validatePrimaryKeyMapping(Dataset $dataset, Collection $fields): void
    {
        $primaryKeyPath = $dataset->primary_key_path;

        if (! is_string($primaryKeyPath) || $primaryKeyPath === '') {
            throw new ImportException(
                'Configure the dataset primary key path before importing.',
                stage: 'mapping',
                errorCode: 'missing_primary_key_mapping',
            );
        }

        if (! $this->primaryKeyField($primaryKeyPath, $fields) instanceof DatasetField) {
            throw new ImportException(
                "The primary key field [{$primaryKeyPath}] is not mapped.",
                stage: 'mapping',
                errorCode: 'primary_key_not_mapped',
            );
        }
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

    /**
     * @param  Collection<int, DatasetField>  $fields
     */
    private function primaryKeyField(string $primaryKeyPath, Collection $fields): ?DatasetField
    {
        $field = $fields->first(
            fn (DatasetField $field): bool => $field->key === $this->canonicalPath($primaryKeyPath),
        );

        return $field instanceof DatasetField ? $field : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalPrimaryValue(array $payload, string $primaryKeyPath): mixed
    {
        return $this->sourcePathResolver->get($payload, $primaryKeyPath);
    }

    private function canonicalExternalId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $externalId = is_string($value) ? trim($value) : (string) $value;

        return $externalId === '' ? null : $externalId;
    }

    private function canonicalPath(string $path): string
    {
        return Str::startsWith($path, '$.') ? Str::after($path, '$.') : $path;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{field: string, stage: string, source_field: string|null, mapped_key: string|null, raw_value: scalar|null, normalized_value: scalar|null, error_code: string, message: string}
     */
    private function primaryKeyFailure(
        array $row,
        string $primaryKeyPath,
        DatasetField $primaryKeyField,
        mixed $canonicalPrimaryValue,
    ): array {
        $sourceValue = $this->sourcePathResolver->get($row, $primaryKeyField->source_path);
        $sourceIsMissing = $sourceValue === null || $sourceValue === '';
        $canonicalIsEmpty = $canonicalPrimaryValue === null
            || (is_string($canonicalPrimaryValue) && trim($canonicalPrimaryValue) === '');

        return $this->failure(
            stage: 'mapping',
            sourceField: $primaryKeyField->source_path,
            mappedKey: $primaryKeyPath,
            rawValue: $sourceValue,
            normalizedValue: $canonicalPrimaryValue,
            errorCode: $sourceIsMissing ? 'missing_primary_key_value' : ($canonicalIsEmpty ? 'empty_primary_key_value' : 'invalid_primary_key'),
            message: $sourceIsMissing
                ? "Primary key [{$primaryKeyPath}] maps from [{$primaryKeyField->source_path}], but the source row has no value."
                : ($canonicalIsEmpty
                    ? "Primary key [{$primaryKeyPath}] resolved to an empty value after normalization."
                    : "The normalized primary key [{$primaryKeyPath}] is not a valid scalar value."),
        );
    }

    /** @return array{field: string, stage: string, source_field: string|null, mapped_key: string|null, raw_value: scalar|null, normalized_value: scalar|null, error_code: string, message: string} */
    private function failure(
        string $stage,
        ?string $sourceField,
        ?string $mappedKey,
        mixed $rawValue,
        string $errorCode,
        string $message,
        mixed $normalizedValue = null,
    ): array {
        return [
            'field' => $mappedKey ?? $sourceField ?? 'payload',
            'stage' => $stage,
            'source_field' => $sourceField,
            'mapped_key' => $mappedKey,
            'raw_value' => $this->diagnosticValue($rawValue, $sourceField, $mappedKey),
            'normalized_value' => $this->diagnosticValue($normalizedValue, $sourceField, $mappedKey),
            'error_code' => $errorCode,
            'message' => $message,
        ];
    }

    private function normalizationErrorCode(DatasetField $field, Throwable $exception): string
    {
        if (Str::startsWith($exception->getMessage(), 'Unsupported normalizer')) {
            return 'unsupported_normalizer';
        }

        if (Str::startsWith($exception->getMessage(), 'Unsupported DatasetField type')) {
            return 'unsupported_field_type';
        }

        return 'invalid_'.Str::snake($field->data_type);
    }

    private function diagnosticValue(mixed $value, ?string $sourceField = null, ?string $mappedKey = null): int|float|string|bool|null
    {
        $fieldName = Str::lower(implode(' ', array_filter([$sourceField, $mappedKey])));

        if (Str::contains($fieldName, ['password', 'secret', 'token', 'authorization', 'api_key', 'private_key'])) {
            return '[redacted]';
        }

        if (is_scalar($value) || $value === null) {
            return is_string($value) ? Str::limit($value, 500) : $value;
        }

        return '[complex value]';
    }
}
