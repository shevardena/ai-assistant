<?php

namespace App\Http\Controllers;

use App\Enums\ApiOperationSyncFrequency;
use App\Enums\ApiOperationSyncStrategy;
use App\Http\Requests\RunApiOperationSyncRequest;
use App\Http\Requests\StoreApiOperationRequest;
use App\Http\Requests\TestApiOperationRequest;
use App\Http\Requests\UpdateApiOperationRequest;
use App\Http\Requests\UpdateApiOperationSyncScheduleRequest;
use App\Jobs\RunApiOperationSync;
use App\Models\ApiOperation;
use App\Models\DataSource;
use App\Models\Team;
use App\Services\Ai\BotToolRegistry;
use App\Services\Api\ApiConnectionBuilderService;
use App\Services\Api\Exceptions\GraphqlRequestException;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Sync\ApiOperationSyncScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApiOperationController extends Controller
{
    public function __construct(
        private readonly ApiConnectionBuilderService $builder,
        private readonly BotToolRegistry $tools,
        private readonly ApiOperationSyncScheduleService $syncSchedules,
    ) {}

    public function create(Request $request, Team $currentTeam, DataSource $dataSource): Response
    {
        Gate::authorize('manageApiOperations', $dataSource);
        $this->ensureSupportedApi($dataSource);

        return Inertia::render($dataSource->type === 'graphql_api'
            ? 'data-sources/graphql-operation-create'
            : 'data-sources/api-operation-create', [
                'dataSource' => [
                    'id' => $dataSource->id,
                    'name' => $dataSource->name,
                    'config' => $this->safeConfig($dataSource->config),
                ],
                'templateContext' => $this->templateContext($request, $currentTeam),
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function index(Team $currentTeam, DataSource $dataSource): array
    {
        Gate::authorize('manageApiOperations', $dataSource);
        $this->ensureSupportedApi($dataSource);

        return array_values($dataSource->apiOperations()
            ->select(['id', 'data_source_id', 'key', 'name', 'type', 'execution_mode', 'method', 'path', 'response_mapping', 'timeout_ms', 'is_enabled'])
            ->latest()
            ->get()
            ->map(fn (ApiOperation $operation): array => [
                'id' => $operation->id,
                'key' => $operation->key,
                'name' => $operation->name,
                'type' => $operation->type,
                'executionMode' => (string) $operation->execution_mode,
                'method' => $operation->method,
                'path' => $operation->path,
                'responseMapping' => $operation->response_mapping,
                'timeoutMs' => $operation->timeout_ms,
                'isEnabled' => $operation->is_enabled,
                'protocol' => $dataSource->type === 'graphql_api' ? 'graphql' : 'rest',
            ])
            ->values()
            ->all());
    }

    public function store(StoreApiOperationRequest $request, Team $currentTeam, DataSource $dataSource): RedirectResponse
    {
        Gate::authorize('manageApiOperations', $dataSource);
        $this->ensureSupportedApi($dataSource);
        $values = $this->operationValues($dataSource, $request->validated());
        $this->validateCapability($request->validated(), $values['execution_mode']);
        $operation = $dataSource->apiOperations()->create($values);
        $this->attachCapability($request, $currentTeam, $operation);
        $this->syncScheduleForOperation($operation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API operation created.')]);

        return to_route('data-sources.show', [
            'current_team' => $currentTeam->slug,
            'data_source' => $dataSource,
        ]);
    }

    public function update(UpdateApiOperationRequest $request, Team $currentTeam, DataSource $dataSource, ApiOperation $apiOperation): RedirectResponse
    {
        Gate::authorize('manageApiOperations', $dataSource);
        $this->ensureSupportedApi($dataSource);
        abort_unless($apiOperation->data_source_id === $dataSource->id, 404);
        $values = $this->operationValues($dataSource, $request->validated());
        $this->validateCapability($request->validated(), $values['execution_mode']);
        $apiOperation->update($values);
        $this->syncScheduleForOperation($apiOperation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API operation updated.')]);

        return to_route('data-sources.show', [
            'current_team' => $currentTeam->slug,
            'data_source' => $dataSource,
        ]);
    }

    public function destroy(Team $currentTeam, DataSource $dataSource, ApiOperation $apiOperation): RedirectResponse
    {
        Gate::authorize('manageApiOperations', $dataSource);
        $this->ensureSupportedApi($dataSource);
        abort_unless($apiOperation->data_source_id === $dataSource->id, 404);
        $apiOperation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API operation deleted.')]);

        return to_route('data-sources.show', [
            'current_team' => $currentTeam->slug,
            'data_source' => $dataSource,
        ]);
    }

    public function test(TestApiOperationRequest $request, Team $currentTeam, DataSource $dataSource): JsonResponse
    {
        Gate::authorize('manageApiOperations', $dataSource);
        $this->ensureSupportedApi($dataSource);

        try {
            return response()->json($dataSource->type === 'graphql_api'
                ? $this->builder->testGraphqlOperation($dataSource, $request->validated())
                : $this->builder->testOperation($dataSource, $request->validated()));
        } catch (GraphqlRequestException $exception) {
            return response()->json([
                'ok' => false,
                'error' => $exception->errorType,
                'message' => 'The GraphQL operation could not be tested safely.',
            ], 422);
        } catch (ImportException $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'error' => 'request_failed',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'error' => 'request_failed',
                'message' => 'The API operation could not be tested safely.',
            ], 422);
        }
    }

    public function updateSyncSchedule(
        UpdateApiOperationSyncScheduleRequest $request,
        Team $currentTeam,
        DataSource $dataSource,
        ApiOperation $apiOperation,
    ): RedirectResponse {
        Gate::authorize('manageApiOperations', $dataSource);
        abort_unless((int) $apiOperation->data_source_id === (int) $dataSource->id, 404);
        $schedule = $this->syncSchedules->ensure($apiOperation);
        $validated = $request->validated();
        $dataset = ! empty($validated['dataset_id'])
            ? $dataSource->datasets()->whereKey($validated['dataset_id'])->firstOrFail()
            : null;

        $this->syncSchedules->configure(
            $schedule,
            ApiOperationSyncFrequency::from($validated['frequency']),
            ApiOperationSyncStrategy::from($validated['strategy']),
            $dataset,
            (array) ($validated['configuration'] ?? []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sync schedule updated.')]);

        return to_route('data-sources.show', ['current_team' => $currentTeam->slug, 'data_source' => $dataSource]);
    }

    public function runSync(
        RunApiOperationSyncRequest $request,
        Team $currentTeam,
        DataSource $dataSource,
        ApiOperation $apiOperation,
    ): RedirectResponse {
        Gate::authorize('manageApiOperations', $dataSource);
        abort_unless((int) $apiOperation->data_source_id === (int) $dataSource->id, 404);
        $dataset = ! $request->integer('dataset_id')
            ? null
            : $dataSource->datasets()->whereKey($request->integer('dataset_id'))->firstOrFail();

        try {
            $schedule = $this->syncSchedules->claimManual($apiOperation, $dataset);
            RunApiOperationSync::dispatchSync($schedule->id);
        } catch (ImportException $exception) {
            throw ValidationException::withMessages(['sync' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Synchronization completed.')]);

        return to_route('data-sources.show', ['current_team' => $currentTeam->slug, 'data_source' => $dataSource]);
    }

    public function pauseSync(Team $currentTeam, DataSource $dataSource, ApiOperation $apiOperation): RedirectResponse
    {
        Gate::authorize('manageApiOperations', $dataSource);
        abort_unless((int) $apiOperation->data_source_id === (int) $dataSource->id, 404);
        $this->syncSchedules->pause($this->syncSchedules->ensure($apiOperation));

        return to_route('data-sources.show', ['current_team' => $currentTeam->slug, 'data_source' => $dataSource]);
    }

    public function resumeSync(Team $currentTeam, DataSource $dataSource, ApiOperation $apiOperation): RedirectResponse
    {
        Gate::authorize('manageApiOperations', $dataSource);
        abort_unless((int) $apiOperation->data_source_id === (int) $dataSource->id, 404);
        $this->syncSchedules->resume($this->syncSchedules->ensure($apiOperation));

        return to_route('data-sources.show', ['current_team' => $currentTeam->slug, 'data_source' => $dataSource]);
    }

    private function syncScheduleForOperation(ApiOperation $operation): void
    {
        if (($operation->response_mapping['sync_mode'] ?? null) === ApiOperationSyncStrategy::FullSnapshot->value) {
            $this->syncSchedules->ensure($operation);

            return;
        }

        $operation->syncSchedule()->update([
            'is_enabled' => false,
            'next_run_at' => null,
            'paused_at' => now(),
        ]);
    }

    private function ensureSupportedApi(DataSource $dataSource): void
    {
        abort_unless(in_array($dataSource->type, ['rest_api', 'graphql_api'], true), 404);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function operationValues(DataSource $dataSource, array $input): array
    {
        try {
            return $dataSource->type === 'graphql_api'
                ? $this->builder->graphqlOperationValues($input)
                : $this->builder->operationValues($input);
        } catch (GraphqlRequestException $exception) {
            throw ValidationException::withMessages([
                'graphql_document' => $exception->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $input */
    private function validateCapability(array $input, string $executionMode): void
    {
        $capability = $input['capability'] ?? null;

        if (! is_string($capability) || $capability === '') {
            return;
        }

        $write = ['add_to_cart', 'capture_lead', 'book_appointment', 'create_support_ticket'];

        if (! in_array($capability, $this->tools->knownToolNames(), true)
            || ($executionMode === 'write') !== in_array($capability, $write, true)) {
            throw ValidationException::withMessages([
                'capability' => 'Choose a capability compatible with this operation.',
            ]);
        }
    }

    private function attachCapability(StoreApiOperationRequest $request, Team $team, ApiOperation $operation): void
    {
        $capability = $request->string('capability')->toString();
        $botId = $request->integer('bot');

        if ($capability === '' || $botId < 1) {
            return;
        }

        $bot = $team->bots()->find($botId);

        if ($bot === null) {
            return;
        }

        $bot->botApiOperations()->updateOrCreate(
            ['api_operation_id' => $operation->id],
            [
                'tool_name' => $capability,
                'is_enabled' => true,
                'settings' => [
                    'input_mapping' => $this->inputMapping($request->input('input_mapping', [])),
                ],
            ],
        );
    }

    /**
     * Convert the builder's editable rows into the runtime mapper's keyed shape.
     *
     * @return array<string, array<string, string>>
     */
    private function inputMapping(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (! array_is_list($value)) {
            $mapping = [];

            foreach ($value as $modelInput => $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                $mapping[(string) $modelInput] = array_filter([
                    'source' => (string) ($definition['source'] ?? 'model_input'),
                    'dataset_field' => (string) ($definition['dataset_field'] ?? $definition['field'] ?? ''),
                    'context_key' => (string) ($definition['context_key'] ?? $definition['context'] ?? ''),
                    'operation_argument' => (string) ($definition['operation_argument'] ?? $definition['argument'] ?? $modelInput),
                    'model_input' => (string) ($definition['model_input'] ?? $modelInput),
                ], static fn (string $item): bool => $item !== '');
            }

            return $mapping;
        }

        $mapping = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $modelInput = (string) ($row['model_input'] ?? '');

            if ($modelInput === '') {
                continue;
            }

            $mapping[$modelInput] = array_filter([
                'source' => (string) ($row['source'] ?? 'model_input'),
                'dataset_field' => (string) ($row['dataset_field'] ?? ''),
                'context_key' => (string) ($row['context_key'] ?? ''),
                'operation_argument' => (string) ($row['operation_argument'] ?? $modelInput),
                'model_input' => $modelInput,
            ], static fn (string $item): bool => $item !== '');
        }

        return $mapping;
    }

    /** @return array<string, mixed>|null */
    private function templateContext(Request $request, Team $currentTeam): ?array
    {
        $requirement = (string) $request->query('requirement');

        return $requirement === '' ? null : [
            'requirementKey' => $requirement,
            'capability' => $request->query('capability'),
            'botId' => $currentTeam->bots()->whereKey($request->integer('bot'))->exists()
                ? $request->integer('bot')
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function safeConfig(mixed $config): array
    {
        if (! is_array($config)) {
            return [];
        }

        unset($config['api_key'], $config['bearer_token'], $config['token'], $config['secret'], $config['password'], $config['authorization']);

        return $config;
    }
}
