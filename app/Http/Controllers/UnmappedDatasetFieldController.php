<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiscoverDatasetFieldsRequest;
use App\Http\Requests\StoreUnmappedDatasetFieldsRequest;
use App\Models\Dataset;
use App\Models\SourceFile;
use App\Models\Team;
use App\Services\Imports\Discovery\Data\DiscoveredSourceField;
use App\Services\Imports\Discovery\SourceFieldDiscoveryService;
use App\Services\Imports\Exceptions\ImportException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UnmappedDatasetFieldController extends Controller
{
    public function __construct(
        private readonly SourceFieldDiscoveryService $discoveryService,
    ) {}

    public function index(
        DiscoverDatasetFieldsRequest $request,
        Team $currentTeam,
        Dataset $dataset,
    ): Response {
        Gate::authorize('update', $dataset);

        $sourceFiles = $this->availableSourceFiles($dataset);
        $selectedSourceFileId = $request->validated('source_file_id');
        $sourceFile = $this->sourceFile($dataset, $selectedSourceFileId);
        $discoveredFields = [];
        $discoveryError = null;

        if ($sourceFile instanceof SourceFile) {
            try {
                $discoveredFields = $this->unmappedFields(
                    $dataset,
                    $this->discoveryService->discover($sourceFile, $dataset->primary_key_path),
                );
            } catch (ImportException $exception) {
                $discoveryError = $exception->getMessage();
            }
        } elseif ($sourceFiles->isNotEmpty() && $selectedSourceFileId !== null) {
            $discoveryError = 'The selected source file is not available for discovery.';
        }

        return Inertia::render('datasets/fields/unmapped', [
            'dataset' => [
                'id' => $dataset->id,
                'name' => $dataset->name,
                'primaryKeyPath' => $dataset->primary_key_path,
            ],
            'sourceFiles' => $sourceFiles
                ->map(fn (SourceFile $file): array => $this->sourceFileDetails($file))
                ->values()
                ->all(),
            'sourceFile' => $sourceFile instanceof SourceFile
                ? $this->sourceFileDetails($sourceFile)
                : null,
            'selectedSourceFileId' => $sourceFile?->id,
            'fields' => $discoveredFields,
            'discoveryError' => $discoveryError,
        ]);
    }

    public function store(
        StoreUnmappedDatasetFieldsRequest $request,
        Team $currentTeam,
        Dataset $dataset,
    ): RedirectResponse {
        Gate::authorize('update', $dataset);

        $sourceFile = $this->sourceFile($dataset, $request->validated('source_file_id'));

        if (! $sourceFile instanceof SourceFile) {
            throw ValidationException::withMessages([
                'source_file_id' => 'No uploaded source file is available for field discovery.',
            ]);
        }

        try {
            $discoveredFields = $this->discoveryService->discover($sourceFile, $dataset->primary_key_path);
        } catch (ImportException $exception) {
            throw ValidationException::withMessages([
                'source_file_id' => $exception->getMessage(),
            ]);
        }

        $fields = $request->validated('fields', []);
        $discoveredByPath = collect($discoveredFields)->keyBy(
            fn (DiscoveredSourceField $field): string => $field->sourcePath,
        );
        $this->ensurePathsWereDiscovered($fields, $discoveredByPath);

        try {
            DB::transaction(function () use ($dataset, $fields, $discoveredByPath): void {
                $lockedDataset = Dataset::query()
                    ->whereKey($dataset->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $existingFields = $lockedDataset->fields()->get();
                $existingPaths = array_fill_keys($existingFields->pluck('source_path')->all(), true);
                $existingKeys = array_fill_keys($existingFields->pluck('key')->all(), true);
                $errors = [];

                foreach ($fields as $index => $field) {
                    if (isset($existingPaths[$field['source_path']])) {
                        $errors["fields.{$index}.source_path"] = 'This source field is already mapped.';
                    }

                    if (isset($existingKeys[$field['key']])) {
                        $errors["fields.{$index}.key"] = 'This internal key already exists.';
                    }
                }

                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }

                $position = ((int) ($existingFields->max('position') ?? -1)) + 1;

                foreach ($fields as $field) {
                    /** @var DiscoveredSourceField $discoveredField */
                    $discoveredField = $discoveredByPath->get($field['source_path']);

                    $lockedDataset->fields()->create([
                        'source_path' => $discoveredField->sourcePath,
                        'key' => $field['key'],
                        'canonical_name' => null,
                        'label' => $field['label'],
                        'data_type' => $field['data_type'],
                        'semantic_type' => null,
                        'description' => null,
                        'is_searchable' => $discoveredField->isSearchable,
                        'is_filterable' => $discoveredField->isFilterable,
                        'is_sortable' => $discoveredField->isSortable,
                        'is_semantic' => false,
                        'is_displayable' => $discoveredField->isDisplayable,
                        'normalizer' => null,
                        'config' => [],
                        'position' => $position++,
                    ]);
                }
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'fields' => 'One or more selected fields were added already. Refresh and try again.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Selected fields added.')]);

        return to_route('datasets.show', [
            'current_team' => $currentTeam->slug,
            'dataset' => $dataset,
        ]);
    }

    /**
     * @return EloquentCollection<int, SourceFile>
     */
    private function availableSourceFiles(Dataset $dataset): EloquentCollection
    {
        $dataSource = $dataset->dataSource;

        if (! $dataSource || $dataSource->team_id !== $dataset->team_id || $dataSource->type !== 'file') {
            return new EloquentCollection;
        }

        return $dataSource->files()
            ->whereIn('status', ['uploaded', 'ready'])
            ->latest()
            ->get();
    }

    private function sourceFile(Dataset $dataset, ?int $sourceFileId): ?SourceFile
    {
        $dataSource = $dataset->dataSource;

        if (! $dataSource || $dataSource->team_id !== $dataset->team_id || $dataSource->type !== 'file') {
            return null;
        }

        return $dataSource->files()
            ->whereIn('status', ['uploaded', 'ready'])
            ->when(
                $sourceFileId !== null,
                fn ($query) => $query->whereKey($sourceFileId),
                fn ($query) => $query->latest(),
            )
            ->first();
    }

    /**
     * @param  list<DiscoveredSourceField>  $discoveredFields
     * @return list<array<string, mixed>>
     */
    private function unmappedFields(Dataset $dataset, array $discoveredFields): array
    {
        $mappedPaths = array_fill_keys($dataset->fields()->pluck('source_path')->all(), true);
        $unmapped = [];

        foreach ($discoveredFields as $field) {
            if (isset($mappedPaths[$field->sourcePath])) {
                continue;
            }

            $unmapped[] = [
                'sourcePath' => $field->sourcePath,
                'key' => $field->suggestedInternalKey,
                'label' => $field->suggestedLabel,
                'dataType' => $field->suggestedType,
                'isSearchable' => $field->isSearchable,
                'isFilterable' => $field->isFilterable,
                'isSortable' => $field->isSortable,
                'isDisplayable' => $field->isDisplayable,
                'sampleValues' => $field->sampleValues,
                'confidence' => $field->confidence,
                'isPrimaryKey' => $field->isPrimaryKey,
            ];
        }

        return $unmapped;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  Collection<string, DiscoveredSourceField>  $discoveredByPath
     */
    private function ensurePathsWereDiscovered(array $fields, Collection $discoveredByPath): void
    {
        $errors = [];

        foreach ($fields as $index => $field) {
            if (! $discoveredByPath->has($field['source_path'])) {
                $errors["fields.{$index}.source_path"] = 'This source field is not present in the selected file.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceFileDetails(SourceFile $sourceFile): array
    {
        $extension = data_get($sourceFile->metadata, 'extension');

        return [
            'id' => $sourceFile->id,
            'originalName' => $sourceFile->original_name,
            'extension' => is_string($extension) ? $extension : null,
            'status' => $sourceFile->status,
        ];
    }
}
