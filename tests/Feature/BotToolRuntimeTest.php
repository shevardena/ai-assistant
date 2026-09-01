<?php

use App\Enums\ApiOperationMode;
use App\Enums\RuntimeMode;
use App\Enums\TeamRole;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\BotDataset;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\DataSource;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiSearchOrchestrator;
use App\Services\Ai\AiToolSchemaBuilder;
use App\Services\Ai\BotToolRegistry;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\Tools\SearchCatalogTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use Illuminate\Support\Facades\Http;

function botToolRegistryContext(bool $attachDataset = true): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    if ($attachDataset) {
        $dataset = Dataset::factory()->ready()->create(['team_id' => $team->id]);
        BotDataset::factory()->create([
            'bot_id' => $bot->id,
            'dataset_id' => $dataset->id,
            'is_enabled' => true,
        ]);
    }

    return [$user, $team, $bot];
}

test('the registry resolves the dataset-backed tools for an eligible bot', function () {
    [, , $bot] = botToolRegistryContext();
    $registry = app(BotToolRegistry::class);

    expect($registry->forBot($bot))->toHaveCount(3)
        ->and($registry->find($bot, 'search_catalog'))->not->toBeNull()
        ->and($registry->find($bot, 'get_product_details'))->not->toBeNull()
        ->and($registry->find($bot, 'request_human_handoff'))->not->toBeNull();

    [, , $botWithoutDataset] = botToolRegistryContext(false);

    expect($registry->forBot($botWithoutDataset))->toHaveCount(1)
        ->and($registry->find($botWithoutDataset, 'search_catalog'))->toBeNull()
        ->and($registry->find($botWithoutDataset, 'request_human_handoff'))->not->toBeNull();
});

test('the registry exposes catalog search for a valid live operation without a dataset', function () {
    [, $team, $bot] = botToolRegistryContext(false);
    $source = DataSource::factory()->create(['team_id' => $team->id, 'type' => 'rest_api', 'status' => 'pending']);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $source->id,
        'execution_mode' => ApiOperationMode::Read->value,
        'request_schema' => ['properties' => ['q' => ['type' => 'string']]],
        'response_mapping' => ['output' => ['title' => ['path' => 'name']]],
    ]);
    BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'search_catalog',
        'is_enabled' => true,
    ]);

    $registry = app(BotToolRegistry::class);

    expect($registry->find($bot, 'search_catalog'))->not->toBeNull()
        ->and($registry->find($bot, 'get_product_details'))->toBeNull();
});

test('search_catalog exposes only product datasets and never knowledge datasets', function () {
    [, $team, $bot] = botToolRegistryContext();
    $knowledge = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'slug' => 'company-knowledge',
        'entity_type' => 'knowledge',
    ]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $knowledge->id,
        'is_enabled' => true,
    ]);

    $definition = app(BotToolRegistry::class)->find($bot, 'search_catalog')->schema($bot);

    expect($definition['properties']['dataset']['enum'])->toContain(null)
        ->toContain($bot->datasets()->where('slug', '!=', 'company-knowledge')->value('slug'))
        ->not->toContain('company-knowledge')
        ->and($definition['required'])->toEqualCanonicalizing(array_keys($definition['properties']));
});

test('live catalog mappings translate canonical search arguments to common API parameter names', function () {
    botToolRegistryContext(false);
    $operation = ApiOperation::factory()->make([
        'request_schema' => [
            'properties' => [
                'name' => ['type' => 'string'],
                'category_name' => ['type' => 'string'],
                'per_page' => ['type' => 'integer'],
            ],
        ],
        'request_mapping' => [
            'query' => [
                'name' => 'name',
                'category_name' => 'category_name',
                'per_page' => 'per_page',
            ],
        ],
    ]);
    $method = new ReflectionMethod(SearchCatalogTool::class, 'liveArguments');

    $arguments = $method->invoke(app(SearchCatalogTool::class), $operation, [
        'text' => 'ბამპერის ბადე',
        'filters' => [[
            'field' => 'category',
            'operator' => 'eq',
            'value' => 'Toyota',
        ]],
        'limit' => 10,
    ]);

    expect($arguments)->toBe([
        'name' => 'ბამპერის ბადე',
        'category_name' => 'Toyota',
        'per_page' => 10,
    ]);
});

