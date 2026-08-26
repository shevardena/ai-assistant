<?php

use App\Enums\ApiOperationMode;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\Conversation;
use App\Models\DataSource;
use App\Models\DataSourceCredential;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiSearchOrchestrator;
use App\Services\Ai\AiToolSchemaBuilder;
use App\Services\Ai\BotToolRegistry;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\Tools\CheckOrderStatusTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: Bot, 1: ApiOperation, 2: BotApiOperation}
 */
function checkOrderStatusContext(
    array $operationOverrides = [],
    array $attachmentSettings = [],
): array {
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
        'key' => 'order_status',
        'name' => 'Order status',
        'execution_mode' => ApiOperationMode::Read->value,
        'method' => 'GET',
        'path' => '/orders/status',
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'order_number' => ['type' => 'string'],
                'customer_email' => ['type' => 'string'],
            ],
            'required' => ['order_number', 'customer_email'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'query' => [
                'order_number' => 'order_number',
                'customer_email' => 'customer_email',
            ],
        ],
        'response_mapping' => [
            'output' => [
                'status' => 'data.status',
                'updated_at' => 'data.updated_at',
            ],
        ],
        ...$operationOverrides,
    ]);
    $attachment = BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'check_order_status',
        'is_enabled' => true,
        'settings' => $attachmentSettings ?: [
            'input_mapping' => [
                'order_reference' => [
                    'source' => 'model_input',
                    'model_input' => 'order_reference',
                    'operation_argument' => 'order_number',
                ],
                'email' => [
                    'source' => 'model_input',
                    'model_input' => 'email',
                    'operation_argument' => 'customer_email',
                ],
            ],
        ],
    ]);

    return [$bot, $operation, $attachment];
}

function executeOrderStatus(Bot $bot, array $arguments, ?ToolExecutionContext $context = null): ToolResult
{
    return app(CheckOrderStatusTool::class)->execute(
        $bot,
        $arguments,
        $context ?? ToolExecutionContext::forBot($bot),
    );
}

function orderStatusJsonResponse(array $payload, int $status = 200): PromiseInterface
{
    return Http::response($payload, $status, ['Content-Type' => 'application/json']);
}

test('the registry exposes order status without requiring a catalog dataset', function () {
    [$bot, $operation, $attachment] = checkOrderStatusContext();
    $registry = app(BotToolRegistry::class);

    expect($bot->datasets)->toHaveCount(0)
        ->and($registry->find($bot, 'check_order_status'))->toBeInstanceOf(CheckOrderStatusTool::class);

    $attachment->update(['is_enabled' => false]);
    expect($registry->find($bot, 'check_order_status'))->toBeNull();

    $attachment->update(['is_enabled' => true]);
    $operation->update(['execution_mode' => ApiOperationMode::Write->value]);
    expect($registry->find($bot, 'check_order_status'))->toBeNull();
});

test('a bot without an order status attachment does not expose the tool', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->create(['team_id' => $user->currentTeam->id]);

    expect(app(BotToolRegistry::class)->find($bot, 'check_order_status'))->toBeNull();
});

test('check_order_status has a strict safe input schema', function () {
    [$bot] = checkOrderStatusContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'check_order_status');
    $schema = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($schema)->toMatchArray([
        'type' => 'function',
        'name' => 'check_order_status',
        'strict' => true,
    ])
        ->and($schema['parameters']['properties'])->toBe([
            'order_reference' => ['type' => 'string'],
            'email' => ['type' => 'string'],
            'postal_code' => ['type' => 'string'],
            'phone' => ['type' => 'string'],
        ])
        ->and($schema['parameters']['required'])->toBe(['order_reference'])
        ->and($schema['parameters']['additionalProperties'])->toBeFalse();
});

test('it maps the order reference and verification input through the configured operation', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => orderStatusJsonResponse([
            'data' => [
                'status' => 'processing',
                'updated_at' => '2026-08-21T12:30:00Z',
                'customer_email' => 'private@example.test',
                'internal_order_id' => 'internal-123',
            ],
        ]),
    ]);
    [$bot, $operation] = checkOrderStatusContext();
    DataSourceCredential::factory()->create([
        'data_source_id' => $operation->dataSource->id,
        'key' => 'bearer_token',
        'encrypted_value' => 'top-secret-token',
    ]);

    $result = executeOrderStatus($bot, [
        'order_reference' => 'AB123',
        'email' => 'customer@example.test',
    ]);

    expect($result->data)->toBe([
        'ok' => true,
        'order_status' => [
            'status' => 'processing',
            'updated_at' => '2026-08-21T12:30:00Z',
        ],
    ])
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('top-secret-token')
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('customer_email')
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('internal_order_id')
        ->and($result->blocks)->toBe([[
            'type' => 'order_status',
            'data' => [
                'status' => 'processing',
                'fields' => [[
                    'key' => 'updated_at',
                    'label' => 'Updated',
                    'value' => '2026-08-21T12:30:00Z',
                ]],
            ],
        ]])
        ->and($result->blocks[0]['data']['fields'][0])->not->toHaveKey('internal_order_id');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.example.test/orders/status?order_number=AB123&customer_email=customer%40example.test'
            && $request->header('Authorization') === ['Bearer top-secret-token'];
    });
});

