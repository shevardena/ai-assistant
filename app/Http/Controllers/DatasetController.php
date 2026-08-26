<?php

namespace App\Http\Controllers;

use App\Enums\DatasetStatus;
use App\Http\Requests\StoreDatasetRequest;
use App\Http\Requests\UpdateDatasetRequest;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DataSource;
use App\Models\SourceFile;
use App\Models\SourceRun;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DatasetController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Dataset::class);

        $team = $this->currentTeam($request);

        return Inertia::render('datasets/index', [
            'datasets' => $team->datasets()
                ->select(['id', 'team_id', 'data_source_id', 'name', 'slug', 'entity_type', 'retrieval_mode', 'status', 'created_at', 'updated_at'])
                ->with('dataSource:id,name,type,status')
                ->latest()
                ->paginate(10)
                ->withQueryString()
                ->through(fn (Dataset $dataset): array => $this->summary($dataset)),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Dataset::class);

        return Inertia::render('datasets/create', [
            'dataSources' => $this->dataSourceOptions($this->currentTeam($request)),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDatasetRequest $request): RedirectResponse
    {
        $team = $this->currentTeam($request);

        $dataset = $team->datasets()->create([
            ...$request->validated(),
            'status' => DatasetStatus::Preparing->value,
            'schema_version' => 1,
            'last_indexed_at' => null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dataset created.')]);

        return to_route('datasets.show', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Team $currentTeam, Dataset $dataset): Response
    {
        Gate::authorize('view', $dataset);

        $dataset->load([
            'dataSource:id,name,type,status',
            'dataSource.files:id,data_source_id,original_name,mime_type,size_bytes,status,metadata,created_at',
            'fields',
        ]);
        $dataset->setRelation('sourceRuns', $dataset->sourceRuns()->latest()->limit(10)->get());

        return Inertia::render('datasets/show', [
            'dataset' => $this->details($dataset),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Team $currentTeam, Dataset $dataset): Response
    {
        Gate::authorize('update', $dataset);

        $dataset->load('dataSource:id,name,type,status');

        return Inertia::render('datasets/edit', [
            'dataset' => $this->details($dataset, false),
            'dataSources' => $this->dataSourceOptions($this->currentTeam($request)),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDatasetRequest $request, Team $currentTeam, Dataset $dataset): RedirectResponse
    {
        Gate::authorize('update', $dataset);

        $dataset->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dataset updated.')]);

        return to_route('datasets.show', [
            'current_team' => $this->currentTeam($request)->slug,
            'dataset' => $dataset,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Team $currentTeam, Dataset $dataset): RedirectResponse
    {
        Gate::authorize('delete', $dataset);

        $dataset->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dataset deleted.')]);

        return to_route('datasets.index', [
            'current_team' => $this->currentTeam($request)->slug,
        ]);
    }

    /**
     * Get the authenticated user's current team.
     */
    private function currentTeam(Request $request): Team
    {
        $team = $request->user()?->currentTeam;

        abort_if(! $team, 403);

        return $team;
    }

    /**
     * Get lightweight data source options for the dataset form.
     *
     * @return list<array{id: int, name: string, type: string, status: string}>
     */
    private function dataSourceOptions(Team $team): array
    {
        return array_values($team->dataSources()
            ->select(['id', 'name', 'type', 'status'])
            ->orderBy('name')
            ->get()
            ->map(fn (DataSource $dataSource): array => [
                'id' => $dataSource->id,
                'name' => $dataSource->name,
                'type' => $dataSource->type,
                'status' => $dataSource->status,
            ])
            ->all());
    }

    /**
     * Get the fields required by the index page.
     *
     * @return array<string, mixed>
     */
    private function summary(Dataset $dataset): array
    {
        return [
            'id' => $dataset->id,
            'name' => $dataset->name,
            'slug' => $dataset->slug,
            'entityType' => $dataset->entity_type,
            'retrievalMode' => $dataset->retrieval_mode,
            'status' => $dataset->status,
            'dataSource' => $dataset->dataSource ? [
                'id' => $dataset->dataSource->id,
                'name' => $dataset->dataSource->name,
                'type' => $dataset->dataSource->type,
                'status' => $dataset->dataSource->status,
            ] : null,
            'createdAt' => $dataset->created_at?->toISOString(),
            'updatedAt' => $dataset->updated_at?->toISOString(),
        ];
    }

    /**
     * Get the fields required by the show and edit pages.
     *
     * @return array<string, mixed>
     */
    private function details(Dataset $dataset, bool $includeFields = true): array
    {
        return [
            ...$this->summary($dataset),
            'primaryKeyPath' => $dataset->primary_key_path,
            'settings' => $dataset->settings,
            'fields' => $includeFields
                ? $dataset->fields->map(fn (DatasetField $field): array => $this->fieldDetails($field))->values()->all()
                : [],
            'sourceFiles' => $includeFields && $dataset->dataSource?->type === 'file'
                ? $dataset->dataSource->files->map(fn (SourceFile $sourceFile): array => $this->sourceFileDetails($sourceFile))->values()->all()
                : [],
            'sourceRuns' => $includeFields && $dataset->relationLoaded('sourceRuns')
                ? $dataset->sourceRuns->map(fn (SourceRun $sourceRun): array => $this->sourceRunDetails($sourceRun))->values()->all()
                : [],
        ];
    }

    /**
     * Get safe source file metadata for the dataset import form.
     *
     * @return array<string, mixed>
     */
    private function sourceFileDetails(SourceFile $sourceFile): array
    {
        $extension = Arr::get((array) $sourceFile->metadata, 'extension');

        return [
            'id' => $sourceFile->id,
            'originalName' => $sourceFile->original_name,
            'mimeType' => $sourceFile->mime_type,
            'sizeBytes' => $sourceFile->size_bytes,
            'extension' => is_string($extension) ? $extension : null,
            'status' => $sourceFile->status,
            'createdAt' => $sourceFile->created_at?->toISOString(),
        ];
    }

    /**
     * Get source run status and counts for the dataset import history.
     *
     * @return array<string, mixed>
     */
    private function sourceRunDetails(SourceRun $sourceRun): array
    {
        $startedAt = $sourceRun->getAttribute('started_at');
        $finishedAt = $sourceRun->getAttribute('finished_at');

        return [
            'id' => $sourceRun->id,
            'type' => $sourceRun->type,
            'status' => $sourceRun->status,
            'rowsRead' => $sourceRun->rows_read,
            'rowsWritten' => $sourceRun->rows_written,
            'rowsFailed' => $sourceRun->rows_failed,
            'error' => $sourceRun->error,
            'startedAt' => $startedAt instanceof CarbonInterface ? $startedAt->toISOString() : null,
            'finishedAt' => $finishedAt instanceof CarbonInterface ? $finishedAt->toISOString() : null,
            'createdAt' => $sourceRun->created_at?->toISOString(),
        ];
    }

    /**
     * Get the fields required by the field mapping section.
     *
     * @return array<string, mixed>
     */
    private function fieldDetails(DatasetField $field): array
    {
        return [
            'id' => $field->id,
            'sourcePath' => $field->source_path,
            'key' => $field->key,
            'canonicalName' => $field->canonical_name,
            'label' => $field->label,
            'dataType' => $field->data_type,
            'semanticType' => $field->semantic_type,
            'description' => $field->description,
            'isSearchable' => $field->is_searchable,
            'isFilterable' => $field->is_filterable,
            'isSortable' => $field->is_sortable,
            'isSemantic' => $field->is_semantic,
            'isDisplayable' => $field->is_displayable,
            'normalizer' => $field->normalizer,
            'config' => $field->config,
            'position' => $field->position,
            'createdAt' => $field->created_at?->toISOString(),
            'updatedAt' => $field->updated_at?->toISOString(),
        ];
    }
}
