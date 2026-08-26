<?php

use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\DataSource;
use App\Models\DataSourceCredential;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('the primary data source creation route is a source-type chooser', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->get(route('data-sources.create', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-sources/create')
            ->missing('sourceType'),
        );

    $this->actingAs($user)
        ->get(route('data-sources.create.file', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-sources/create')
            ->where('sourceType', 'file'),
        );

    $this->actingAs($user)
        ->get(route('data-sources.api.create', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-sources/api-create')
            ->has('authTypes'),
        );
});

test('a REST API connection stores structured configuration and encrypted credentials', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)->post(route('data-sources.api.store', [
        'current_team' => $team->slug,
    ]), [
        'name' => 'Store API',
        'base_url' => 'https://api.example.test',
        'auth_type' => 'bearer',
        'bearer_token' => 'top-secret-token',
        'default_headers' => ['Accept' => 'application/json'],
        'default_query_parameters' => ['locale' => 'en'],
    ]);

    $dataSource = DataSource::query()->where('name', 'Store API')->firstOrFail();
    $credential = DataSourceCredential::query()->where('data_source_id', $dataSource->id)->firstOrFail();

    $response->assertRedirect(route('data-sources.api-operations.create', [
        'current_team' => $team->slug,
        'data_source' => $dataSource,
    ]));

    expect($dataSource->type)->toBe('rest_api')
        ->and($dataSource->config)->toMatchArray([
            'base_url' => 'https://api.example.test',
            'auth_type' => 'bearer',
            'default_headers' => ['Accept' => 'application/json'],
        ])
        ->and($credential->key)->toBe('bearer_token')
        ->and($credential->getRawOriginal('encrypted_value'))->not->toContain('top-secret-token');
});

test('saving a connection ignores stale credentials for other authentication methods', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->post(route('data-sources.api.store', ['current_team' => $team->slug]), [
            'name' => 'Store API',
            'base_url' => 'https://api.example.test',
            'auth_type' => 'bearer',
            'bearer_token' => 'top-secret-token',
            'api_key' => ['stale' => 'browser-value'],
        ])
        ->assertRedirect();

    $dataSource = DataSource::query()->where('name', 'Store API')->firstOrFail();

    expect($dataSource->credentials()->pluck('key')->all())->toBe(['bearer_token']);
});

test('the API connection page does not expose stored secrets', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $dataSource = DataSource::factory()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test', 'auth_type' => 'bearer'],
    ]);
    $dataSource->credentials()->create(['key' => 'bearer_token', 'encrypted_value' => 'top-secret-token']);

    $this->actingAs($user)
        ->get(route('data-sources.api.edit', [
            'current_team' => $team->slug,
            'data_source' => $dataSource,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-sources/api-create')
            ->where('dataSource.config.base_url', 'https://api.example.test')
            ->missing('dataSource.config.bearer_token')
            ->missing('dataSource.credentials.0.encrypted_value'),
        );
});

test('legacy REST API edit links redirect to the structured connection builder', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $dataSource = DataSource::factory()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
    ]);

    $this->actingAs($user)
        ->get(route('data-sources.edit', [
            'current_team' => $team->slug,
            'data_source' => $dataSource,
        ]))
        ->assertRedirect(route('data-sources.api.edit', [
            'current_team' => $team->slug,
            'data_source' => $dataSource,
        ]));
});

test('connection tests use bearer credentials and return a bounded safe preview', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response([
            'data' => ['products' => [['id' => 1, 'name' => 'A']]],
            'token' => 'provider-secret',
        ], 200, ['Content-Type' => 'application/json']),
    ]);

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)
        ->postJson(route('data-sources.api.test', ['current_team' => $team->slug]), [
            'base_url' => 'https://api.example.test',
            'auth_type' => 'bearer',
            'bearer_token' => 'top-secret-token',
        ])
        ->assertOk()
        ->assertJsonPath('recordArrays.0.path', 'data.products');

    expect($response->getContent())
        ->not->toContain('top-secret-token')
        ->not->toContain('provider-secret');

    Http::assertSent(fn ($request): bool => $request->header('Authorization') === ['Bearer top-secret-token']);
});