test('live catalog search tries the original term before the canonical remote mapping fallback', function () {
    [, $team, $bot] = botToolRegistryContext(false);
    $source = DataSource::factory()->ready()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
    ]);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $source->id,
        'execution_mode' => ApiOperationMode::Read->value,
        'method' => 'GET',
        'path' => '/products',
        'request_schema' => [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ],
        'request_mapping' => [
            'query' => [],
            'fixed' => ['query' => ['per_page' => 300]],
            'live_query' => [
                'search_text' => 'name',
                'constraints' => ['year' => ['eq' => 'y']],
            ],
        ],
        'response_mapping' => [
            'collection' => [
                'path' => 'data',
                'fields' => [
                    'id' => ['path' => 'id', 'type' => 'integer', 'required' => true],
                    'title' => ['path' => 'name', 'type' => 'string', 'required' => true, 'searchable' => true],
                    'price' => ['path' => 'price', 'type' => 'decimal', 'required' => false],
                ],
            ],
            'pagination' => ['type' => 'none'],
        ],
    ]);
    BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'search_catalog',
        'is_enabled' => true,
    ]);

    Http::preventStrayRequests();
    $queries = [];
    Http::fake([
        'https://api.example.test/*' => function ($request) use (&$queries) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $searchText = (string) ($query['name'] ?? '');
            $queries[] = $searchText;
            $isCamry = strtolower($searchText) === 'camry';
            $isPrius = strtolower($searchText) === 'prius';

            if (! $isCamry && ! $isPrius) {
                return Http::response(['data' => []]);
            }

            return Http::response([
                'data' => [[
                    'id' => $isPrius ? 51 : 35,
                    'name' => $isPrius ? 'TOYOTA PRIUS - ფარი' : '07-09 CAMRY - ბამპერი (წინა)',
                    'price' => $isPrius ? '90.00' : '160.00',
                ]],
            ]);
        },
    ]);

    $tool = app(BotToolRegistry::class)->find($bot, 'search_catalog');
    $camryResult = $tool->execute(
        $bot,
        [
            'dataset' => null,
            'text' => 'camry',
            'filters' => [],
            'sorts' => [],
            'limit' => 10,
            'result_count' => null,
        ],
        ToolExecutionContext::forBot(
            $bot,
            userMessage: new Message(['content' => 'მაჩვენე ქემრის ნაწილები']),
            mode: RuntimeMode::Test,
        ),
    );

    $priusResult = $tool->execute(
        $bot,
        [
            'dataset' => null,
            'text' => 'prius',
            'filters' => [],
            'sorts' => [],
            'limit' => 10,
            'result_count' => null,
        ],
        ToolExecutionContext::forBot(
            $bot,
            userMessage: new Message(['content' => 'სალამი, პრისუზე რამე გაქვთ?']),
            mode: RuntimeMode::Test,
        ),
    );

    $yearResult = $tool->execute(
        $bot,
        [
            'dataset' => null,
            'text' => 'Prius',
            'filters' => [],
            'constraints' => [['type' => 'year', 'operator' => 'eq', 'value' => 2009]],
            'sorts' => [],
            'limit' => 10,
            'result_count' => null,
        ],
        ToolExecutionContext::forBot(
            $bot,
            userMessage: new Message(['content' => '2009 Prius']),
            mode: RuntimeMode::Test,
        ),
    );

    $relaxedResult = $tool->execute(
        $bot,
        [
            'dataset' => null,
            'text' => 'Toyota Prius',
            'filters' => [],
            'sorts' => [],
            'limit' => 10,
            'result_count' => null,
        ],
        ToolExecutionContext::forBot(
            $bot,
            userMessage: new Message(['content' => 'show me toyota prius product']),
            mode: RuntimeMode::Test,
        ),
    );

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'name=camry'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'name=prius'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'per_page=300'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'y=2009'));

    expect($camryResult->data['search']['count'])->toBe(1)
        ->and($camryResult->data['search']['items'][0]['title'])->toBe('07-09 CAMRY - ბამპერი (წინა)')
        ->and($camryResult->metadata['live_read'])->toMatchArray([
            'raw_response_item_count' => 1,
            'collection_extracted_item_count' => 1,
            'mapped_item_count' => 1,
            'matcher_input_count' => 1,
            'matcher_output_count' => 1,
            'product_mapped_count' => 1,
        ])
        ->and($priusResult->data['search']['count'])->toBe(1)
        ->and($priusResult->data['search']['items'][0]['title'])->toBe('TOYOTA PRIUS - ფარი')
        ->and($queries)->toBe(['ქემრი', 'camry', 'პრისუ', 'prius', 'prius', 'toyota prius', 'Prius'])
        ->and($camryResult->metadata['attempts'])->toMatchArray([
            ['type' => 'original', 'text' => 'ქემრი', 'count' => 0, 'confirmed_empty' => true, 'fallback_triggered' => true],
            ['type' => 'canonical_fallback', 'text' => 'camry', 'count' => 1, 'confirmed_empty' => false, 'fallback_triggered' => false],
        ])
        ->and($priusResult->metadata['selected_query'])->toBe('prius')
        ->and($yearResult->data['search']['count'])->toBe(1)
        ->and($yearResult->metadata['live_read']['remote_constraints'][0]['parameters'])->toBe(['y' => 2009])
        ->and($relaxedResult->data['search']['count'])->toBe(1)
        ->and($relaxedResult->metadata['selected_query'])->toBe('Prius');
});

