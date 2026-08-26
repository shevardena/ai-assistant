<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDataSourceCredentialRequest;
use App\Models\DataSource;
use App\Models\DataSourceCredential;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class DataSourceCredentialController extends Controller
{
    /**
     * @return list<array<string, mixed>>
     */
    public function index(Team $currentTeam, DataSource $dataSource): array
    {
        Gate::authorize('manageCredentials', $dataSource);
        $this->ensureSupportedApi($dataSource);

        return array_values($dataSource->credentials()
            ->select(['id', 'data_source_id', 'key', 'created_at', 'updated_at'])
            ->get()
            ->map(fn (DataSourceCredential $credential): array => [
                'id' => $credential->id,
                'key' => $credential->key,
                'configured' => true,
                'createdAt' => $credential->created_at?->toISOString(),
                'updatedAt' => $credential->updated_at?->toISOString(),
            ])
            ->values()
            ->all());
    }

    public function store(StoreDataSourceCredentialRequest $request, Team $currentTeam, DataSource $dataSource): RedirectResponse
    {
        Gate::authorize('manageCredentials', $dataSource);
        $this->ensureSupportedApi($dataSource);
        $validated = $request->validated();

        $dataSource->credentials()->updateOrCreate(
            ['key' => $validated['key']],
            ['encrypted_value' => $validated['secret']],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credential saved.')]);

        return to_route('data-sources.credentials.index', [
            'current_team' => $currentTeam->slug,
            'data_source' => $dataSource,
        ]);
    }

    public function destroy(Team $currentTeam, DataSource $dataSource, DataSourceCredential $credential): RedirectResponse
    {
        Gate::authorize('manageCredentials', $dataSource);
        $this->ensureSupportedApi($dataSource);
        abort_unless($credential->data_source_id === $dataSource->id, 404);
        $credential->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credential removed.')]);

        return to_route('data-sources.credentials.index', [
            'current_team' => $currentTeam->slug,
            'data_source' => $dataSource,
        ]);
    }

    private function ensureSupportedApi(DataSource $dataSource): void
    {
        abort_unless(in_array($dataSource->type, ['rest_api', 'graphql_api'], true), 404);
    }
}