test('connection tests reject private and local URLs', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->postJson(route('data-sources.api.test', ['current_team' => $team->slug]), [
            'base_url' => 'http://127.0.0.1:8080',
            'auth_type' => 'none',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'connection_failed');
});

test('API operations are isolated to the current team and write tests are dry runs', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $foreignTeam = Team::factory()->create();
    $foreignSource = DataSource::factory()->create(['team_id' => $foreignTeam->id, 'type' => 'rest_api']);

    $this->actingAs($user)
        ->get(route('data-sources.api-operations.create', [
            'current_team' => $team->slug,
            'data_source' => $foreignSource,
        ]))
        ->assertNotFound();

    $source = DataSource::factory()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
    ]);

    Http::fake();

    $this->actingAs($user)
        ->postJson(route('data-sources.api-operations.test', [
            'current_team' => $team->slug,
            'data_source' => $source,
        ]), [
            'key' => 'add-to-cart',
            'name' => 'Add to cart',
            'usage' => 'live_write',
            'method' => 'POST',
            'path' => '/cart/items',
            'body_parameters' => [['name' => 'product_id', 'source' => 'tool_argument', 'required' => true]],
            'test_arguments' => ['product_id' => 'sku-1'],
        ])
        ->assertOk()
        ->assertJsonPath('dryRun', true);

    Http::assertNothingSent();
});

test('saving an API operation redirects back to the Inertia data source page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $source = DataSource::factory()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
    ]);

    $this->actingAs($user)
        ->post(route('data-sources.api-operations.store', [
            'current_team' => $team->slug,
            'data_source' => $source,
        ]), [
            'key' => 'list-products',
            'name' => 'List products',
            'usage' => 'live_read',
            'method' => 'GET',
            'path' => '/products',
            'response_fields' => [['name' => 'id', 'path' => 'id', 'required' => true]],
            'pagination' => ['type' => 'none'],
        ])
        ->assertRedirect(route('data-sources.show', [
            'current_team' => $team->slug,
            'data_source' => $source,
        ]));

    expect($source->apiOperations()->where('key', 'list-products')->exists())->toBeTrue();
});

test('an existing API operation opens in the edit form and updates without creating a duplicate', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $source = DataSource::factory()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
    ]);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $source->id,
        'key' => 'find-products',
        'name' => 'Find products',
        'path' => '/products',
        'request_schema' => [
            'type' => 'object',
            'properties' => ['per_page' => ['type' => 'integer']],
            'required' => [],
        ],
        'request_mapping' => [
            'query' => ['per_page' => 'per_page'],
            'fixed' => ['query' => []],
        ],
        'response_mapping' => [
            'output' => [
                'id' => ['path' => 'id', 'required' => true],
            ],
            'pagination' => ['type' => 'none'],
        ],
    ]);
    BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'search_catalog',
    ]);

    $this->actingAs($user)
        ->get(route('data-sources.api-operations.edit', [
            'current_team' => $team->slug,
            'data_source' => $source,
            'api_operation' => $operation,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-sources/api-operation-create')
            ->where('operation.id', $operation->id)
            ->where('operation.key', 'find-products')
            ->where('operation.query_parameters.0.name', 'per_page')
            ->where('operation.capability', 'search_catalog'),
        );

    $this->actingAs($user)
        ->put(route('data-sources.api-operations.update', [
            'current_team' => $team->slug,
            'data_source' => $source,
            'api_operation' => $operation,
        ]), [
            'key' => 'find-products',
            'name' => 'Find products live',
            'usage' => 'live_read',
            'method' => 'GET',
            'path' => '/products',
            'capability' => 'search_catalog',
            'bot' => $bot->id,
            'query_parameters' => [['name' => 'per_page', 'source' => 'fixed', 'value' => '20', 'type' => 'integer']],
            'response_fields' => [['name' => 'id', 'path' => 'id', 'required' => true]],
            'pagination' => ['type' => 'none'],
        ])
        ->assertRedirect(route('data-sources.show', [
            'current_team' => $team->slug,
            'data_source' => $source,
        ]));

    expect($source->apiOperations()->count())->toBe(1)
        ->and($operation->fresh()->name)->toBe('Find products live')
        ->and($operation->botApiOperations()->firstOrFail()->tool_name)->toBe('search_catalog');
});
