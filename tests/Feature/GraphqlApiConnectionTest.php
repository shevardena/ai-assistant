<?php

use App\Models\ApiOperation;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\DataSource;
use App\Models\DataSourceCredential;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('GraphQL connections use a dedicated endpoint and encrypted credentials', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->get(route('data-sources.graphql.create', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-sources/graphql-create')
            ->has('authTypes'));

    $this->actingAs($user)->post(route('data-sources.graphql.store', ['current_team' => $team->slug]), [
        'protocol' => 'graphql',
        'name' => 'Catalog GraphQL',
        'endpoint' => 'https://api.example.test/graphql',
        'auth_type' => 'bearer',
        'bearer_token' => 'top-secret-token',
        'default_variables' => ['locale' => 'en'],
    ])->assertRedirect();

    $source = DataSource::query()->where('name', 'Catalog GraphQL')->firstOrFail();
    $credential = DataSourceCredential::query()->where('data_source_id', $source->id)->firstOrFail();

    expect($source->type)->toBe('graphql_api')
        ->and($source->config)->toMatchArray([
            'protocol' => 'graphql',
            'endpoint' => 'https://api.example.test/graphql',
            'default_variables' => ['locale' => 'en'],
        ])
        ->and($credential->key)->toBe('bearer_token')
        ->and($credential->getRawOriginal('encrypted_value'))->not->toContain('top-secret-token');
});

test('GraphQL connection details are tenant scoped and never expose credentials', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $source = DataSource::factory()->create([
        'team_id' => $team->id,
        'type' => 'graphql_api',
        'config' => ['endpoint' => 'https://api.example.test/graphql', 'auth_type' => 'bearer'],
    ]);
    $source->credentials()->create(['key' => 'bearer_token', 'encrypted_value' => 'top-secret-token']);

    $this->actingAs($user)
        ->get(route('data-sources.graphql.edit', ['current_team' => $team->slug, 'data_source' => $source]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-sources/graphql-create')
            ->where('dataSource.config.endpoint', 'https://api.example.test/graphql')
            ->missing('dataSource.credentials.0.encrypted_value'));

    $foreignTeam = Team::factory()->create();
    $foreignSource = DataSource::factory()->create([
        'team_id' => $foreignTeam->id,
        'type' => 'graphql_api',
        'config' => ['endpoint' => 'https://foreign.example.test/graphql'],
    ]);

    $this->actingAs($user)
        ->get(route('data-sources.graphql.edit', ['current_team' => $team->slug, 'data_source' => $foreignSource]))
        ->assertNotFound();
});

test('GraphQL endpoints use the shared SSRF validation', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->postJson(route('data-sources.graphql.store', ['current_team' => $team->slug]), [
            'protocol' => 'graphql',
            'name' => 'Local GraphQL',
            'endpoint' => 'http://127.0.0.1:8080/graphql',
            'auth_type' => 'none',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('endpoint');
});

test('GraphQL operation tests send a query envelope and map tool variables', function () {
    Http::fake([
        'https://api.example.test/graphql' => Http::response([
            'data' => ['order' => ['status' => 'shipped']],
        ]),
    ]);

    $user = User::factory()->create();
    $team = $user->currentTeam;
    $source = DataSource::factory()->create([
        'team_id' => $team->id,
        'type' => 'graphql_api',
        'config' => ['endpoint' => 'https://api.example.test/graphql', 'auth_type' => 'none'],
    ]);

    $this->actingAs($user)
        ->postJson(route('data-sources.api-operations.test', ['current_team' => $team->slug, 'data_source' => $source]), [
            'protocol' => 'graphql',
            'key' => 'order-status',
            'name' => 'Order status',
            'usage' => 'live_read',
            'method' => 'POST',
            'path' => '/',
            'graphql_document' => 'query Order($orderId: ID!) { order(id: $orderId) { status } }',
            'graphql_operation_name' => 'Order',
            'graphql_variables' => [['name' => 'orderId', 'source' => 'tool_argument', 'argument' => 'order_id']],
            'response_fields' => [['name' => 'status', 'path' => 'order.status', 'required' => true]],
            'test_arguments' => ['order_id' => 'order-7'],
        ])
        ->assertOk();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.example.test/graphql'
            && $request->data()['operationName'] === 'Order'
            && $request->data()['variables'] === ['orderId' => 'order-7'];
    });
});

