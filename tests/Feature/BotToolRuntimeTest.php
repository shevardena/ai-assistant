<?php

use App\Enums\ApiOperationMode;
use App\Enums\RuntimeMode;
use App\Enums\TeamRole;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\BotDataset;
use App\Models\Dataset;
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

test('live catalog search sends canonical Camry and Prius terms through the remote mapping', function () {
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
            'properties' => ['search' => ['type' => 'string']],
            'required' => [],
        ],
        'request_mapping' => [
            'query' => ['search' => 'q'],
            'live_query' => ['search_text' => 'q'],
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
    Http::fake([
        'https://api.example.test/*' => function ($request) {
            $isPrius = str_contains($request->url(), 'q=prius');

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

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'q=camry'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'q=prius'));

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
        ->and($priusResult->data['search']['items'][0]['title'])->toBe('TOYOTA PRIUS - ფარი');
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
