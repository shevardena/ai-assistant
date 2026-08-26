<?php

namespace App\Services\Api;

use App\Models\BotApiOperation;
use App\Models\Dataset;
use App\Models\DatasetRecord;

class RuntimeApiArgumentMapper
{
    /**
     * Determine whether every configured model-input mapping has a safe shape.
     *
     * @param  list<string>  $allowedModelInputs
     */
    public function hasValidMappings(
        BotApiOperation $attachment,
        array $allowedModelInputs = [],
        ?string $requiredSource = null,
    ): bool {
        foreach ($this->inputMapping($attachment) as $modelInput => $definition) {
            $configuration = $this->mappingFor($attachment, $modelInput);

            if (($allowedModelInputs !== []
                && ! in_array($modelInput, $allowedModelInputs, true))
                || $configuration === null
                || ($requiredSource !== null && $configuration['source'] !== $requiredSource)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the configured DatasetField and runtime argument for one model input.
     *
     * @return array{source: string, dataset_field: string|null, model_input: string|null, context_key: string|null, operation_argument: string}|null
     */
    public function mappingFor(BotApiOperation $attachment, string $modelInput): ?array
    {
        $mapping = $this->inputMapping($attachment);
        $definition = $mapping[$modelInput] ?? null;

        if (! is_array($definition)) {
            return null;
        }

        $source = $definition['source'] ?? 'dataset_field';
        $datasetField = $definition['dataset_field'] ?? $definition['field'] ?? null;
        $configuredModelInput = $definition['model_input'] ?? $modelInput;
        $contextKey = $definition['context_key'] ?? $definition['context'] ?? null;
        $operationArgument = $definition['operation_argument'] ?? $definition['argument'] ?? null;

        if (! is_string($source)
            || ! in_array($source, ['context_value', 'dataset_field', 'model_input'], true)
            || ! is_string($operationArgument)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,254}$/', $operationArgument) !== 1) {
            return null;
        }

        if ($source === 'dataset_field'
            && (! is_string($datasetField)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,254}$/', $datasetField) !== 1)) {
            return null;
        }

        if ($source === 'model_input'
            && (! is_string($configuredModelInput)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,254}(?:\.[A-Za-z_][A-Za-z0-9_-]{0,63})?$/', $configuredModelInput) !== 1
                || $configuredModelInput !== $modelInput)) {
            return null;
        }

        if ($source === 'context_value'
            && (! is_string($contextKey)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,99}$/', $contextKey) !== 1)) {
            return null;
        }

        return [
            'source' => $source,
            'dataset_field' => $source === 'dataset_field' ? $datasetField : null,
            'model_input' => $source === 'model_input' ? $configuredModelInput : null,
            'context_key' => $source === 'context_value' ? $contextKey : null,
            'operation_argument' => $operationArgument,
        ];
    }

    /**
     * Map model-facing values into configured operation arguments.
     *
     * Values sourced from a DatasetRecord are read only through a DatasetField
     * belonging to that resolved Dataset.
     *
     * @param  array<string, mixed>  $modelInputs
     * @param  array<string, mixed>  $contextValues
     * @return array<string, mixed>|null
     */
    public function map(
        BotApiOperation $attachment,
        ?Dataset $dataset,
        ?DatasetRecord $record,
        array $modelInputs,
        array $contextValues = [],
    ): ?array {
        if (($dataset === null) !== ($record === null)) {
            return null;
        }

        $fields = $dataset?->relationLoaded('fields')
            ? $dataset->fields
            : $dataset?->fields()->get();
        $payload = $record?->getAttribute('payload');

        if ($dataset !== null
            && $record !== null
            && (int) $record->dataset_id !== (int) $dataset->id) {
            return null;
        }

        $arguments = [];

        foreach ($modelInputs as $modelInput => $value) {
            if (! is_scalar($value)) {
                return null;
            }

            $configuration = $this->mappingFor($attachment, $modelInput);

            if ($configuration === null) {
                return null;
            }

            if ($configuration['source'] === 'model_input') {
                $arguments[$configuration['operation_argument']] = $value;

                continue;
            }

            if ($configuration['source'] === 'context_value') {
                return null;
            }

            if ($dataset === null || $record === null || $fields === null || ! is_array($payload)) {
                return null;
            }

            $field = $fields->firstWhere('key', $configuration['dataset_field']);

            if ($field === null || ! array_key_exists($field->key, $payload)) {
                return null;
            }

            $mappedValue = $payload[$field->key];

            if (! is_scalar($mappedValue)
                || (is_string($mappedValue) && trim($mappedValue) === '')) {
                return null;
            }

            $arguments[$configuration['operation_argument']] = $mappedValue;
        }

        foreach ($this->inputMapping($attachment) as $modelInput => $definition) {
            $configuration = $this->mappingFor($attachment, $modelInput);

            if ($configuration === null || $configuration['source'] !== 'context_value') {
                continue;
            }

            $contextKey = $configuration['context_key'];

            if ($contextKey === null || ! array_key_exists($contextKey, $contextValues)) {
                continue;
            }

            $value = $contextValues[$contextKey];

            if (! is_scalar($value) && $value !== null) {
                return null;
            }

            $arguments[$configuration['operation_argument']] = $value;
        }

        return $arguments;
    }

    /**
     * @return array<string, mixed>
     */
    private function inputMapping(BotApiOperation $attachment): array
    {
        $settings = $attachment->getAttribute('settings');
        $mapping = is_array($settings) ? ($settings['input_mapping'] ?? []) : [];

        return is_array($mapping) ? $mapping : [];
    }
}
