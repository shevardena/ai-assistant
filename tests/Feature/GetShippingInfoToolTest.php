<?php

use App\Enums\ApiOperationMode;
use App\Enums\DatasetStatus;
use App\Enums\TeamRole;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\BotDataset;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\DataSource;
use App\Models\DataSourceCredential;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiSearchOrchestrator;
use App\Services\Ai\AiToolSchemaBuilder;
use App\Services\Ai\BotToolRegistry;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\Tools\GetShippingInfoTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: Bot, 1: Dataset, 2: DatasetField, 3: DatasetRecord, 4: ApiOperation, 5: BotApiOperation}
 */
function shippingInfoContext(array $operationOverrides = [], array $attachmentSettings = []): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $dataSource = DataSource::factory()->ready()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
    ]);
    $dataset = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'entity_type' => 'product',
    ]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'is_enabled' => true,
    ]);
    $nameField = DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'name',
        'is_displayable' => true,
        'position' => 1,
    ]);
    $skuField = DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'sku',
        'is_displayable' => false,
        'position' => 2,
    ]);
    $record = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'product-1',
        'payload' => [
            'name' => 'Gaming Laptop',
            'sku' => 'ABC-991',
            'internal_note' => 'private',
        ],
        'is_active' => true,
    ]);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $dataSource->id,
        'key' => 'shipping_quote',
        'name' => 'Shipping quote',
        'execution_mode' => ApiOperationMode::Read->value,
        'method' => 'GET',
        'path' => '/products/{sku}/shipping',
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string'],
                'postal_code' => ['type' => 'string'],
                'country' => ['type' => 'string'],
                'quantity' => ['type' => 'integer'],
            ],
            'required' => ['sku', 'postal_code'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'path' => ['sku' => 'sku'],
            'query' => [
                'postal_code' => 'postal_code',
                'country' => 'country',
                'quantity' => 'quantity',
            ],
        ],
        'response_mapping' => [
            'output' => [
                'options' => 'data.options',
                'estimated_days' => 'data.estimated_days',
                'price' => 'data.price',
            ],
        ],
        ...$operationOverrides,
    ]);
    $attachment = BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'get_shipping_info',
        'is_enabled' => true,
        'settings' => $attachmentSettings ?: [
            'input_mapping' => [
                'product_reference' => [
                    'source' => 'dataset_field',
                    'dataset_field' => 'sku',
                    'operation_argument' => 'sku',
                ],
                'postal_code' => [
                    'source' => 'model_input',
                    'model_input' => 'postal_code',
                    'operation_argument' => 'postal_code',
                ],
                'country' => [
                    'source' => 'model_input',
                    'model_input' => 'country',
                    'operation_argument' => 'country',
                ],
                'quantity' => [
                    'source' => 'model_input',
                    'model_input' => 'quantity',
                    'operation_argument' => 'quantity',
                ],
            ],
        ],
    ]);

    return [$bot, $dataset, $skuField, $record, $operation, $attachment];
}

function executeShippingInfo(Bot $bot, array $arguments): ToolResult
{
    return app(GetShippingInfoTool::class)->execute(
        $bot,
        $arguments,
        ToolExecutionContext::forBot($bot),
    );
}

function shippingJsonResponse(array $payload, int $status = 200): PromiseInterface
{
    return Http::response($payload, $status, ['Content-Type' => 'application/json']);
}

test('the registry exposes get_shipping_info only for a complete read integration', function () {
    [$bot, , , , $operation, $attachment] = shippingInfoContext();
    $registry = app(BotToolRegistry::class);

    expect($registry->find($bot, 'get_shipping_info'))->toBeInstanceOf(GetShippingInfoTool::class);

    $attachment->update(['is_enabled' => false]);
    expect($registry->find($bot, 'get_shipping_info'))->toBeNull();

    $attachment->update(['is_enabled' => true]);
    $operation->update(['execution_mode' => ApiOperationMode::Write->value]);
    expect($registry->find($bot, 'get_shipping_info'))->toBeNull();

    $operation->update(['execution_mode' => ApiOperationMode::Read->value]);
    $attachment->update(['settings' => []]);
    expect($registry->find($bot, 'get_shipping_info'))->toBeNull();
});

test('get_shipping_info exposes a strict bounded shipping schema', function () {
    [$bot] = shippingInfoContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'get_shipping_info');
    $schema = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($schema)->toMatchArray([
        'type' => 'function',
        'name' => 'get_shipping_info',
        'strict' => true,
    ])
        ->and($schema['parameters']['properties'])->toBe([
            'product_reference' => ['type' => 'string'],
            'postal_code' => ['type' => 'string'],
            'country' => ['type' => 'string'],
            'quantity' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 100,
            ],
        ])
        ->and($schema['parameters']['required'])->toBe(['product_reference'])
        ->and($schema['parameters']['additionalProperties'])->toBeFalse();
});

