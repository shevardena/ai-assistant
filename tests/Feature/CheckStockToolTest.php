<?php

use App\Enums\ApiOperationMode;
use App\Enums\DatasetStatus;
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
use App\Services\Ai\Tools\CheckStockTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: Bot, 1: Dataset, 2: DatasetField, 3: DatasetRecord, 4: ApiOperation, 5: BotApiOperation}
 */
function checkStockContext(array $operationOverrides = [], array $attachmentSettings = []): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
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
        'key' => 'stock_lookup',
        'name' => 'Stock lookup',
        'execution_mode' => ApiOperationMode::Read->value,
        'method' => 'GET',
        'path' => '/products/{sku}/stock',
        'request_schema' => [
            'type' => 'object',
            'properties' => ['sku' => ['type' => 'string']],
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
        'settings' => $attachmentSettings ?: [
            'input_mapping' => [
                'product_reference' => [
                    'dataset_field' => 'sku',
                    'operation_argument' => 'sku',
                ],
            ],
        ],
    ]);

    return [$bot, $dataset, $skuField, $record, $operation, $attachment];
}

function executeStockCheck(Bot $bot, array $arguments): ToolResult
{
    return app(CheckStockTool::class)->execute(
        $bot,
        $arguments,
        ToolExecutionContext::forBot($bot),
    );
}

function stockJsonResponse(array $payload, int $status = 200): PromiseInterface
{
    return Http::response($payload, $status, ['Content-Type' => 'application/json']);
}

test('the registry exposes check_stock only for a configured catalog integration', function () {
    [$bot, , , , $operation, $attachment] = checkStockContext();
    $registry = app(BotToolRegistry::class);

    expect($registry->find($bot, 'check_stock'))->toBeInstanceOf(CheckStockTool::class);

    $attachment->update(['is_enabled' => false]);
    expect($registry->find($bot, 'check_stock'))->toBeNull();

    $attachment->update(['is_enabled' => true]);
    $operation->update(['execution_mode' => ApiOperationMode::Write->value]);
    expect($registry->find($bot, 'check_stock'))->toBeNull();

    $operation->update(['execution_mode' => ApiOperationMode::Read->value]);
    $attachment->delete();
    expect($registry->find($bot, 'check_stock'))->toBeNull();
});

test('check_stock has a strict product reference schema', function () {
    [$bot] = checkStockContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'check_stock');
    $schema = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($schema)->toMatchArray([
        'type' => 'function',
        'name' => 'check_stock',
        'strict' => true,
    ])
        ->and($schema['parameters']['properties'])->toBe([
            'product_reference' => ['type' => 'string'],
        ])
        ->and($schema['parameters']['required'])->toBe(['product_reference'])
        ->and($schema['parameters']['additionalProperties'])->toBeFalse();
});

test('checks live stock using the authorized record field rather than arbitrary model input', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => stockJsonResponse([
            'data' => ['inventory' => ['available' => true, 'quantity' => 7]],
        ]),
    ]);
    [$bot, , , , , $attachment] = checkStockContext();
    DataSourceCredential::factory()->create([
        'data_source_id' => $attachment->apiOperation->dataSource->id,
        'key' => 'bearer_token',
        'encrypted_value' => 'top-secret-token',
    ]);

    $result = executeStockCheck($bot, ['product_reference' => 'product-1']);

    expect($result->data)->toEqualCanonicalizing([
        'ok' => true,
        'stock' => ['available' => true, 'quantity' => 7],
    ])
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('top-secret-token');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.example.test/products/ABC-991/stock'
            && $request->header('Authorization') === ['Bearer top-secret-token'];
    });
});

test('rejects foreign and unattached product references without making an HTTP request', function () {
    Http::preventStrayRequests();
    [$bot, $dataset] = checkStockContext();
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
    $unattachedDataset = Dataset::factory()->ready()->create([
        'team_id' => $dataset->team_id,
        'entity_type' => 'product',
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $unattachedDataset->id,
        'key' => 'sku',
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $unattachedDataset->id,
        'external_id' => 'unattached-product',
        'payload' => ['sku' => 'UNATTACHED-1'],
    ]);

    expect(executeStockCheck($bot, ['product_reference' => 'foreign-product'])->data)
        ->toMatchArray(['ok' => false, 'error' => 'not_found'])
        ->and(executeStockCheck($bot, ['product_reference' => 'unattached-product'])->data)
        ->toMatchArray(['ok' => false, 'error' => 'not_found']);

    Http::assertNothingSent();
});

test('rejects disabled non-ready and inactive products safely', function () {
    Http::preventStrayRequests();
    [$bot, $dataset, , $record] = checkStockContext();
    $attachment = BotDataset::query()
        ->where('bot_id', $bot->id)
        ->where('dataset_id', $dataset->id)
        ->firstOrFail();

    $attachment->update(['is_enabled' => false]);
    expect(executeStockCheck($bot, ['product_reference' => 'product-1'])->data)
        ->toMatchArray(['ok' => false, 'error' => 'not_found']);

    $attachment->update(['is_enabled' => true]);
    $dataset->update(['status' => DatasetStatus::Preparing->value]);
    expect(executeStockCheck($bot, ['product_reference' => 'product-1'])->data)
        ->toMatchArray(['ok' => false, 'error' => 'not_found']);

    $dataset->update(['status' => DatasetStatus::Ready->value]);
    $record->update(['is_active' => false]);
    expect(executeStockCheck($bot, ['product_reference' => 'product-1'])->data)
        ->toMatchArray(['ok' => false, 'error' => 'not_found']);

    Http::assertNothingSent();
});

