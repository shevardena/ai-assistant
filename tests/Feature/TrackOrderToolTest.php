<?php

use App\Enums\ApiOperationMode;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\DataSource;
use App\Models\DataSourceCredential;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiSearchOrchestrator;
use App\Services\Ai\AiToolSchemaBuilder;
use App\Services\Ai\BotToolRegistry;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Ai\Tools\TrackOrderTool;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: Bot, 1: ApiOperation, 2: BotApiOperation}
 */
function trackOrderContext(
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
        'key' => 'shipment_tracking',
        'name' => 'Shipment tracking',
        'execution_mode' => ApiOperationMode::Read->value,
        'method' => 'GET',
        'path' => '/shipments/track',
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
                'status' => 'shipment.status',
                'carrier' => 'shipment.carrier',
                'tracking_number' => 'shipment.tracking_number',
                'estimated_delivery' => 'shipment.eta',
                'latest_event' => 'shipment.latest_event',
            ],
        ],
        ...$operationOverrides,
    ]);
    $attachment = BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'track_order',
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

function executeTrackOrder(Bot $bot, array $arguments): ToolResult
{
    return app(TrackOrderTool::class)->execute(
        $bot,
        $arguments,
        ToolExecutionContext::forBot($bot),
    );
}

function trackingJsonResponse(array $payload, int $status = 200): PromiseInterface
{
    return Http::response($payload, $status, ['Content-Type' => 'application/json']);
}

test('the registry exposes tracking without requiring a catalog dataset', function () {
    [$bot, $operation, $attachment] = trackOrderContext();
    $registry = app(BotToolRegistry::class);

    expect($bot->datasets)->toHaveCount(0)
        ->and($registry->find($bot, 'track_order'))->toBeInstanceOf(TrackOrderTool::class);

    $attachment->update(['is_enabled' => false]);
    expect($registry->find($bot, 'track_order'))->toBeNull();

    $attachment->update(['is_enabled' => true]);
    $operation->update(['execution_mode' => ApiOperationMode::Write->value]);
    expect($registry->find($bot, 'track_order'))->toBeNull();
});

test('a bot without a tracking attachment does not expose the tool', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->create(['team_id' => $user->currentTeam->id]);

    expect(app(BotToolRegistry::class)->find($bot, 'track_order'))->toBeNull();
});

test('track_order has a strict schema with order or tracking reference lookup', function () {
    [$bot] = trackOrderContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'track_order');
    $schema = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($schema)->toMatchArray([
        'type' => 'function',
        'name' => 'track_order',
        'strict' => true,
    ])
        ->and($schema['parameters']['properties'])->toBe([
            'order_reference' => ['type' => 'string'],
            'tracking_reference' => ['type' => 'string'],
            'email' => ['type' => 'string'],
            'postal_code' => ['type' => 'string'],
            'phone' => ['type' => 'string'],
        ])
        ->and($schema['parameters']['required'])->toBe([])
        ->and($schema['parameters']['anyOf'])->toBe([
            ['required' => ['order_reference']],
            ['required' => ['tracking_reference']],
        ])
        ->and($schema['parameters']['additionalProperties'])->toBeFalse();
});

test('order-reference tracking maps verification input and exposes only safe fields', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => trackingJsonResponse([
            'shipment' => [
                'status' => 'in_transit',
                'carrier' => 'DHL',
                'tracking_number' => 'ABC123',
                'eta' => '2026-08-27',
                'latest_event' => 'Departed facility',
                'customer_email' => 'private@example.test',
                'delivery_address' => 'private address',
                'internal_shipment_id' => 'internal-123',
            ],
        ]),
    ]);
    [$bot, $operation] = trackOrderContext();
    DataSourceCredential::factory()->create([
        'data_source_id' => $operation->dataSource->id,
        'key' => 'bearer_token',
        'encrypted_value' => 'top-secret-token',
    ]);

    $result = executeTrackOrder($bot, [
        'order_reference' => 'AB123',
        'email' => 'customer@example.test',
    ]);

    expect($result->data['ok'])->toBeTrue()
        ->and($result->data['tracking'])->toEqualCanonicalizing([
            'status' => 'in_transit',
            'carrier' => 'DHL',
            'tracking_number' => 'ABC123',
            'estimated_delivery' => '2026-08-27',
            'latest_event' => 'Departed facility',
        ])
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('top-secret-token')
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('private@example.test')
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('internal-123')
        ->and($result->blocks)->toBe([[
            'type' => 'tracking',
            'data' => [
                'status' => 'in_transit',
                'carrier' => 'DHL',
                'tracking_reference' => 'ABC123',
                'estimated_delivery' => '2026-08-27',
                'latest_event' => 'Departed facility',
                'fields' => [],
            ],
        ]])
        ->and($result->blocks[0]['data'])->not->toHaveKey('internal_shipment_id');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.example.test/shipments/track?order_number=AB123&customer_email=customer%40example.test'
            && $request->header('Authorization') === ['Bearer top-secret-token'];
    });
});