test('order status blocks prioritize delivery fields and keep generic mapped scalars', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => orderStatusJsonResponse([
            'data' => [
                'status' => 'awaiting_fulfillment',
                'estimated_delivery' => '2026-08-25',
                'updated_at' => '2026-08-22T10:30:00Z',
                'payment_status' => 'paid',
                'unmapped_nested' => ['secret' => 'discarded'],
            ],
        ]),
    ]);
    [$bot] = checkOrderStatusContext([
        'response_mapping' => [
            'output' => [
                'status' => 'data.status',
                'estimated_delivery' => 'data.estimated_delivery',
                'updated_at' => 'data.updated_at',
                'payment_status' => 'data.payment_status',
            ],
        ],
    ]);

    $result = executeOrderStatus($bot, [
        'order_reference' => 'AB123',
        'email' => 'customer@example.test',
    ]);

    expect($result->blocks[0])->toBe([
        'type' => 'order_status',
        'data' => [
            'status' => 'awaiting_fulfillment',
            'fields' => [
                [
                    'key' => 'estimated_delivery',
                    'label' => 'Estimated delivery',
                    'value' => '2026-08-25',
                ],
                [
                    'key' => 'updated_at',
                    'label' => 'Updated',
                    'value' => '2026-08-22T10:30:00Z',
                ],
                [
                    'key' => 'payment_status',
                    'label' => 'Payment status',
                    'value' => 'paid',
                ],
            ],
        ],
    ])
        ->and(json_encode($result->blocks, JSON_THROW_ON_ERROR))->not->toContain('unmapped_nested');
});

test('operation schema determines whether verification is required', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => orderStatusJsonResponse([
            'data' => ['status' => 'processing'],
        ]),
    ]);
    [$bot] = checkOrderStatusContext([
        'request_schema' => [
            'type' => 'object',
            'properties' => ['order_number' => ['type' => 'string']],
            'required' => ['order_number'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'query' => ['order_number' => 'order_number'],
        ],
        'response_mapping' => [
            'output' => ['status' => 'data.status'],
        ],
    ], [
        'input_mapping' => [
            'order_reference' => [
                'source' => 'model_input',
                'model_input' => 'order_reference',
                'operation_argument' => 'order_number',
            ],
        ],
    ]);

    $result = executeOrderStatus($bot, ['order_reference' => 'AB123']);

    expect($result->data)->toBe([
        'ok' => true,
        'order_status' => ['status' => 'processing'],
    ]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.example.test/orders/status?order_number=AB123';
    });
});

test('missing configured verification input returns invalid_request without HTTP', function () {
    Http::preventStrayRequests();
    [$bot] = checkOrderStatusContext();

    $result = executeOrderStatus($bot, ['order_reference' => 'AB123']);

    expect($result->data)->toMatchArray([
        'ok' => false,
        'error' => 'invalid_request',
    ]);

    Http::assertNothingSent();
});

test('missing configured verification input returns a trusted form in a conversation', function () {
    Http::preventStrayRequests();
    [$bot] = checkOrderStatusContext();
    $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

    $result = executeOrderStatus(
        $bot,
        ['order_reference' => 'AB123'],
        ToolExecutionContext::forBot($bot, $conversation),
    );

    expect($result->data)->toMatchArray([
        'ok' => false,
        'error' => 'missing_input',
    ])
        ->and($result->blocks[0]['type'])->toBe('form')
        ->and($result->blocks[0]['data']['fields'][0]['name'])->toBe('email')
        ->and($conversation->state()->firstOrFail()->memory['active_form']['conversation_id'])
        ->toBe($conversation->id);

    Http::assertNothingSent();
});

test('invalid or unconfigured model inputs are rejected without HTTP', function () {
    Http::preventStrayRequests();
    [$bot] = checkOrderStatusContext();

    foreach ([
        [],
        ['order_reference' => ''],
        ['order_reference' => 'AB123', 'email' => ''],
        ['order_reference' => 'AB123', 'customer_id' => 'internal-123'],
    ] as $arguments) {
        expect(executeOrderStatus($bot, $arguments)->data)
            ->toMatchArray(['ok' => false, 'error' => 'invalid_request']);
    }

    Http::assertNothingSent();
});

