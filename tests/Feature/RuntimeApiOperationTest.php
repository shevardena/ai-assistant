<?php

use App\Enums\ApiOperationMode;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\DataSource;
use App\Models\DataSourceCredential;
use App\Models\User;
use App\Services\Api\RuntimeApiOperation;
use App\Services\Api\RuntimeApiOperationExecutor;
use App\Services\Api\RuntimeApiOperationResolver;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: User, 1: Bot, 2: DataSource, 3: ApiOperation, 4: BotApiOperation}
 */
function runtimeApiOperationContext(array $operationOverrides = []): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $dataSource = DataSource::factory()->ready()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
    ]);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $dataSource->id,
        'key' => 'stock_lookup',
        'name' => 'Stock lookup',
        'execution_mode' => ApiOperationMode::Read->value,
        'method' => 'GET',
        'path' => '/products/{sku}/stock',
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string'],
                'store_id' => ['type' => 'string'],
            ],
            'required' => ['sku'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'path' => ['sku' => 'sku'],
        ],
        'response_mapping' => [
            'output' => [
                'available' => 'data.inventory.available',
                'quantity' => 'data.inventory.quantity',
            ],
        ],
        ...$operationOverrides,
    ]);
    $attachment = BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'check_stock',
        'is_enabled' => true,
    ]);

    return [$user, $bot, $dataSource, $operation, $attachment];
}

function resolveRuntimeApiOperation(Bot $bot, string $identifier = 'check_stock'): ?RuntimeApiOperation
{
    return app(RuntimeApiOperationResolver::class)->resolve($bot, $identifier);
}

test('resolves only a same-team enabled operation attached to the bot', function () {
    [, $bot] = runtimeApiOperationContext();

    expect(resolveRuntimeApiOperation($bot))
        ->toBeInstanceOf(RuntimeApiOperation::class);
});

test('does not resolve operations from another team or unattached operations', function () {
    [, $bot, $dataSource] = runtimeApiOperationContext();
    [, $otherBot, $otherDataSource, $otherOperation] = runtimeApiOperationContext();

    expect(resolveRuntimeApiOperation($bot))->toBeInstanceOf(RuntimeApiOperation::class);

    BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $otherOperation->id,
        'tool_name' => 'foreign_tool',
    ]);
    $unattachedOperation = ApiOperation::factory()->create([
        'data_source_id' => $dataSource->id,
        'execution_mode' => ApiOperationMode::Read->value,
    ]);

    expect(resolveRuntimeApiOperation($bot, 'foreign_tool'))->toBeNull()
        ->and(resolveRuntimeApiOperation($bot, 'unattached_tool'))->toBeNull()
        ->and($otherBot->team_id)->not->toBe($bot->team_id)
        ->and($otherDataSource->team_id)->not->toBe($dataSource->team_id)
        ->and($unattachedOperation->data_source_id)->toBe($dataSource->id);
});

test('does not resolve disabled attachments disabled operations or write operations', function () {
    [, $bot, , $operation, $attachment] = runtimeApiOperationContext();

    $attachment->update(['is_enabled' => false]);
    expect(resolveRuntimeApiOperation($bot))->toBeNull();

    $attachment->update(['is_enabled' => true]);
    $operation->update(['is_enabled' => false]);
    expect(resolveRuntimeApiOperation($bot))->toBeNull();

    $operation->update([
        'is_enabled' => true,
        'execution_mode' => ApiOperationMode::Write->value,
    ]);

    expect(resolveRuntimeApiOperation($bot))->toBeNull();
});

test('validates required unknown and typed runtime arguments', function () {
    [, $bot] = runtimeApiOperationContext();
    $runtimeOperation = resolveRuntimeApiOperation($bot);
    $executor = app(RuntimeApiOperationExecutor::class);

    expect($runtimeOperation)->toBeInstanceOf(RuntimeApiOperation::class);

    $missing = $executor->execute($runtimeOperation, []);
    $unknown = $executor->execute($runtimeOperation, ['sku' => 'A', 'headers' => []]);
    $wrongType = $executor->execute($runtimeOperation, ['sku' => 10]);

    expect($missing->success)->toBeFalse()
        ->and($missing->error)->toBe('invalid_request')
        ->and($unknown->error)->toBe('invalid_request')
        ->and($wrongType->error)->toBe('invalid_request');
});

