<?php

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
