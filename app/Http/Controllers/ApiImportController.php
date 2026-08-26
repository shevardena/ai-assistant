<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApiImportRequest;
use App\Models\ApiOperation;
use App\Models\Dataset;
use App\Models\Team;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Imports\RestApiImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ApiImportController extends Controller
{
    public function __construct(private readonly RestApiImportService $importService) {}

    public function store(StoreApiImportRequest $request, Team $currentTeam, Dataset $dataset): RedirectResponse
    {
        Gate::authorize('update', $dataset);

        try {
            $this->importService->handle(
                $dataset,
                ApiOperation::query()->findOrFail($request->integer('api_operation_id')),
            );
        } catch (ImportException $exception) {
            throw ValidationException::withMessages([
                'api_operation_id' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $dataset->dataSource?->type === 'graphql_api'
                ? __('GraphQL API import completed.')
                : __('REST API import completed.'),
        ]);

        return to_route('datasets.show', [
            'current_team' => $currentTeam->slug,
            'dataset' => $dataset,
        ]);
    }
}