test('it resolves the dataset SKU and maps destination inputs without exposing credentials', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => shippingJsonResponse([
            'data' => [
                'options' => ['standard', 'express'],
                'estimated_days' => 3,
                'price' => 20.0,
            ],
        ]),
    ]);
    [$bot, , , , $operation] = shippingInfoContext();
    DataSourceCredential::factory()->create([
        'data_source_id' => $operation->dataSource->id,
        'key' => 'bearer_token',
        'encrypted_value' => 'top-secret-token',
    ]);

    $result = executeShippingInfo($bot, [
        'product_reference' => 'product-1',
        'postal_code' => '10001',
        'country' => 'US',
        'quantity' => 2,
    ]);

    expect($result->data)->toMatchArray([
        'ok' => true,
        'shipping' => [
            'options' => ['standard', 'express'],
            'estimated_days' => 3,
            'price' => 20,
        ],
    ])
        ->and((float) $result->data['shipping']['price'])->toBe(20.0)
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('top-secret-token');

    Http::assertSent(function ($request): bool {
        $url = parse_url($request->url());
        $query = [];

        parse_str((string) ($url['query'] ?? ''), $query);
        ksort($query);

        $matches = ($url['scheme'] ?? null) === 'https'
            && ($url['host'] ?? null) === 'api.example.test'
            && ($url['path'] ?? null) === '/products/ABC-991/shipping'
            && $query === [
                'country' => 'US',
                'postal_code' => '10001',
                'quantity' => '2',
            ]
            && $request->header('Authorization') === ['Bearer top-secret-token'];

        return $matches;
    });
});

test('it never accepts a model supplied SKU or arbitrary operation argument', function () {
    Http::preventStrayRequests();
    [$bot] = shippingInfoContext();

    $result = executeShippingInfo($bot, [
        'product_reference' => 'product-1',
        'postal_code' => '10001',
        'sku' => 'ATTACKER-SKU',
    ]);

    expect($result->data)->toMatchArray([
        'ok' => false,
        'error' => 'invalid_request',
    ]);

    Http::assertNothingSent();
});

test('missing required destination input fails before the HTTP executor', function () {
    Http::preventStrayRequests();
    [$bot] = shippingInfoContext();

    $result = executeShippingInfo($bot, ['product_reference' => 'product-1']);

    expect($result->data)->toMatchArray([
        'ok' => false,
        'error' => 'invalid_request',
    ]);

    Http::assertNothingSent();
});

test('optional destination inputs are sent only when provided', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => shippingJsonResponse([
            'data' => [
                'options' => ['standard'],
                'estimated_days' => 5,
                'price' => 12,
            ],
        ]),
    ]);
    [$bot] = shippingInfoContext();

    $result = executeShippingInfo($bot, [
        'product_reference' => 'product-1',
        'postal_code' => '10001',
    ]);

    expect($result->data['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.example.test/products/ABC-991/shipping?postal_code=10001';
    });
});

test('foreign, unattached, disabled, non-ready, and inactive products are not callable', function () {
    Http::preventStrayRequests();
    [$bot, $dataset, , $record] = shippingInfoContext();
    $foreignTeam = Team::factory()->create();
    $foreignDataset = Dataset::factory()->ready()->create([
        'team_id' => $foreignTeam->id,
        'entity_type' => 'product',
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $foreignDataset->id,
        'key' => 'sku',
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $foreignDataset->id,
        'external_id' => 'foreign-product',
        'payload' => ['sku' => 'FOREIGN-1'],
    ]);

    expect(executeShippingInfo($bot, [
        'product_reference' => 'foreign-product',
        'postal_code' => '10001',
    ])->data)->toMatchArray(['ok' => false, 'error' => 'not_found']);

    BotDataset::query()->where('bot_id', $bot->id)->where('dataset_id', $dataset->id)->update(['is_enabled' => false]);
    expect(executeShippingInfo($bot, [
        'product_reference' => 'product-1',
        'postal_code' => '10001',
    ])->data)->toMatchArray(['ok' => false, 'error' => 'not_found']);

    BotDataset::query()->where('bot_id', $bot->id)->where('dataset_id', $dataset->id)->update(['is_enabled' => true]);
    $dataset->update(['status' => DatasetStatus::Preparing->value]);
    expect(executeShippingInfo($bot, [
        'product_reference' => 'product-1',
        'postal_code' => '10001',
    ])->data)->toMatchArray(['ok' => false, 'error' => 'not_found']);

    $dataset->update(['status' => DatasetStatus::Ready->value]);
    $record->update(['is_active' => false]);
    expect(executeShippingInfo($bot, [
        'product_reference' => 'product-1',
        'postal_code' => '10001',
    ])->data)->toMatchArray(['ok' => false, 'error' => 'not_found']);

    Http::assertNothingSent();
});

