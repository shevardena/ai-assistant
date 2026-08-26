<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApiConnectionRequest;
use App\Http\Requests\TestApiConnectionRequest;
use App\Models\DataSource;
use App\Models\Team;
use App\Services\Api\ApiConnectionBuilderService;
use App\Services\Onboarding\BusinessTemplateRegistry;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ApiConnectionBuilderController extends Controller
{
    public function __construct(
        private readonly ApiConnectionBuilderService $builder,
        private readonly BusinessTemplateRegistry $templates,
        private readonly DatabaseManager $database,
    ) {}

    public function create(Request $request): Response
    {
        Gate::authorize('create', DataSource::class);

        return Inertia::render('data-sources/api-create', [
            'templateContext' => $this->templateContext($request),
            'authTypes' => $this->authTypes(),
            'operationModes' => [
                ['value' => 'synced', 'labelKey' => 'api_builder.modes.synced'],
                ['value' => 'live_read', 'labelKey' => 'api_builder.modes.live_read'],
                ['value' => 'live_write', 'labelKey' => 'api_builder.modes.live_write'],
            ],
        ]);
    }

    public function createGraphql(Request $request): Response
    {
        Gate::authorize('create', DataSource::class);

        return Inertia::render('data-sources/graphql-create', [
            'templateContext' => $this->templateContext($request),
            'authTypes' => $this->authTypes(),
        ]);
    }

    public function store(StoreApiConnectionRequest $request, Team $currentTeam): RedirectResponse
    {
        $values = $this->builder->connectionValues($request->validated());
        $dataSource = $this->database->transaction(function () use ($currentTeam, $request, $values): DataSource {
            $dataSource = $currentTeam->dataSources()->create([
                'name' => $request->string('name')->toString(),
                'type' => 'rest_api',
                'config' => $values['config'],
            ]);

            $this->syncCredentials($dataSource, $values['credentials']);

            return $dataSource;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('REST API connection created.')]);

        $operationRoute = [
            'current_team' => $currentTeam->slug,
            'data_source' => $dataSource,
        ];

        foreach (['template', 'requirement', 'capability'] as $key) {
            $value = $request->string($key)->toString();

            if ($value !== '') {
                $operationRoute[$key] = $value;
            }
        }

        if ($request->integer('bot') > 0) {
            $operationRoute['bot'] = $request->integer('bot');
        }

        return to_route('data-sources.api-operations.create', $operationRoute);
    }

    public function storeGraphql(StoreApiConnectionRequest $request, Team $currentTeam): RedirectResponse
    {
        abort_unless($request->string('protocol')->toString() === 'graphql', 422);
        $values = $this->builder->graphqlConnectionValues($request->validated());
        $dataSource = $this->database->transaction(function () use ($currentTeam, $request, $values): DataSource {
            $dataSource = $currentTeam->dataSources()->create([
                'name' => $request->string('name')->toString(),
                'type' => 'graphql_api',
                'config' => $values['config'],
            ]);

            $this->syncCredentials($dataSource, $values['credentials']);

            return $dataSource;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('GraphQL connection created.')]);

        $operationRoute = [
            'current_team' => $currentTeam->slug,
            'data_source' => $dataSource,
        ];

        foreach (['template', 'requirement', 'capability'] as $key) {
            $value = $request->string($key)->toString();

            if ($value !== '') {
                $operationRoute[$key] = $value;
            }
        }

        if ($request->integer('bot') > 0) {
            $operationRoute['bot'] = $request->integer('bot');
        }

        return to_route('data-sources.api-operations.create', $operationRoute);
    }

    public function edit(Team $currentTeam, DataSource $dataSource): Response
    {
        Gate::authorize('update', $dataSource);

        return Inertia::render('data-sources/api-create', [
            'dataSource' => [
                'id' => $dataSource->id,
                'name' => $dataSource->name,
                'type' => $dataSource->type,
                'config' => $this->safeConfig($dataSource->config),
            ],
            'templateContext' => null,
            'authTypes' => $this->authTypes(),
            'operationModes' => [],
        ]);
    }

    public function editGraphql(Team $currentTeam, DataSource $dataSource): Response
    {
        Gate::authorize('update', $dataSource);
        abort_unless($dataSource->type === 'graphql_api', 404);

        return Inertia::render('data-sources/graphql-create', [
            'dataSource' => [
                'id' => $dataSource->id,
                'name' => $dataSource->name,
                'type' => $dataSource->type,
                'config' => $this->safeConfig($dataSource->config),
            ],
            'templateContext' => null,
            'authTypes' => $this->authTypes(),
        ]);
    }

    public function update(StoreApiConnectionRequest $request, Team $currentTeam, DataSource $dataSource): RedirectResponse
    {
        Gate::authorize('update', $dataSource);
        $values = $this->builder->connectionValues($request->validated(), $dataSource);

        $this->database->transaction(function () use ($dataSource, $request, $values): void {
            $dataSource->update([
                'name' => $request->string('name')->toString(),
                'config' => $values['config'],
            ]);

            if ((string) ($values['config']['auth_type'] ?? 'none') === 'none') {
                $dataSource->credentials()->delete();
            } else {
                $this->syncCredentials($dataSource, $values['credentials']);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('REST API connection updated.')]);

        return to_route('data-sources.show', [$currentTeam->slug, $dataSource]);
    }

    public function updateGraphql(StoreApiConnectionRequest $request, Team $currentTeam, DataSource $dataSource): RedirectResponse
    {
        Gate::authorize('update', $dataSource);
        abort_unless($dataSource->type === 'graphql_api', 404);
        $values = $this->builder->graphqlConnectionValues($request->validated(), $dataSource);

        $this->database->transaction(function () use ($dataSource, $request, $values): void {
            $dataSource->update([
                'name' => $request->string('name')->toString(),
                'config' => $values['config'],
            ]);

            if ((string) ($values['config']['auth_type'] ?? 'none') === 'none') {
                $dataSource->credentials()->delete();
            } else {
                $this->syncCredentials($dataSource, $values['credentials']);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('GraphQL connection updated.')]);

        return to_route('data-sources.show', [$currentTeam->slug, $dataSource]);
    }

    public function test(TestApiConnectionRequest $request): JsonResponse
    {
        try {
            return response()->json($this->builder->test($request->validated()));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'error' => 'connection_failed',
                'message' => 'The API connection could not be tested safely.',
            ], 422);
        }
    }

    /** @return array<string, mixed>|null */
    private function templateContext(Request $request): ?array
    {
        $template = $this->templates->find((string) $request->query('template'));
        $requirementKey = (string) $request->query('requirement');

        if ($template === null || $requirementKey === '') {
            return null;
        }

        foreach ($template->requirements as $requirement) {
            if ($requirement->key !== $requirementKey) {
                continue;
            }

            return [
                'templateKey' => $template->key,
                'requirementKey' => $requirement->key,
                'titleKey' => $requirement->titleKey,
                'type' => $requirement->type->value,
                'dataMode' => $requirement->dataMode?->value,
                'capabilities' => $requirement->capabilities,
                'suggestedFields' => $requirement->suggestedFields,
                'botId' => $request->integer('bot') ?: null,
            ];
        }

        return null;
    }

    /** @param array<string, string> $credentials */
    private function syncCredentials(DataSource $dataSource, array $credentials): void
    {
        foreach ($credentials as $key => $value) {
            $dataSource->credentials()->updateOrCreate(
                ['key' => $key],
                ['encrypted_value' => $value],
            );
        }
    }

    /** @return array<string, mixed> */
    private function safeConfig(mixed $config): array
    {
        if (! is_array($config)) {
            return [];
        }

        $safe = [];

        foreach ($config as $key => $value) {
            if (preg_match('/(?:authorization|credential|password|secret|token|api[_-]?key)/i', (string) $key) === 1) {
                continue;
            }

            $safe[(string) $key] = is_array($value) ? $this->safeConfig($value) : $value;
        }

        return $safe;
    }

    /** @return list<array{value: string, labelKey: string}> */
    private function authTypes(): array
    {
        return [
            ['value' => 'none', 'labelKey' => 'api_builder.auth.none'],
            ['value' => 'bearer', 'labelKey' => 'api_builder.auth.bearer'],
            ['value' => 'api_key', 'labelKey' => 'api_builder.auth.api_key'],
            ['value' => 'basic', 'labelKey' => 'api_builder.auth.basic'],
            ['value' => 'custom_header', 'labelKey' => 'api_builder.auth.custom_header'],
        ];
    }
}