test('search_catalog federates eligible product datasets and live API sources', function () {
    [, $team, $bot] = botToolRegistryContext();
    $dataset = $bot->datasets()->firstOrFail();
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'name',
        'label' => 'Name',
        'data_type' => 'string',
        'is_searchable' => true,
        'is_displayable' => true,
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => '35',
        'payload' => ['name' => 'Indexed CAMRY part'],
        'searchable_text' => 'Indexed CAMRY part',
    ]);

    $source = DataSource::factory()->ready()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
    ]);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $source->id,
        'execution_mode' => ApiOperationMode::Read->value,
        'method' => 'GET',
        'path' => '/products',
        'request_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
        'request_mapping' => [
            'query' => [],
            'fixed' => ['query' => ['per_page' => 300]],
            'live_query' => ['search_text' => 'name'],
        ],
        'response_mapping' => [
            'collection' => [
                'path' => 'data',
                'fields' => [
                    'id' => ['path' => 'id', 'type' => 'integer', 'required' => true],
                    'title' => ['path' => 'name', 'type' => 'string', 'required' => true, 'searchable' => true],
                ],
            ],
            'pagination' => ['type' => 'none'],
        ],
    ]);
    BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'search_catalog',
        'is_enabled' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => Http::response([
            'data' => [['id' => 35, 'name' => 'Live CAMRY part']],
        ]),
    ]);

    $result = app(BotToolRegistry::class)->find($bot, 'search_catalog')->execute(
        $bot,
        ['dataset' => null, 'text' => 'camry', 'filters' => [], 'sorts' => [], 'limit' => 10, 'result_count' => null],
        ToolExecutionContext::forBot($bot, mode: RuntimeMode::Test),
    );

    expect($result->data['search']['count'])->toBe(2)
        ->and($result->metadata['source_results'])->toHaveCount(2)
        ->and($result->metadata['source_errors'])->toBe([]);
});

test('the registered tool produces a strict schema without internal implementation details', function () {
    [, , $bot] = botToolRegistryContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'search_catalog');
    $definition = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($definition)->toMatchArray([
        'type' => 'function',
        'name' => 'search_catalog',
        'strict' => true,
    ])
        ->and($definition['parameters']['additionalProperties'])->toBeFalse()
        ->and(json_encode($definition, JSON_THROW_ON_ERROR))
        ->not->toContain('source_path')
        ->not->toContain('credentials')
        ->not->toContain('endpoint');
});

test('unknown model tool calls are rejected through generic registry dispatch', function () {
    [, , $bot] = botToolRegistryContext();
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
                        'call_id' => 'unknown-call',
                        'name' => 'lookup_faq',
                        'arguments' => '{}',
                    ]],
                    'output_text' => null,
                    'usage' => null,
                ]
                : [
                    'output' => [],
                    'output_text' => 'I cannot use that tool.',
                    'usage' => null,
                ];
        }
    };
    app()->instance(AiClient::class, $fake);

    $response = app(AiSearchOrchestrator::class)->run($bot, 'Show details.');

    expect($response->answer)->toBe('I cannot use that tool.')
        ->and($response->toolCallsCount)->toBe(1)
        ->and($fake->payloads[1]['input'])->toContain([
            'type' => 'function_call_output',
            'call_id' => 'unknown-call',
            'output' => json_encode([
                'ok' => false,
                'error' => 'unsupported_tool',
                'message' => 'The requested tool is not available.',
            ], JSON_THROW_ON_ERROR),
        ]);
});

test('tool results keep model data separate from internal metadata', function () {
    $result = ToolResult::success(
        ['ok' => true, 'search' => ['count' => 1]],
        ['card_source' => ['dataset_id' => 5, 'record_ids' => [9]]],
    );

    expect($result->modelData())->toBe([
        'ok' => true,
        'search' => ['count' => 1],
    ])
        ->and($result->metadata)->toBe([
            'card_source' => ['dataset_id' => 5, 'record_ids' => [9]],
        ]);
});