test('missing configured product data returns a safe result', function () {
    Http::preventStrayRequests();
    [$bot, , , $record] = shippingInfoContext();
    $record->update(['payload' => ['name' => 'Gaming Laptop']]);

    expect(executeShippingInfo($bot, [
        'product_reference' => 'product-1',
        'postal_code' => '10001',
    ])->data)->toMatchArray([
        'ok' => false,
        'error' => 'missing_product_data',
    ]);

    Http::assertNothingSent();
});

test('disabled or write integrations and upstream failures stay unavailable and secret-free', function () {
    Http::preventStrayRequests();
    [$bot, , , , $operation, $attachment] = shippingInfoContext();
    DataSourceCredential::factory()->create([
        'data_source_id' => $operation->dataSource->id,
        'key' => 'bearer_token',
        'encrypted_value' => 'top-secret-token',
    ]);

    $operation->update(['execution_mode' => ApiOperationMode::Write->value]);
    expect(executeShippingInfo($bot, [
        'product_reference' => 'product-1',
        'postal_code' => '10001',
    ])->data)->toMatchArray(['ok' => false, 'error' => 'integration_unavailable']);

    $operation->update(['execution_mode' => ApiOperationMode::Read->value]);
    $attachment->update(['is_enabled' => true]);
    $responseMode = 'timeout';

    Http::fake([
        'https://api.example.test/*' => function () use (&$responseMode): PromiseInterface {
            if ($responseMode === 'timeout') {
                throw new ConnectionException('request timed out');
            }

            return shippingJsonResponse(['error' => 'private upstream body'], 503);
        },
    ]);

    $timeout = executeShippingInfo($bot, [
        'product_reference' => 'product-1',
        'postal_code' => '10001',
    ]);

    expect($timeout->data['error'])->toBe('timeout')
        ->and(json_encode($timeout->data, JSON_THROW_ON_ERROR))->not->toContain('top-secret-token');

    $responseMode = 'upstream_error';

    $result = executeShippingInfo($bot, [
        'product_reference' => 'product-1',
        'postal_code' => '10001',
    ]);

    expect($result->data['error'])->toBe('integration_error')
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('private upstream body')
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('top-secret-token');
});

test('generic orchestrator dispatches get_shipping_info without a manual tool branch', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => shippingJsonResponse([
            'data' => [
                'options' => ['standard'],
                'estimated_days' => 4,
                'price' => 15,
            ],
        ]),
    ]);
    [$bot] = shippingInfoContext();
    $fake = new class implements AiClient
    {
        /** @var list<array<string, mixed>> */
        public array $payloads = [];

        public function createResponse(array $payload): array
        {
            $this->payloads[] = $payload;

            return count($this->payloads) === 1
                ? [
                    'output' => [[
                        'type' => 'function_call',
                        'call_id' => 'shipping-call',
                        'name' => 'get_shipping_info',
                        'arguments' => json_encode([
                            'product_reference' => 'product-1',
                            'postal_code' => '10001',
                        ], JSON_THROW_ON_ERROR),
                    ]],
                    'output_text' => null,
                    'usage' => null,
                ]
                : [
                    'output' => [],
                    'output_text' => 'Standard shipping arrives in four days.',
                    'usage' => null,
                ];
        }
    };
    app()->instance(AiClient::class, $fake);

    $response = app(AiSearchOrchestrator::class)->run($bot, 'How much is shipping?');

    expect($response->answer)->toBe('Standard shipping arrives in four days.')
        ->and($response->toolCallsCount)->toBe(1);

    $functionOutput = collect($fake->payloads[1]['input'])
        ->first(fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'
            && ($item['call_id'] ?? null) === 'shipping-call');

    expect($functionOutput)->not->toBeNull()
        ->and(json_decode($functionOutput['output'], true, 512, JSON_THROW_ON_ERROR))->toEqualCanonicalizing([
            'ok' => true,
            'shipping' => [
                'options' => ['standard'],
                'estimated_days' => 4,
                'price' => 15,
            ],
        ]);
});
