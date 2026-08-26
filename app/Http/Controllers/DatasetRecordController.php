<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDatasetRecordRequest;
use App\Http\Requests\UpdateDatasetRecordRequest;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\Team;
use App\Services\DatasetRecordService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DatasetRecordController extends Controller
{
    public function __construct(private readonly DatasetRecordService $recordService) {}

    public function index(Request $request, Team $currentTeam, Dataset $dataset): Response
    {
        Gate::authorize('view', $dataset);

        $fields = $dataset->fields()->get();
        $query = $dataset->records()->select([
            'id', 'dataset_id', 'external_id', 'origin', 'payload', 'is_active',
            'source_updated_at', 'created_at', 'updated_at',
        ]);
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status');
        $origin = (string) $request->string('origin');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('external_id', 'ilike', '%'.$search.'%')
                    ->orWhere('searchable_text', 'ilike', '%'.$search.'%');
            });
        }

        if ($status === 'active' || $status === 'inactive') {
            $query->where('is_active', $status === 'active');
        }

        if (in_array($origin, ['manual', 'file_import', 'rest_api', 'graphql_api'], true)) {
            $query->where('origin', $origin);
        }

        return Inertia::render('datasets/records/index', [
            'dataset' => $this->datasetDetails($dataset),
            'fields' => $fields->map(fn (DatasetField $field): array => $this->fieldDetails($field))->values()->all(),
            'records' => $query->latest('id')->paginate(25)->withQueryString()->through(
                fn (DatasetRecord $record): array => $this->recordDetails($record, $fields),
            ),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'origin' => $origin,
            ],
            'counts' => [
                'total' => $dataset->records()->count(),
                'active' => $dataset->records()->where('is_active', true)->count(),
            ],
        ]);
    }

    public function create(Team $currentTeam, Dataset $dataset): Response
    {
        Gate::authorize('update', $dataset);

        return Inertia::render('datasets/records/create', [
            'dataset' => $this->datasetDetails($dataset),
            'fields' => $dataset->fields()->get()->map(fn (DatasetField $field): array => $this->fieldDetails($field))->values()->all(),
        ]);
    }

    public function store(StoreDatasetRecordRequest $request, Team $currentTeam, Dataset $dataset): RedirectResponse
    {
        Gate::authorize('update', $dataset);
        $record = $this->recordService->create($dataset, (array) $request->validated('values'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Record created.')]);

        return to_route('datasets.records.show', [$currentTeam->slug, $dataset, $record]);
    }

    public function show(Team $currentTeam, Dataset $dataset, DatasetRecord $record): Response
    {
        Gate::authorize('view', $dataset);
        $this->ensureRecordBelongsToDataset($dataset, $record);
        $fields = $dataset->fields()->get();

        return Inertia::render('datasets/records/show', [
            'dataset' => $this->datasetDetails($dataset),
            'fields' => $fields->map(fn (DatasetField $field): array => $this->fieldDetails($field))->values()->all(),
            'record' => $this->recordDetails($record, $fields, includeRaw: true),
        ]);
    }

    public function edit(Team $currentTeam, Dataset $dataset, DatasetRecord $record): Response
    {
        Gate::authorize('update', $dataset);
        $this->ensureRecordBelongsToDataset($dataset, $record);
        $fields = $dataset->fields()->get();

        return Inertia::render('datasets/records/edit', [
            'dataset' => $this->datasetDetails($dataset),
            'fields' => $fields->map(fn (DatasetField $field): array => $this->fieldDetails($field))->values()->all(),
            'record' => $this->recordDetails($record, $fields),
        ]);
    }

    public function update(UpdateDatasetRecordRequest $request, Team $currentTeam, Dataset $dataset, DatasetRecord $record): RedirectResponse
    {
        Gate::authorize('update', $dataset);
        $this->ensureRecordBelongsToDataset($dataset, $record);
        $this->recordService->update($record, (array) $request->validated('values'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Record updated.')]);

        return to_route('datasets.records.show', [$currentTeam->slug, $dataset, $record]);
    }

    public function deactivate(Team $currentTeam, Dataset $dataset, DatasetRecord $record): RedirectResponse
    {
        Gate::authorize('update', $dataset);
        $this->ensureRecordBelongsToDataset($dataset, $record);
        $this->recordService->deactivate($record);

        return to_route('datasets.records.show', [$currentTeam->slug, $dataset, $record]);
    }

    public function activate(Team $currentTeam, Dataset $dataset, DatasetRecord $record): RedirectResponse
    {
        Gate::authorize('update', $dataset);
        $this->ensureRecordBelongsToDataset($dataset, $record);
        $this->recordService->activate($record);

        return to_route('datasets.records.show', [$currentTeam->slug, $dataset, $record]);
    }

    public function destroy(Team $currentTeam, Dataset $dataset, DatasetRecord $record): RedirectResponse
    {
        Gate::authorize('update', $dataset);
        $this->ensureRecordBelongsToDataset($dataset, $record);
        $this->recordService->delete($record);

        return to_route('datasets.records.index', [$currentTeam->slug, $dataset]);
    }

    private function ensureRecordBelongsToDataset(Dataset $dataset, DatasetRecord $record): void
    {
        abort_unless($record->dataset_id === $dataset->id, 404);
    }

    /** @return array{id: int, name: string, slug: string} */
    private function datasetDetails(Dataset $dataset): array
    {
        return ['id' => $dataset->id, 'name' => $dataset->name, 'slug' => $dataset->slug];
    }

    /** @return array<string, mixed> */
    private function fieldDetails(DatasetField $field): array
    {
        return [
            'id' => $field->id,
            'key' => $field->key,
            'label' => $field->label,
            'dataType' => $field->data_type,
            'description' => $field->description,
            'isDisplayable' => $field->is_displayable,
            'config' => $field->config,
        ];
    }

    /**
     * @param  Collection<int, DatasetField>  $fields
     * @return array<string, mixed>
     */
    private function recordDetails(DatasetRecord $record, Collection $fields, bool $includeRaw = false): array
    {
        $payload = (array) $record->payload;

        return [
            'id' => $record->id,
            'externalId' => $record->external_id,
            'origin' => $record->origin,
            'isActive' => $record->is_active,
            'createdAt' => $this->isoDate($record->created_at),
            'updatedAt' => $this->isoDate($record->updated_at),
            'sourceUpdatedAt' => $this->isoDate($record->source_updated_at),
            'values' => $fields->mapWithKeys(fn (DatasetField $field): array => [
                $field->key => [
                    'label' => $field->label,
                    'dataType' => $field->data_type,
                    'value' => $payload[$field->key] ?? null,
                ],
            ])->all(),
            ...($includeRaw ? ['raw' => $payload] : []),
        ];
    }

    private function isoDate(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toISOString() : null;
    }
}
