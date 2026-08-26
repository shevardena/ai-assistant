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
use App\Services\Ai\Tools\GetStoreLocationsTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: Bot, 1: ApiOperation, 2: BotApiOperation}
 */
function storeLocationsContext(
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
        'key' => 'store_locations',
        'name' => 'Store locations',
        'execution_mode' => ApiOperationMode::Read->value,
        'method' => 'GET',
        'path' => '/locations',
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'zip' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['zip'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'query' => [
                'zip' => 'postal_code',
                'limit' => 'limit',
            ],
        ],
        'response_mapping' => [
            'collection' => [
                'path' => 'data.locations',
                'limit' => 10,
                'fields' => [
                    'name' => 'name',
                    'address' => 'address',
                    'city' => 'city',
                    'postal_code' => 'postal_code',
                    'latitude' => 'latitude',
                    'longitude' => 'longitude',
                    'distance_km' => 'distance_km',
                ],
            ],
        ],
        ...$operationOverrides,
    ]);
    $attachment = BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'get_store_locations',
        'is_enabled' => true,
        'settings' => $attachmentSettings ?: [
            'input_mapping' => [
                'postal_code' => [
                    'source' => 'model_input',
                    'model_input' => 'postal_code',
                    'operation_argument' => 'zip',
                ],
                'limit' => [
                    'source' => 'model_input',
                    'model_input' => 'limit',
                    'operation_argument' => 'limit',
                ],
            ],
        ],
    ]);

    return [$bot, $operation, $attachment];
}

function executeStoreLocations(Bot $bot, array $arguments): ToolResult
{
    return app(GetStoreLocationsTool::class)->execute(
        $bot,
        $arguments,
        ToolExecutionContext::forBot($bot),
    );
}

function locationsJsonResponse(array $payload, int $status = 200): PromiseInterface
{
    return Http::response($payload, $status, ['Content-Type' => 'application/json']);
}

test('the registry exposes locations without requiring a catalog dataset', function () {
    [$bot, $operation, $attachment] = storeLocationsContext();
    $registry = app(BotToolRegistry::class);

    expect($bot->datasets)->toHaveCount(0)
        ->and($registry->find($bot, 'get_store_locations'))->toBeInstanceOf(GetStoreLocationsTool::class);

    $attachment->update(['is_enabled' => false]);
    expect($registry->find($bot, 'get_store_locations'))->toBeNull();

    $attachment->update(['is_enabled' => true]);
    $operation->update(['execution_mode' => ApiOperationMode::Write->value]);
    expect($registry->find($bot, 'get_store_locations'))->toBeNull();
});

test('a bot without a location attachment does not expose the tool', function () {
    $user = User::factory()->create();
    $bot = Bot::factory()->create(['team_id' => $user->currentTeam->id]);

    expect(app(BotToolRegistry::class)->find($bot, 'get_store_locations'))->toBeNull();
});

test('get_store_locations has a strict bounded geographic schema', function () {
    [$bot] = storeLocationsContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'get_store_locations');
    $schema = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($schema)->toMatchArray([
        'type' => 'function',
        'name' => 'get_store_locations',
        'strict' => true,
    ])
        ->and($schema['parameters']['properties'])->toBe([
            'query' => ['type' => 'string'],
            'postal_code' => ['type' => 'string'],
            'city' => ['type' => 'string'],
            'country' => ['type' => 'string'],
            'latitude' => [
                'type' => 'number',
                'minimum' => -90,
                'maximum' => 90,
            ],
            'longitude' => [
                'type' => 'number',
                'minimum' => -180,
                'maximum' => 180,
            ],
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 20,
            ],
        ])
        ->and($schema['parameters']['required'])->toBe([])
        ->and($schema['parameters']['additionalProperties'])->toBeFalse();
});