test('tracking-reference integrations use the same generic mapper', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => trackingJsonResponse([
            'shipment' => [
                'status' => 'delivered',
                'delivered_at' => '2026-08-21T15:30:00Z',
            ],
        ]),
    ]);
    [$bot] = trackOrderContext([
        'request_schema' => [
            'type' => 'object',
            'properties' => ['tracking_number' => ['type' => 'string']],
            'required' => ['tracking_number'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'query' => ['tracking_number' => 'tracking_number'],
        ],
        'response_mapping' => [
            'output' => [
                'status' => 'shipment.status',
                'delivered_at' => 'shipment.delivered_at',
            ],
        ],
    ], [
        'input_mapping' => [
            'tracking_reference' => [
                'source' => 'model_input',
                'model_input' => 'tracking_reference',
                'operation_argument' => 'tracking_number',
            ],
        ],
    ]);

    $result = executeTrackOrder($bot, ['tracking_reference' => 'ABC123']);

    expect($result->data)->toBe([
        'ok' => true,
        'tracking' => [
            'status' => 'delivered',
            'delivered_at' => '2026-08-21T15:30:00Z',
        ],
    ]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.example.test/shipments/track?tracking_number=ABC123';
    });
});

test('tracking blocks promote aliases, preserve generic scalars, and validate URLs', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => trackingJsonResponse([
            'shipment' => [
                'state' => 'out_for_delivery',
                'carrier_name' => 'UPS',
                'number' => '1Z999',
                'eta' => '2026-08-27',
                'latest_update' => 'Arrived at local facility',
                'link' => 'https://carrier.example.test/track/1Z999',
                'service_level' => 'Ground',
                'signature_required' => true,
            ],
        ]),
    ]);
    [$bot] = trackOrderContext([
        'response_mapping' => [
            'output' => [
                'tracking_status' => 'shipment.state',
                'carrier' => 'shipment.carrier_name',
                'tracking_number' => 'shipment.number',
                'eta' => 'shipment.eta',
                'latest_update' => 'shipment.latest_update',
                'tracking_url' => 'shipment.link',
                'service_level' => 'shipment.service_level',
                'signature_required' => 'shipment.signature_required',
            ],
        ],
    ]);

    $result = executeTrackOrder($bot, [
        'order_reference' => 'AB123',
        'email' => 'customer@example.test',
    ]);

    expect($result->blocks[0])->toBe([
        'type' => 'tracking',
        'data' => [
            'status' => 'out_for_delivery',
            'carrier' => 'UPS',
            'tracking_reference' => '1Z999',
            'estimated_delivery' => '2026-08-27',
            'latest_event' => 'Arrived at local facility',
            'tracking_url' => 'https://carrier.example.test/track/1Z999',
            'fields' => [
                ['key' => 'service_level', 'label' => 'Service level', 'value' => 'Ground'],
                ['key' => 'signature_required', 'label' => 'Signature required', 'value' => true],
            ],
        ],
    ]);
});

test('unsafe mapped tracking URLs are omitted without affecting other fields', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => trackingJsonResponse([
            'shipment' => [
                'status' => 'in_transit',
                'tracking_url' => 'javascript:alert(1)',
            ],
        ]),
    ]);
    [$bot] = trackOrderContext([
        'response_mapping' => [
            'output' => [
                'status' => 'shipment.status',
                'tracking_url' => 'shipment.tracking_url',
            ],
        ],
    ]);

    $result = executeTrackOrder($bot, [
        'order_reference' => 'AB123',
        'email' => 'customer@example.test',
    ]);

    expect($result->blocks[0])->toBe([
        'type' => 'tracking',
        'data' => [
            'status' => 'in_transit',
            'fields' => [],
        ],
    ]);
});

test('missing configured verification input returns invalid_request without HTTP', function () {
    Http::preventStrayRequests();
    [$bot] = trackOrderContext();

    $result = executeTrackOrder($bot, ['order_reference' => 'AB123']);

    expect($result->data)->toMatchArray([
        'ok' => false,
        'error' => 'invalid_request',
    ]);

    Http::assertNothingSent();
});

test('missing or unconfigured lookup inputs are rejected without HTTP', function () {
    Http::preventStrayRequests();
    [$bot] = trackOrderContext();

    foreach ([
        [],
        ['email' => 'customer@example.test'],
        ['order_reference' => '', 'email' => 'customer@example.test'],
        ['order_reference' => 'AB123', 'tracking_reference' => 'ABC123', 'carrier_url' => 'https://evil.test'],
    ] as $arguments) {
        expect(executeTrackOrder($bot, $arguments)->data)
            ->toMatchArray(['ok' => false, 'error' => 'invalid_request']);
    }

    Http::assertNothingSent();
});