test('GraphQL errors in an HTTP 200 response are reported as execution failures', function () {
    Http::fake(['https://api.example.test/graphql' => Http::response(['errors' => [['message' => 'Nope']]])]);
    $user = User::factory()->create();
    $source = DataSource::factory()->create([
        'team_id' => $user->currentTeam->id,
        'type' => 'graphql_api',
        'config' => ['endpoint' => 'https://api.example.test/graphql', 'auth_type' => 'none'],
    ]);

    $this->actingAs($user)
        ->postJson(route('data-sources.api-operations.test', [$user->currentTeam->slug, $source->id]), [
            'protocol' => 'graphql', 'key' => 'bad', 'name' => 'Bad query', 'usage' => 'live_read', 'method' => 'POST', 'path' => '/',
            'graphql_document' => 'query { order { status } }', 'response_fields' => [],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'graphql_execution_failed');
});

test('invalid GraphQL documents are rejected before the provider is called', function () {
    Http::fake();
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $source = DataSource::factory()->create([
        'team_id' => $team->id,
        'type' => 'graphql_api',
        'config' => ['endpoint' => 'https://api.example.test/graphql', 'auth_type' => 'none'],
    ]);

    $this->actingAs($user)
        ->postJson(route('data-sources.api-operations.test', ['current_team' => $team->slug, 'data_source' => $source]), [
            'protocol' => 'graphql',
            'key' => 'invalid-query',
            'name' => 'Invalid query',
            'usage' => 'live_read',
            'method' => 'POST',
            'path' => '/',
            'graphql_document' => 'query {',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'graphql_query_invalid');

    Http::assertNothingSent();
});

test('GraphQL mutation previews never send an outbound request', function () {
    Http::fake();
    $user = User::factory()->create();
    $source = DataSource::factory()->create([
        'team_id' => $user->currentTeam->id,
        'type' => 'graphql_api',
        'config' => ['endpoint' => 'https://api.example.test/graphql', 'auth_type' => 'none'],
    ]);

    $this->actingAs($user)
        ->postJson(route('data-sources.api-operations.test', [$user->currentTeam->slug, $source->id]), [
            'protocol' => 'graphql', 'key' => 'add-cart', 'name' => 'Add cart', 'usage' => 'live_write', 'method' => 'POST', 'path' => '/',
            'graphql_document' => 'mutation Add($id: ID!) { add(id: $id) { ok } }',
            'graphql_operation_name' => 'Add',
            'graphql_variables' => [['name' => 'id', 'source' => 'tool_argument', 'argument' => 'id']],
            'test_arguments' => ['id' => 'sku-1'],
        ])
        ->assertOk()
        ->assertJsonPath('dryRun', true);

    Http::assertNothingSent();
});

test('GraphQL relay imports normalize records and follow cursors once', function () {
    Http::fakeSequence()
        ->push(['data' => ['products' => ['nodes' => [['id' => 'A', 'name' => 'A']], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1']]]])
        ->push(['data' => ['products' => ['nodes' => [['id' => 'B', 'name' => 'B']], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'cursor-2']]]]);

    $user = User::factory()->create();
    $team = $user->currentTeam;
    $source = DataSource::factory()->create(['team_id' => $team->id, 'type' => 'graphql_api', 'config' => ['endpoint' => 'https://api.example.test/graphql', 'auth_type' => 'none']]);
    $dataset = Dataset::factory()->create(['team_id' => $team->id, 'data_source_id' => $source->id, 'primary_key_path' => 'id']);
    DatasetField::factory()->create(['dataset_id' => $dataset->id, 'source_path' => 'name', 'key' => 'name', 'data_type' => 'string']);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $source->id,
        'type' => 'query', 'execution_mode' => 'read', 'method' => 'POST', 'path' => '/',
        'request_schema' => [],
        'request_mapping' => ['graphql' => ['document' => 'query Products($after: String) { products(after: $after) { nodes { id name } pageInfo { hasNextPage endCursor } } }', 'operation_name' => 'Products', 'variables' => ['after' => ['source' => 'fixed', 'value' => null]]]],
        'response_mapping' => ['records_path' => 'products.nodes', 'sync_mode' => 'full_snapshot', 'pagination' => ['type' => 'relay_cursor', 'has_next_path' => 'products.pageInfo.hasNextPage', 'cursor_path' => 'products.pageInfo.endCursor', 'cursor_variable' => 'after', 'max_pages' => 5]],
    ]);

    $this->actingAs($user)
        ->post(route('datasets.api-imports.store', [$team->slug, $dataset]), ['api_operation_id' => $operation->id])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(DatasetRecord::query()->where('dataset_id', $dataset->id)->pluck('external_id')->all())->toBe(['A', 'B']);
    Http::assertSentCount(2);
});
