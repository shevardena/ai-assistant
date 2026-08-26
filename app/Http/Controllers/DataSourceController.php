<?php

namespace App\Http\Controllers;

use App\Enums\DataSourceStatus;
use App\Http\Requests\StoreDataSourceRequest;
use App\Http\Requests\UpdateDataSourceRequest;
use App\Models\ApiOperation;
use App\Models\DataSource;
use App\Models\SourceFile;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DataSourceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', DataSource::class);

        $team = $this->currentTeam($request);

        return Inertia::render('data-sources/index', [
            'dataSources' => $team->dataSources()
                ->select(['id', 'name', 'type', 'status', 'last_synced_at', 'created_at', 'updated_at'])
                ->latest()
                ->paginate(10)
                ->withQueryString()
                ->through(fn (DataSource $dataSource): array => $this->summary($dataSource)),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', DataSource::class);

        return Inertia::render('data-sources/create', [
            'templateContext' => array_filter([
                'template' => $request->query('template'),
                'requirement' => $request->query('requirement'),
                'capability' => $request->query('capability'),
                'bot' => $request->integer('bot') ?: null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);
    }

    /**
     * Display the existing file-source setup form after the source chooser.
     */
    public function createFile(): Response
    {
        Gate::authorize('create', DataSource::class);

        return Inertia::render('data-sources/create', [
            'sourceType' => 'file',
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDataSourceRequest $request): RedirectResponse
    {
        $team = $this->currentTeam($request);

        $dataSource = $team->dataSources()->create([
            ...$request->validated(),
            'status' => DataSourceStatus::Pending->value,
            'last_synced_at' => null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Data source created.')]);

        return to_route('data-sources.show', [
            'current_team' => $team->slug,
            'data_source' => $dataSource,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Team $currentTeam, DataSource $dataSource): Response
    {
        Gate::authorize('view', $dataSource);

        $dataSource->load([
            'files:id,data_source_id,original_name,mime_type,size_bytes,status,metadata,created_at',
            'credentials:id,data_source_id,key,created_at,updated_at',
            'apiOperations:id,data_source_id,key,name,type,execution_mode,method,path,response_mapping,timeout_ms,is_enabled,updated_at',
            'apiOperations.syncSchedule',
            'datasets:id,data_source_id,team_id,name',
        ]);

        return Inertia::render('data-sources/show', [
            'dataSource' => $this->details($dataSource),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Team $currentTeam, DataSource $dataSource): Response|RedirectResponse
    {
        Gate::authorize('update', $dataSource);

        if ($dataSource->type === 'rest_api') {
            return to_route('data-sources.api.edit', [
                'current_team' => $currentTeam->slug,
                'data_source' => $dataSource,
            ]);
        }

        if ($dataSource->type === 'graphql_api') {
            return to_route('data-sources.graphql.edit', [
                'current_team' => $currentTeam->slug,
                'data_source' => $dataSource,
            ]);
        }

        return Inertia::render('data-sources/edit', [
            'dataSource' => $this->details($dataSource),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDataSourceRequest $request, Team $currentTeam, DataSource $dataSource): RedirectResponse
    {
        Gate::authorize('update', $dataSource);

        $dataSource->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Data source updated.')]);

        return to_route('data-sources.show', [
            'current_team' => $this->currentTeam($request)->slug,
            'data_source' => $dataSource,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Team $currentTeam, DataSource $dataSource): RedirectResponse
    {
        Gate::authorize('delete', $dataSource);

        $dataSource->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Data source deleted.')]);

        return to_route('data-sources.index', [
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
     * Get the fields required by the index page.
     *
     * @return array<string, mixed>
     */
    private function summary(DataSource $dataSource): array
    {
        $lastSyncedAt = $dataSource->getAttribute('last_synced_at');

        return [
            'id' => $dataSource->id,
            'name' => $dataSource->name,
            'type' => $dataSource->type,
            'status' => $dataSource->status,
            'lastSyncedAt' => $lastSyncedAt instanceof CarbonInterface
                ? $lastSyncedAt->toISOString()
                : null,
            'createdAt' => $dataSource->created_at?->toISOString(),
            'updatedAt' => $dataSource->updated_at?->toISOString(),
        ];
    }

    /**
     * Get the fields required by the edit and show pages.
     *
     * @return array<string, mixed>
     */
    private function details(DataSource $dataSource): array
    {
        return [
            ...$this->summary($dataSource),
            'config' => $this->safeConfig($dataSource->config),
            'sourceFiles' => $dataSource->relationLoaded('files')
                ? $dataSource->files->map(fn (SourceFile $sourceFile): array => $this->sourceFileDetails($sourceFile))->values()->all()
                : [],
            'connection' => in_array($dataSource->type, ['rest_api', 'graphql_api'], true) ? [
                'protocol' => $dataSource->type === 'graphql_api' ? 'graphql' : 'rest',
                'baseUrl' => is_string(data_get($dataSource->config, 'base_url')) ? data_get($dataSource->config, 'base_url') : null,
                'endpoint' => is_string(data_get($dataSource->config, 'endpoint')) ? data_get($dataSource->config, 'endpoint') : null,
                'authType' => is_string(data_get($dataSource->config, 'auth_type')) ? data_get($dataSource->config, 'auth_type') : 'none',
                'credentialsConfigured' => $dataSource->relationLoaded('credentials') && $dataSource->credentials->isNotEmpty(),
                'credentialKeys' => $dataSource->relationLoaded('credentials') ? $dataSource->credentials->pluck('key')->values()->all() : [],
            ] : null,
            'apiOperations' => $dataSource->relationLoaded('apiOperations')
                ? $dataSource->apiOperations->map(fn (ApiOperation $operation): array => [
                    'id' => $operation->id,
                    'key' => $operation->key,
                    'name' => $operation->name,
                    'type' => $operation->type,
                    'executionMode' => $operation->execution_mode,
                    'method' => $operation->method,
                    'path' => $operation->path,
                    'responseMapping' => $operation->response_mapping,
                    'timeoutMs' => $operation->timeout_ms,
                    'isEnabled' => $operation->is_enabled,
                    'updatedAt' => $operation->updated_at?->toISOString(),
                    'syncSchedule' => $operation->relationLoaded('syncSchedule') && $operation->syncSchedule !== null ? [
                        'id' => $operation->syncSchedule->id,
                        'datasetId' => $operation->syncSchedule->dataset_id,
                        'frequency' => $operation->syncSchedule->frequency->value,
                        'strategy' => $operation->syncSchedule->strategy->value,
                        'isEnabled' => $operation->syncSchedule->is_enabled,
                        'pausedAt' => $operation->syncSchedule->paused_at?->toISOString(),
                        'nextRunAt' => $operation->syncSchedule->next_run_at?->toISOString(),
                        'lastStartedAt' => $operation->syncSchedule->last_started_at?->toISOString(),
                        'lastCompletedAt' => $operation->syncSchedule->last_completed_at?->toISOString(),
                        'lastSuccessAt' => $operation->syncSchedule->last_success_at?->toISOString(),
                        'lastFailureAt' => $operation->syncSchedule->last_failure_at?->toISOString(),
                        'consecutiveFailures' => $operation->syncSchedule->consecutive_failures,
                        'lastError' => $operation->syncSchedule->last_error,
                        'configuration' => $operation->syncSchedule->configuration ?? [],
                    ] : null,
                ])->values()->all()
                : [],
            'datasets' => $dataSource->relationLoaded('datasets')
                ? $dataSource->datasets->map(fn ($dataset): array => [
                    'id' => $dataset->id,
                    'name' => $dataset->name,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * Get safe metadata for a source file without exposing its storage path.
     *
     * @return array<string, mixed>
     */
    private function sourceFileDetails(SourceFile $sourceFile): array
    {
        $metadata = (array) $sourceFile->metadata;
        $extension = Arr::get($metadata, 'extension');

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
     * Never expose credential-shaped values from public source configuration.
     *
     * @return array<string, mixed>
     */
    private function safeConfig(mixed $config): array
    {
        if (! is_array($config)) {
            return [];
        }

        $safe = [];

        foreach ($config as $key => $value) {
            $key = (string) $key;

            if (in_array(strtolower($key), ['api_key', 'bearer_token', 'token', 'secret', 'password', 'authorization', 'encrypted_value'], true)) {
                continue;
            }

            $safe[$key] = is_array($value) ? $this->safeConfig($value) : $value;
        }

        return $safe;
    }
}