test('foreign-team and other-bot operations are unavailable', function () {
    Http::preventStrayRequests();
    [$bot, $operation, $attachment] = trackOrderContext();
    $otherBot = Bot::factory()->create(['team_id' => $bot->team_id]);
    $attachment->update(['bot_id' => $otherBot->id]);

    expect(app(BotToolRegistry::class)->find($bot, 'track_order'))->toBeNull()
        ->and(executeTrackOrder($bot, [
            'order_reference' => 'AB123',
            'email' => 'customer@example.test',
        ])->data)->toMatchArray(['ok' => false, 'error' => 'integration_unavailable']);

    $attachment->update(['bot_id' => $bot->id]);
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

    expect(app(BotToolRegistry::class)->find($bot, 'track_order'))->toBeNull()
        ->and(executeTrackOrder($bot, [
            'order_reference' => 'AB123',
            'email' => 'customer@example.test',
        ])->data)->toMatchArray(['ok' => false, 'error' => 'integration_unavailable']);

    $attachment->update(['api_operation_id' => $operation->id]);
    Http::assertNothingSent();
});

test('non-ready integrations do not execute', function () {
    Http::preventStrayRequests();
    [$bot, $operation] = trackOrderContext();
    $operation->dataSource->update(['status' => 'preparing']);

    expect(executeTrackOrder($bot, [
        'order_reference' => 'AB123',
        'email' => 'customer@example.test',
    ])->data)->toMatchArray(['ok' => false, 'error' => 'integration_unavailable']);

    Http::assertNothingSent();
});

test('privacy-safe upstream failures normalize to tracking_not_available', function () {
    Http::preventStrayRequests();
    [$bot] = trackOrderContext();
    Http::fake([
        'https://api.example.test/*' => trackingJsonResponse([
            'error' => 'order exists but email verification failed',
            'customer_email' => 'private@example.test',
            'internal_shipment_id' => 'internal-123',
        ], 404),
    ]);

    $result = executeTrackOrder($bot, [
        'order_reference' => 'AB123',
        'email' => 'wrong@example.test',
    ]);

    expect($result->data)->toMatchArray([
        'ok' => false,
        'error' => 'tracking_not_available',
    ])
        ->and($result->blocks)->toBe([])
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('order exists')
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('private@example.test')
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('internal-123');
});

test('timeout and unavailable integrations are normalized safely', function () {
    Http::preventStrayRequests();
    [$bot] = trackOrderContext();
    $connectionFailure = 'request timed out';
    Http::fake([
        'https://api.example.test/*' => function () use (&$connectionFailure): never {
            throw new ConnectionException($connectionFailure);
        },
    ]);

    $timeout = executeTrackOrder($bot, [
        'order_reference' => 'AB123',
        'email' => 'customer@example.test',
    ]);

    expect($timeout->data)->toMatchArray([
        'ok' => false,
        'error' => 'timeout',
    ])->and($timeout->blocks)->toBe([]);

    $connectionFailure = 'connection failed';

    $unavailable = executeTrackOrder($bot, [
        'order_reference' => 'AB123',
        'email' => 'customer@example.test',
    ]);

    expect($unavailable->data)->toMatchArray([
        'ok' => false,
        'error' => 'integration_unavailable',
    ])->and($unavailable->blocks)->toBe([]);
});

test('generic orchestrator dispatches track_order without a manual branch', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => trackingJsonResponse([
            'shipment' => ['status' => 'in_transit'],
        ]),
    ]);
    [$bot] = trackOrderContext([
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
            'output' => ['status' => 'shipment.status'],
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
                        'call_id' => 'track-call',
                        'name' => 'track_order',
                        'arguments' => json_encode([
                            'order_reference' => 'AB123',
                        ], JSON_THROW_ON_ERROR),
                    ]],
                    'output_text' => null,
                    'usage' => null,
                ]
                : [
                    'output' => [],
                    'output_text' => 'Your shipment is in transit.',
                    'usage' => null,
                ];
        }
    };
    app()->instance(AiClient::class, $fake);

    $response = app(AiSearchOrchestrator::class)->run($bot, 'Where is my package?');

    expect($response->answer)->toBe('Your shipment is in transit.')
        ->and($response->toolCallsCount)->toBe(1)
        ->and($response->blocks)->toBe([[
            'type' => 'tracking',
            'data' => [
                'status' => 'in_transit',
                'fields' => [],
            ],
        ]])
        ->and($fake->payloads[1]['input'])->toContain([
            'type' => 'function_call_output',
            'call_id' => 'track-call',
            'output' => json_encode([
                'ok' => true,
                'tracking' => ['status' => 'in_transit'],
            ], JSON_THROW_ON_ERROR),
        ]);
});