test('postal-code lookup maps safe fields and caps the location collection', function () {
    Http::preventStrayRequests();
    $locations = [];

    for ($index = 1; $index <= 25; $index++) {
        $locations[] = [
            'name' => 'Store '.$index,
            'address' => $index.' Main Street',
            'city' => 'New York',
            'postal_code' => '10001',
            'latitude' => 40.75,
            'longitude' => -73.99,
            'distance_km' => $index / 10,
            'internal_store_id' => 'internal-'.$index,
            'manager_email' => 'private@example.test',
            'internal_notes' => 'Do not expose',
        ];
    }

    Http::fake([
        'https://api.example.test/*' => locationsJsonResponse([
            'data' => ['locations' => $locations],
        ]),
    ]);
    [$bot, $operation] = storeLocationsContext();
    DataSourceCredential::factory()->create([
        'data_source_id' => $operation->dataSource->id,
        'key' => 'bearer_token',
        'encrypted_value' => 'top-secret-token',
    ]);

    $result = executeStoreLocations($bot, ['postal_code' => '10001']);

    expect($result->data['ok'])->toBeTrue()
        ->and($result->data['locations'])->toHaveCount(10)
        ->and($result->data['locations'][0])->toEqualCanonicalizing([
            'name' => 'Store 1',
            'address' => '1 Main Street',
            'city' => 'New York',
            'postal_code' => '10001',
            'latitude' => 40.75,
            'longitude' => -73.99,
            'distance_km' => 0.1,
        ])
        ->and($result->blocks)->toBe([[
            'type' => 'locations',
            'data' => [
                'locations' => [[
                    'name' => 'Store 1',
                    'address' => '1 Main Street',
                    'city' => 'New York',
                    'postal_code' => '10001',
                    'latitude' => 40.75,
                    'longitude' => -73.99,
                    'distance' => 0.1,
                    'distance_unit' => 'km',
                ],
                    ...array_map(static fn (int $index): array => [
                        'name' => 'Store '.$index,
                        'address' => $index.' Main Street',
                        'city' => 'New York',
                        'postal_code' => '10001',
                        'latitude' => 40.75,
                        'longitude' => -73.99,
                        'distance' => $index / 10,
                        'distance_unit' => 'km',
                    ], range(2, 10)),
                ],
            ],
        ]])
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('internal_store_id')
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('manager_email')
        ->and(json_encode($result->data, JSON_THROW_ON_ERROR))->not->toContain('top-secret-token');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.example.test/locations?postal_code=10001'
            && $request->header('Authorization') === ['Bearer top-secret-token'];
    });
});

test('coordinate lookup maps latitude and longitude through the generic mapper', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => locationsJsonResponse([
            'data' => [
                'locations' => [[
                    'name' => 'Berlin Store',
                    'latitude' => 52.52,
                    'longitude' => 13.405,
                ]],
            ],
        ]),
    ]);
    [$bot] = storeLocationsContext([
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'lat' => ['type' => 'number'],
                'lng' => ['type' => 'number'],
            ],
            'required' => ['lat', 'lng'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'query' => [
                'lat' => 'lat',
                'lng' => 'lng',
            ],
        ],
        'response_mapping' => [
            'collection' => [
                'path' => 'data.locations',
                'fields' => [
                    'name' => 'name',
                    'latitude' => 'latitude',
                    'longitude' => 'longitude',
                ],
            ],
        ],
    ], [
        'input_mapping' => [
            'latitude' => [
                'source' => 'model_input',
                'model_input' => 'latitude',
                'operation_argument' => 'lat',
            ],
            'longitude' => [
                'source' => 'model_input',
                'model_input' => 'longitude',
                'operation_argument' => 'lng',
            ],
        ],
    ]);

    $result = executeStoreLocations($bot, [
        'latitude' => 52.52,
        'longitude' => 13.405,
    ]);

    expect($result->data)->toBe([
        'ok' => true,
        'locations' => [[
            'name' => 'Berlin Store',
            'latitude' => 52.52,
            'longitude' => 13.405,
        ]],
    ])
        ->and($result->blocks[0]['data']['locations'][0])->toMatchArray([
            'name' => 'Berlin Store',
            'latitude' => 52.52,
            'longitude' => 13.405,
        ]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.example.test/locations?lat=52.52&lng=13.405';
    });
});

test('successful mapped aliases create a safe map-ready locations block', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => locationsJsonResponse([
            'data' => ['locations' => [[
                'store_name' => 'Airport Store',
                'street_address' => '1 Airport Way',
                'state' => 'NY',
                'zip_code' => '11430',
                'lat' => 40.6413,
                'lon' => -73.7781,
                'distance_miles' => 2.4,
                'url' => 'https://maps.example.test/airport',
                'hours' => 'Daily 8-8',
            ]]],
        ]),
    ]);
    [$bot] = storeLocationsContext([
        'response_mapping' => [
            'collection' => [
                'path' => 'data.locations',
                'fields' => [
                    'store_name' => 'store_name',
                    'street_address' => 'street_address',
                    'state' => 'state',
                    'zip_code' => 'zip_code',
                    'lat' => 'lat',
                    'lon' => 'lon',
                    'distance_miles' => 'distance_miles',
                    'url' => 'url',
                    'hours' => 'hours',
                ],
            ],
        ],
    ]);

    $result = executeStoreLocations($bot, ['postal_code' => '11430']);

    expect($result->blocks)->toBe([[
        'type' => 'locations',
        'data' => [
            'locations' => [[
                'name' => 'Airport Store',
                'address' => '1 Airport Way',
                'region' => 'NY',
                'postal_code' => '11430',
                'latitude' => 40.6413,
                'longitude' => -73.7781,
                'distance' => 2.4,
                'distance_unit' => 'miles',
                'hours' => 'Daily 8-8',
                'url' => 'https://maps.example.test/airport',
            ]],
        ],
    ]]);
});

