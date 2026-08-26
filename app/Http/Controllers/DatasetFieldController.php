<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDatasetFieldRequest;
use App\Http\Requests\UpdateDatasetFieldRequest;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DatasetFieldController extends Controller
{
    /**
     * Show the form for creating a field under the dataset.
     */
    public function create(Team $currentTeam, Dataset $dataset): Response
    {
        Gate::authorize('update', $dataset);

        return Inertia::render('datasets/fields/create', [
            'dataset' => [
                'id' => $dataset->id,
                'name' => $dataset->name,
            ],
        ]);
    }

    /**
     * Store a newly created field under the dataset.
     */
    public function store(StoreDatasetFieldRequest $request, Team $currentTeam, Dataset $dataset): RedirectResponse
    {
        Gate::authorize('update', $dataset);

        $dataset->fields()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dataset field created.')]);

        return to_route('datasets.show', [
            'current_team' => $dataset->team->slug,
            'dataset' => $dataset,
        ]);
    }

    /**
     * Show the form for editing a field under the dataset.
     */
    public function edit(Team $currentTeam, Dataset $dataset, DatasetField $field): Response
    {
        Gate::authorize('update', $dataset);
        $this->ensureFieldBelongsToDataset($dataset, $field);

        return Inertia::render('datasets/fields/edit', [
            'dataset' => [
                'id' => $dataset->id,
                'name' => $dataset->name,
            ],
            'field' => $this->fieldDetails($field),
        ]);
    }

    /**
     * Update a field under the dataset.
     */
    public function update(UpdateDatasetFieldRequest $request, Team $currentTeam, Dataset $dataset, DatasetField $field): RedirectResponse
    {
        Gate::authorize('update', $dataset);
        $this->ensureFieldBelongsToDataset($dataset, $field);

        $field->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dataset field updated.')]);

        return to_route('datasets.show', [
            'current_team' => $dataset->team->slug,
            'dataset' => $dataset,
        ]);
    }

    /**
     * Remove a field under the dataset.
     */
    public function destroy(Team $currentTeam, Dataset $dataset, DatasetField $field): RedirectResponse
    {
        Gate::authorize('update', $dataset);
        $this->ensureFieldBelongsToDataset($dataset, $field);

        $field->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dataset field deleted.')]);

        return to_route('datasets.show', [
            'current_team' => $dataset->team->slug,
            'dataset' => $dataset,
        ]);
    }

    /**
     * Ensure the nested field is owned by the route dataset.
     */
    private function ensureFieldBelongsToDataset(Dataset $dataset, DatasetField $field): void
    {
        abort_unless($field->dataset_id === $dataset->id, 404);
    }

    /**
     * Get the fields required by the field form.
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
        ];
    }
}