test('foreign, disabled, write, and non-ready operations are unavailable', function () {
    Http::preventStrayRequests();
    [$bot, $operation, $attachment] = checkOrderStatusContext();
    $foreignTeam = Team::factory()->create();
    $foreignSource = DataSource::factory()->ready()->create([
        'team_id' => $foreignTeam->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://foreign.example.test'],
    ]);
    $foreignOperation = ApiOperation::factory()->create([
        'data_source_id' => $foreignSource->id,
        'execution_mode' => ApiOperationMode::Read->value,
    ]);

    $attachment->update(['api_operation_id' => $foreignOperation->id]);
    expect(app(BotToolRegistry::class)->find($bot, 'check_order_status'))->toBeNull()
        ->and(executeOrderStatus($bot, [
            'order_reference' => 'AB123',
            'email' => 'customer@example.test',
        ])->data)->toMatchArray(['ok' => false, 'error' => 'integration_unavailable']);

    $attachment->update(['api_operation_id' => $operation->id, 'is_enabled' => false]);
    expect(executeOrderStatus($bot, [
        'order_reference' => 'AB123',
        'email' => 'customer@example.test',
    ])->data)->toMatchArray(['ok' => false, 'error' => 'integration_unavailable']);

    $attachment->update(['is_enabled' => true]);
    $operation->update(['execution_mode' => ApiOperationMode::Write->value]);
    expect(executeOrderStatus($bot, [
        'order_reference' => 'AB123',
        'email' => 'customer@example.test',
    ])->data)->toMatchArray(['ok' => false, 'error' => 'integration_unavailable']);

    $operation->update(['execution_mode' => ApiOperationMode::Read->value]);
    $operation->dataSource->update(['status' => 'preparing']);
    expect(executeOrderStatus($bot, [
        'order_reference' => 'AB123',
        'email' => 'customer@example.test',
    ])->data)->toMatchArray(['ok' => false, 'error' => 'integration_unavailable']);

    Http::assertNothingSent();
});

test('privacy-safe upstream failures do not expose order or customer data', function () {
    Http::preventStrayRequests();
    [$bot] = checkOrderStatusContext();
    Http::fake([
        'https://api.example.test/*' => orderStatusJsonResponse([
            'error' => 'order exists but customer_email is incorrect',
            'customer_email' => 'private@example.test',
            'internal_order_id' => 'internal-123',
        ], 404),
    ]);

    $result = executeOrderStatus($bot, [
        'order_reference' => 'AB123',
        'email' => 'wrong@example.test',
    ]);

    expect($result->data)->toMatchArray([
        'ok' => false,
        'error' => 'order_not_available',
    ])
        ->and($result->blocks)->toBe([])
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('order exists')
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('private@example.test')
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('internal-123');
});

test('timeouts and unavailable integrations are normalized safely', function () {
    Http::preventStrayRequests();
    [$bot] = checkOrderStatusContext();
    $connectionFailure = 'request timed out';
    Http::fake([
        'https://api.example.test/*' => function () use (&$connectionFailure): never {
            throw new ConnectionException($connectionFailure);
        },
    ]);

    $timeout = executeOrderStatus($bot, [
        'order_reference' => 'AB123',
        'email' => 'customer@example.test',
    ]);

    expect($timeout->data)->toMatchArray([
        'ok' => false,
        'error' => 'timeout',
    ])->and($timeout->blocks)->toBe([]);

    $connectionFailure = 'connection failed';

    $unavailable = executeOrderStatus($bot, [
        'order_reference' => 'AB123',
        'email' => 'customer@example.test',
    ]);

    expect($unavailable->data)->toMatchArray([
        'ok' => false,
        'error' => 'integration_unavailable',
    ])->and($unavailable->blocks)->toBe([]);
});

test('generic orchestrator dispatches order status without a manual tool branch', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => orderStatusJsonResponse([
            'data' => ['status' => 'shipped'],
        ]),
    ]);
    [$bot] = checkOrderStatusContext([
        'request_schema' => [
            'type' => 'object',
            'properties' => ['order_number' => ['type' => 'string']],
            'required' => ['order_number'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'query' => ['order_number' => 'order_number'],
        ],
        'response_mapping' => [
            'output' => ['status' => 'data.status'],
        ],
    ], [
        'input_mapping' => [
            'order_reference' => [
                'source' => 'model_input',
                'model_input' => 'order_reference',
                'operation_argument' => 'order_number',
            ],
        ],
    ]);
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
                        'call_id' => 'order-call',
                        'name' => 'check_order_status',
                        'arguments' => json_encode([
                            'order_reference' => 'AB123',
                        ], JSON_THROW_ON_ERROR),
                    ]],
                    'output_text' => null,
                    'usage' => null,
                ]
                : [
                    'output' => [],
                    'output_text' => 'Your order has shipped.',
                    'usage' => null,
                ];
        }
    };
    app()->instance(AiClient::class, $fake);

    $response = app(AiSearchOrchestrator::class)->run($bot, 'Has order AB123 shipped?');

    expect($response->answer)->toBe('Your order has shipped.')
        ->and($response->toolCallsCount)->toBe(1)
        ->and($response->blocks)->toBe([[
            'type' => 'order_status',
            'data' => [
                'status' => 'shipped',
                'fields' => [],
            ],
        ]])
        ->and($fake->payloads[1]['input'])->toContain([
            'type' => 'function_call_output',
            'call_id' => 'order-call',
            'output' => json_encode([
                'ok' => true,
                'order_status' => ['status' => 'shipped'],
            ], JSON_THROW_ON_ERROR),
        ]);
});