test('returns missing_product_data when the configured DatasetField is absent', function () {
    Http::preventStrayRequests();
    [$bot, , , $record] = checkStockContext();
    $record->update(['payload' => ['name' => 'Gaming Laptop']]);

    expect(executeStockCheck($bot, ['product_reference' => 'product-1'])->data)
        ->toMatchArray([
            'ok' => false,
            'error' => 'missing_product_data',
        ]);

    Http::assertNothingSent();
});

test('rejects malformed or unknown product references safely', function () {
    Http::preventStrayRequests();
    [$bot] = checkStockContext();

    foreach ([
        [],
        ['product_reference' => ''],
        ['product_reference' => "\x01bad"],
        ['product_reference' => 'product-1', 'sku' => 'ABC-991'],
        ['product_reference' => 'unknown-product'],
    ] as $arguments) {
        expect(executeStockCheck($bot, $arguments)->data)
            ->toMatchArray(['ok' => false, 'error' => 'not_found']);
    }

    Http::assertNothingSent();
});

test('does not use a foreign or write operation', function () {
    Http::preventStrayRequests();
    [$bot, , , , $operation, $attachment] = checkStockContext();
    $foreignTeam = Team::factory()->create();
    $foreignDataSource = DataSource::factory()->ready()->create([
        'team_id' => $foreignTeam->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://foreign.example.test'],
    ]);
    $foreignOperation = ApiOperation::factory()->create([
        'data_source_id' => $foreignDataSource->id,
        'execution_mode' => ApiOperationMode::Read->value,
    ]);

    $attachment->update(['api_operation_id' => $foreignOperation->id]);
    expect(executeStockCheck($bot, ['product_reference' => 'product-1'])->data)
        ->toMatchArray(['ok' => false, 'error' => 'integration_unavailable']);

    $attachment->update(['api_operation_id' => $operation->id]);
    $operation->update(['execution_mode' => ApiOperationMode::Write->value]);
    expect(executeStockCheck($bot, ['product_reference' => 'product-1'])->data)
        ->toMatchArray(['ok' => false, 'error' => 'integration_unavailable']);

    Http::assertNothingSent();
});

test('normalizes timeout and upstream errors without leaking credentials', function () {
    Http::preventStrayRequests();
    [$bot, , , , , $attachment] = checkStockContext();
    DataSourceCredential::factory()->create([
        'data_source_id' => $attachment->apiOperation->dataSource->id,
        'key' => 'bearer_token',
        'encrypted_value' => 'top-secret-token',
    ]);
    $responseMode = 'connection_failure';

    Http::fake([
        'https://api.example.test/*' => function ($request, array $options) use (&$responseMode): PromiseInterface {
            if ($responseMode === 'connection_failure') {
                return (Http::failedConnection())($request, $options);
            }

            return stockJsonResponse(['error' => 'private upstream body'], 503);
        },
    ]);

    $unavailable = executeStockCheck($bot, ['product_reference' => 'product-1']);

    expect($unavailable->data['error'])->toBe('unavailable')
        ->and(json_encode($unavailable->data, JSON_THROW_ON_ERROR))->not->toContain('top-secret-token');

    $responseMode = 'upstream_error';
    $failure = executeStockCheck($bot, ['product_reference' => 'product-1']);

    expect($failure->data['error'])->toBe('integration_error')
        ->and(json_encode($failure->data, JSON_THROW_ON_ERROR))->not->toContain('private upstream body');
});

test('generic orchestrator dispatches check_stock without a manual tool branch', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => stockJsonResponse([
            'data' => ['inventory' => ['available' => true, 'quantity' => 3]],
        ]),
    ]);
    [$bot] = checkStockContext();
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
                        'call_id' => 'stock-call',
                        'name' => 'check_stock',
                        'arguments' => json_encode(['product_reference' => 'product-1'], JSON_THROW_ON_ERROR),
                    ]],
                    'output_text' => null,
                    'usage' => null,
                ]
                : [
                    'output' => [],
                    'output_text' => 'The product is in stock.',
                    'usage' => null,
                ];
        }
    };
    app()->instance(AiClient::class, $fake);

    $response = app(AiSearchOrchestrator::class)->run($bot, 'Is this in stock?');

    expect($response->answer)->toBe('The product is in stock.')
        ->and($response->toolCallsCount)->toBe(1);

    $functionOutput = collect($fake->payloads[1]['input'])
        ->first(fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'
            && ($item['call_id'] ?? null) === 'stock-call');

    expect($functionOutput)->not->toBeNull()
        ->and(json_decode($functionOutput['output'], true, 512, JSON_THROW_ON_ERROR))->toEqualCanonicalizing([
            'ok' => true,
            'stock' => ['available' => true, 'quantity' => 3],
        ]);
});
