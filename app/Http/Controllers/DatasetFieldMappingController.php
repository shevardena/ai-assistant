<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiscoverDatasetFieldsRequest;
use App\Http\Requests\UpdateDatasetFieldsRequest;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\SourceFile;
use App\Models\Team;
use App\Services\Imports\Discovery\Data\DiscoveredSourceField;
use App\Services\Imports\Discovery\SourceFieldDiscoveryService;
use App\Services\Imports\Exceptions\ImportException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DatasetFieldMappingController extends Controller
{
    public function __construct(
        private readonly SourceFieldDiscoveryService $discoveryService,
    ) {}

    /**
     * Discover fields from a tenant-owned uploaded source file without saving mappings.
     */
    public function discover(
        DiscoverDatasetFieldsRequest $request,
        Team $currentTeam,
        Dataset $dataset,
    ): JsonResponse {
        Gate::authorize('update', $dataset);

        $dataSource = $dataset->dataSource;

        if (! $dataSource || $dataSource->team_id !== $dataset->team_id || $dataSource->type !== 'file') {
            throw ValidationException::withMessages([
                'source_file_id' => 'This dataset does not have a compatible file data source.',
            ]);
        }

        $selectedSourceFileId = $request->validated('source_file_id');
        $sourceFiles = $dataSource->files()
            ->whereIn('status', ['uploaded', 'ready'])
            ->when(
                $selectedSourceFileId !== null,
                fn ($query) => $query->whereKey($selectedSourceFileId),
                fn ($query) => $query->latest(),
            );
        $sourceFile = $sourceFiles->first();

        if (! $sourceFile instanceof SourceFile) {
            throw ValidationException::withMessages([
                'source_file_id' => 'No uploaded source file is available for this dataset.',
            ]);
        }

        try {
            $discoveredFields = $this->discoveryService->discover(
                $sourceFile,
                $dataset->primary_key_path,
            );
        } catch (ImportException $exception) {
            throw ValidationException::withMessages([
                'source_file_id' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'source_file' => $this->sourceFileDetails($sourceFile),
            'fields' => $this->mergeDiscoveredFields($dataset, $discoveredFields),
            'sample_row_limit' => SourceFieldDiscoveryService::SAMPLE_ROW_LIMIT,
        ]);
    }

    /**
     * Save all included field mappings as one transaction.
     */
    public function update(
        UpdateDatasetFieldsRequest $request,
        Team $currentTeam,
        Dataset $dataset,
    ): RedirectResponse {
        Gate::authorize('update', $dataset);

        $fields = $request->validated('fields', []);

        DB::transaction(function () use ($dataset, $fields): void {
            $existingFields = $dataset->fields()->get()->keyBy('id');
            $includedFields = array_values(array_filter(
                $fields,
                fn (array $field): bool => (bool) $field['included'],
            ));

            foreach ($fields as $field) {
                $fieldId = $field['id'] ?? null;

                if ($fieldId !== null && ! $field['included']) {
                    $existingFields->get((int) $fieldId)?->delete();
                }
            }

            usort(
                $includedFields,
                fn (array $left, array $right): int => $left['position'] <=> $right['position'],
            );

            foreach ($includedFields as $position => $field) {
                $values = [
                    'source_path' => $field['source_path'],
                    'key' => $field['key'],
                    'canonical_name' => $field['canonical_name'] ?? null,
                    'label' => $field['label'],
                    'data_type' => $field['data_type'],
                    'semantic_type' => $field['semantic_type'] ?? null,
                    'description' => $field['description'] ?? null,
                    'is_searchable' => (bool) $field['is_searchable'],
                    'is_filterable' => (bool) $field['is_filterable'],
                    'is_sortable' => (bool) $field['is_sortable'],
                    'is_semantic' => (bool) $field['is_semantic'],
                    'is_displayable' => (bool) $field['is_displayable'],
                    'normalizer' => $field['normalizer'] ?? null,
                    'config' => $field['config'] ?? null,
                    'position' => $position,
                ];
                $fieldId = $field['id'] ?? null;

                if ($fieldId !== null) {
                    $existingFields->get((int) $fieldId)?->update($values);

                    continue;
                }

                $dataset->fields()->create($values);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Field mappings saved.')]);

        return to_route('datasets.show', [
            'current_team' => $currentTeam->slug,
            'dataset' => $dataset,
        ]);
    }

    /**
     * @param  list<DiscoveredSourceField>  $discoveredFields
     * @return list<array<string, mixed>>
     */
    private function mergeDiscoveredFields(Dataset $dataset, array $discoveredFields): array
    {
        $existingFields = $dataset->fields()->get()->keyBy(
            fn (DatasetField $field): string => $this->normalizePath($field->source_path),
        );
        $merged = [];
        $seenPaths = [];

        foreach ($discoveredFields as $discoveredField) {
            $normalizedPath = $this->normalizePath($discoveredField->sourcePath);
            $existingField = $existingFields->get($normalizedPath);

            if ($existingField instanceof DatasetField) {
                $merged[] = [
                    ...$this->fieldDetails($existingField),
                    'included' => true,
                    'is_existing' => true,
                    'sample_values' => $discoveredField->sampleValues,
                    'confidence' => $discoveredField->confidence,
                    'is_primary_key' => $discoveredField->isPrimaryKey,
                ];
            } else {
                $merged[] = [
                    'id' => null,
                    'source_path' => $discoveredField->sourcePath,
                    'key' => $discoveredField->suggestedInternalKey,
                    'canonical_name' => null,
                    'label' => $discoveredField->suggestedLabel,
                    'data_type' => $discoveredField->suggestedType,
                    'semantic_type' => null,
                    'description' => null,
                    'is_searchable' => $discoveredField->isSearchable,
                    'is_filterable' => $discoveredField->isFilterable,
                    'is_sortable' => $discoveredField->isSortable,
                    'is_semantic' => false,
                    'is_displayable' => $discoveredField->isDisplayable,
                    'normalizer' => null,
                    'config' => [],
                    'position' => count($merged),
                    'included' => true,
                    'is_existing' => false,
                    'sample_values' => $discoveredField->sampleValues,
                    'confidence' => $discoveredField->confidence,
                    'is_primary_key' => $discoveredField->isPrimaryKey,
                ];
            }

            $seenPaths[$normalizedPath] = true;
        }

        foreach ($existingFields as $existingField) {
            if (! ($seenPaths[$this->normalizePath($existingField->source_path)] ?? false)) {
                $merged[] = [
                    ...$this->fieldDetails($existingField),
                    'included' => true,
                    'is_existing' => true,
                    'sample_values' => [],
                    'confidence' => null,
                    'is_primary_key' => $this->normalizePath($existingField->source_path)
                        === $this->normalizePath($dataset->primary_key_path),
                ];
            }
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldDetails(DatasetField $field): array
    {
        return [
            'id' => $field->id,
            'source_path' => $field->source_path,
            'key' => $field->key,
            'canonical_name' => $field->canonical_name,
            'label' => $field->label,
            'data_type' => $field->data_type,
            'semantic_type' => $field->semantic_type,
            'description' => $field->description,
            'is_searchable' => $field->is_searchable,
            'is_filterable' => $field->is_filterable,
            'is_sortable' => $field->is_sortable,
            'is_semantic' => $field->is_semantic,
            'is_displayable' => $field->is_displayable,
            'normalizer' => $field->normalizer,
            'config' => $field->config,
            'position' => $field->position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceFileDetails(SourceFile $sourceFile): array
    {
        $extension = data_get($sourceFile->metadata, 'extension');

        return [
            'id' => $sourceFile->id,
            'original_name' => $sourceFile->original_name,
            'extension' => is_string($extension) ? $extension : null,
            'status' => $sourceFile->status,
        ];
    }

    private function normalizePath(?string $path): string
    {
        return is_string($path) ? Str::replaceStart('$.', '', $path) : '';
    }
}