test('builds a mapped GET request with server-side credentials and safe output', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => Http::response([
            'data' => [
                'inventory' => [
                    'available' => true,
                    'quantity' => 7,
                    'internal_note' => 'do not expose',
                ],
            ],
        ], 200, ['Content-Type' => 'application/json']),
    ]);
    [, $bot, $dataSource] = runtimeApiOperationContext([
        'request_mapping' => [
            'path' => ['sku' => 'sku'],
            'query' => ['store_id' => 'store'],
        ],
    ]);
    DataSourceCredential::factory()->create([
        'data_source_id' => $dataSource->id,
        'key' => 'bearer_token',
        'encrypted_value' => 'top-secret-token',
    ]);

    $runtimeOperation = resolveRuntimeApiOperation($bot);
    $result = app(RuntimeApiOperationExecutor::class)->execute($runtimeOperation, [
        'sku' => 'A/1',
        'store_id' => 'main',
    ]);

    expect($result->success)->toBeTrue()
        ->and($result->data)->toEqualCanonicalizing([
            'available' => true,
            'quantity' => 7,
        ])
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('top-secret-token');

    Http::assertSent(function ($request): bool {
        return $request->method() === 'GET'
            && str_contains($request->url(), '/products/A%2F1/stock')
            && str_contains($request->url(), 'store=main')
            && $request->header('Authorization') === ['Bearer top-secret-token'];
    });
});

test('maps configured request body fields without allowing endpoint control', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => Http::response([
            'data' => ['inventory' => ['available' => false, 'quantity' => 0]],
        ], 200, ['Content-Type' => 'application/json']),
    ]);
    [, $bot] = runtimeApiOperationContext([
        'method' => 'POST',
        'path' => '/stock/check',
        'request_schema' => [
            'type' => 'object',
            'properties' => ['postal_code' => ['type' => 'string']],
            'required' => ['postal_code'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'body' => ['postal_code' => 'address.postal_code'],
        ],
    ]);

    $runtimeOperation = resolveRuntimeApiOperation($bot);
    $result = app(RuntimeApiOperationExecutor::class)->execute($runtimeOperation, [
        'postal_code' => '0105',
    ]);

    expect($result->success)->toBeTrue();

    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.example.test/stock/check'
            && $request->data() === ['address' => ['postal_code' => '0105']];
    });
});

test('normalizes upstream failures and connection failures without exposing secrets', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => Http::response([
            'error' => 'upstream secret response',
        ], 503),
    ]);
    [, $bot, $dataSource] = runtimeApiOperationContext();
    DataSourceCredential::factory()->create([
        'data_source_id' => $dataSource->id,
        'key' => 'bearer_token',
        'encrypted_value' => 'top-secret-token',
    ]);

    $failure = app(RuntimeApiOperationExecutor::class)->execute(
        resolveRuntimeApiOperation($bot),
        ['sku' => 'A'],
    );

    expect($failure->success)->toBeFalse()
        ->and($failure->error)->toBe('integration_error')
        ->and(json_encode($failure, JSON_THROW_ON_ERROR))
        ->not->toContain('top-secret-token')
        ->not->toContain('upstream secret response');

    Http::fake([
        'https://api.example.test/*' => Http::failedConnection(),
    ]);

    $unavailable = app(RuntimeApiOperationExecutor::class)->execute(
        resolveRuntimeApiOperation($bot),
        ['sku' => 'A'],
    );

    expect($unavailable->success)->toBeFalse()
        ->and($unavailable->error)->toBe('unavailable');
});

test('rejects missing required mapped response values and excludes unmapped fields', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => Http::response([
            'data' => ['inventory' => ['available' => true]],
        ], 200, ['Content-Type' => 'application/json']),
    ]);
    [, $bot] = runtimeApiOperationContext();

    $result = app(RuntimeApiOperationExecutor::class)->execute(
        resolveRuntimeApiOperation($bot),
        ['sku' => 'A'],
    );

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('integration_error');
});
