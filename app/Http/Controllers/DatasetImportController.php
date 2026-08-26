<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDatasetImportRequest;
use App\Models\Dataset;
use App\Models\Team;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Imports\FileImportService;
use App\Services\Typesense\TypesenseDatasetSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DatasetImportController extends Controller
{
    public function __construct(
        private readonly FileImportService $fileImportService,
        private readonly TypesenseDatasetSync $typesenseDatasetSync,
    ) {}

    /**
     * Import a selected source file into the dataset.
     */
    public function store(StoreDatasetImportRequest $request, Team $currentTeam, Dataset $dataset): RedirectResponse
    {
        Gate::authorize('update', $dataset);

        try {
            $sourceRun = $this->fileImportService->handle($dataset, $request->integer('source_file_id'));
        } catch (ImportException $exception) {
            throw ValidationException::withMessages([
                'source_file_id' => $exception->getMessage(),
            ]);
        }

        if ($sourceRun->status === 'completed') {
            $this->typesenseDatasetSync->syncAfterImport($dataset);
        }

        $message = $sourceRun->status === 'completed'
            ? __('Dataset import completed.')
            : __('Dataset import finished with no valid records.');

        Inertia::flash('toast', [
            'type' => $sourceRun->status === 'completed' ? 'success' : 'error',
            'message' => $message,
        ]);

        return to_route('datasets.show', [
            'current_team' => $request->user()?->currentTeam?->slug,
            'dataset' => $dataset,
        ]);
    }
}