test('missing required location input and invalid coordinates fail before HTTP', function () {
    Http::preventStrayRequests();
    [$bot] = storeLocationsContext();

    foreach ([
        [],
        ['latitude' => 91.0, 'longitude' => 13.4],
        ['latitude' => 52.5],
        ['postal_code' => '10001', 'limit' => 21],
        ['postal_code' => '10001', 'limit' => 0],
        ['postal_code' => '10001', 'unexpected' => 'value'],
    ] as $arguments) {
        expect(executeStoreLocations($bot, $arguments)->data)
            ->toMatchArray(['ok' => false, 'error' => 'invalid_request']);
    }

    Http::assertNothingSent();
});

test('no matching locations returns an empty successful list', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => locationsJsonResponse([
            'data' => ['locations' => []],
        ]),
    ]);
    [$bot] = storeLocationsContext();

    $result = executeStoreLocations($bot, ['postal_code' => '99999']);

    expect($result->data)->toBe([
        'ok' => true,
        'locations' => [],
    ])
        ->and($result->blocks)->toBe([]);
});

test('foreign-team, other-bot, and non-ready integrations are unavailable', function () {
    Http::preventStrayRequests();
    [$bot, $operation, $attachment] = storeLocationsContext();
    $otherBot = Bot::factory()->create(['team_id' => $bot->team_id]);
    $attachment->update(['bot_id' => $otherBot->id]);

    expect(app(BotToolRegistry::class)->find($bot, 'get_store_locations'))->toBeNull()
        ->and(executeStoreLocations($bot, ['postal_code' => '10001'])->data)
        ->toMatchArray(['ok' => false, 'error' => 'integration_unavailable']);

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

    expect(app(BotToolRegistry::class)->find($bot, 'get_store_locations'))->toBeNull()
        ->and(executeStoreLocations($bot, ['postal_code' => '10001'])->data)
        ->toMatchArray(['ok' => false, 'error' => 'integration_unavailable']);

    $attachment->update(['api_operation_id' => $operation->id]);
    $operation->dataSource->update(['status' => 'preparing']);

    expect(executeStoreLocations($bot, ['postal_code' => '10001'])->data)
        ->toMatchArray(['ok' => false, 'error' => 'integration_unavailable']);

    Http::assertNothingSent();
});

test('invalid collection responses and upstream failures stay safe', function () {
    Http::preventStrayRequests();
    [$bot] = storeLocationsContext();
    $responsePayload = [
        'data' => ['locations' => ['unsafe-item']],
    ];
    $responseStatus = 200;

    Http::fake([
        'https://api.example.test/*' => function () use (&$responsePayload, &$responseStatus): PromiseInterface {
            return locationsJsonResponse($responsePayload, $responseStatus);
        },
    ]);

    $invalid = executeStoreLocations($bot, ['postal_code' => '10001']);

    expect($invalid->data)->toMatchArray([
        'ok' => false,
        'error' => 'integration_error',
    ])
        ->and($invalid->blocks)->toBe([]);

    $responsePayload = ['error' => 'private upstream body'];
    $responseStatus = 503;

    $failure = executeStoreLocations($bot, ['postal_code' => '10001']);

    expect($failure->data)->toMatchArray([
        'ok' => false,
        'error' => 'integration_error',
    ])
        ->and(json_encode($failure->data, JSON_THROW_ON_ERROR))->not->toContain('private upstream body');
});

test('timeouts are normalized safely', function () {
    Http::preventStrayRequests();
    [$bot] = storeLocationsContext();
    Http::fake([
        'https://api.example.test/*' => fn (): never => throw new ConnectionException('request timed out'),
    ]);

    $result = executeStoreLocations($bot, ['postal_code' => '10001']);

    expect($result->data)->toMatchArray([
        'ok' => false,
        'error' => 'timeout',
    ])
        ->and($result->blocks)->toBe([]);
});

test('generic orchestrator dispatches locations without a manual branch', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => locationsJsonResponse([
            'data' => [
                'locations' => [[
                    'name' => 'Downtown Store',
                ]],
            ],
        ]),
    ]);
    [$bot] = storeLocationsContext([
        'response_mapping' => [
            'collection' => [
                'path' => 'data.locations',
                'fields' => ['name' => 'name'],
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
                        'call_id' => 'locations-call',
                        'name' => 'get_store_locations',
                        'arguments' => json_encode([
                            'postal_code' => '10001',
                        ], JSON_THROW_ON_ERROR),
                    ]],
                    'output_text' => null,
                    'usage' => null,
                ]
                : [
                    'output' => [],
                    'output_text' => 'There is a Downtown Store nearby.',
                    'usage' => null,
                ];
        }
    };
    app()->instance(AiClient::class, $fake);

    $response = app(AiSearchOrchestrator::class)->run($bot, 'Where is your nearest store?');

    expect($response->answer)->toBe('There is a Downtown Store nearby.')
        ->and($response->toolCallsCount)->toBe(1)
        ->and($fake->payloads[1]['input'])->toContain([
            'type' => 'function_call_output',
            'call_id' => 'locations-call',
            'output' => json_encode([
                'ok' => true,
                'locations' => [['name' => 'Downtown Store']],
            ], JSON_THROW_ON_ERROR),
        ]);
});
