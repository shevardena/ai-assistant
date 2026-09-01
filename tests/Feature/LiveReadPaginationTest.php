<?php

use App\Enums\ApiOperationMode;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\DataSource;
use App\Models\User;
use App\Services\Api\LiveRead\LiveReadQuery;
use App\Services\Api\LiveRead\LiveReadQueryPlan;
use App\Services\Api\LiveRead\LiveReadQueryPlanner;
use App\Services\Api\RuntimeApiOperation;
use App\Services\Api\RuntimeApiOperationExecutor;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

function liveReadRuntimeContext(array $mapping): RuntimeApiOperation
{
    $user =
        User::factory()->create();
    $bot = Bot::factory()->create(['team_id' => $user->currentTeam->id]);
    $source = DataSource::factory()->ready()->create([
        'team_id' => $bot->team_id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://live.example.test'],
    ]);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $source->id,
        'key' => 'find_records',
        'name' => 'Find records',
        'execution_mode' => ApiOperationMode::Read->value,
        'method' => 'GET',
        'path' => '/records',
        'request_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
        'request_mapping' => ['path' => [], 'query' => [], 'body' => []],
        'response_mapping' => $mapping,
    ]);
    $attachment = BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'find_records',
        'is_enabled' => true,
    ]);

    return new RuntimeApiOperation($bot, $attachment, $operation, $source);
}

function liveReadPlan(RuntimeApiOperation $runtime, array $arguments): LiveReadQueryPlan
{
    return (new LiveReadQueryPlanner)->plan($runtime->operation, LiveReadQuery::fromArguments($arguments));
}

function liveCollectionMapping(array $pagination = []): array
{
    return [
        'collection' => [
            'path' => 'data',
            'fields' => [
                'id' => ['path' => 'id', 'type' => 'integer'],
                'name' => ['path' => 'name', 'type' => 'string'],
                'price' => ['path' => 'price', 'type' => 'decimal'],
                'capacity' => ['path' => 'capacity', 'type' => 'decimal'],
                'region_code' => ['path' => 'region_code', 'type' => 'string'],
                'activation_date' => ['path' => 'activation_date', 'type' => 'date'],
            ],
        ],
        'pagination' => $pagination + ['type' => 'none'],
    ];
}

test('follows REST page pagination until an exact count is satisfied', function () {
    Http::fakeSequence('https://live.example.test/*')
        ->push(['data' => [['id' => 1, 'name' => 'no']], 'meta' => ['current_page' => 1, 'last_page' => 2]])
        ->push(['data' => [['id' => 2, 'name' => 'yes'], ['id' => 3, 'name' => 'yes'], ['id' => 4, 'name' => 'yes']], 'meta' => ['current_page' => 2, 'last_page' => 2]]);
    $runtime = liveReadRuntimeContext(liveCollectionMapping(['type' => 'page', 'parameter' => 'page', 'current_path' => 'meta.current_page', 'last_path' => 'meta.last_page']));
    $runtime->operation->update(['request_mapping' => ['path' => [], 'query' => [], 'body' => []]]);

    $result = app(RuntimeApiOperationExecutor::class)->executeLiveRead($runtime, liveReadPlan($runtime, ['filters' => [['field' => 'name', 'operator' => 'eq', 'value' => 'yes']], 'result_count' => ['mode' => 'exact', 'value' => 3]]));

    expect($result->success)->toBeTrue()->and($result->data['records'])->toHaveCount(3)->and($result->data['meta']['pages_fetched'])->toBe(2);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === 'https://live.example.test/records?page=2');
});

test('applies a local price filter before the final result limit', function () {
    Http::fake(['https://live.example.test/*' => Http::response(['data' => [
        ['id' => 1, 'name' => 'Camry A', 'price' => '160.00'],
        ['id' => 2, 'name' => 'Camry B', 'price' => '220.00'],
        ['id' => 3, 'name' => 'Camry C', 'price' => '45.00'],
    ]])]);
    $runtime = liveReadRuntimeContext(liveCollectionMapping());
    $runtime->operation->update(['request_mapping' => [
        'path' => [],
        'query' => [],
        'body' => [],
        'live_query' => ['search_text' => 'name'],
    ]]);

    $result = app(RuntimeApiOperationExecutor::class)->executeLiveRead($runtime, liveReadPlan($runtime, [
        'text' => 'Camry',
        'filters' => [['field' => 'price', 'operator' => 'lte', 'value' => 200]],
        'result_count' => ['mode' => 'exact', 'value' => 2],
    ]));

    expect($result->success)->toBeTrue()
        ->and(array_column($result->data['records'], 'id'))->toBe([1, 3])
        ->and($result->data['meta']['matcher_input_count'])->toBe(3)
        ->and($result->data['meta']['matcher_output_count'])->toBe(2);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://live.example.test/records?name=Camry');
});

test('pushes a configured remote price filter without assuming its parameter name', function () {
    Http::fake(['https://live.example.test/*' => Http::response(['data' => [
        ['id' => 1, 'name' => 'Camry A', 'price' => '160.00'],
    ]])]);
    $runtime = liveReadRuntimeContext(liveCollectionMapping());
    $runtime->operation->update(['request_mapping' => [
        'path' => [],
        'query' => [],
        'body' => [],
        'live_query' => [
            'search_text' => 'name',
            'filters' => ['price' => ['lte' => 'max_amount']],
        ],
    ]]);

    $result = app(RuntimeApiOperationExecutor::class)->executeLiveRead($runtime, liveReadPlan($runtime, [
        'text' => 'Camry',
        'filters' => [['field' => 'price', 'operator' => 'lte', 'value' => 200]],
        'result_count' => ['mode' => 'exact', 'value' => 1],
    ]));

    expect($result->success)->toBeTrue()->and($result->data['meta']['local_filters'])->toBe([]);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'name=Camry')
        && str_contains($request->url(), 'max_amount=200'));
});

test('continues pagination until enough records remain after local filtering', function () {
    Http::fakeSequence('https://live.example.test/*')
        ->push(['data' => array_map(
            static fn (int $id): array => ['id' => $id, 'price' => $id === 1 ? 100 : 220],
            range(1, 10),
        ), 'meta' => ['current_page' => 1, 'last_page' => 2]])
        ->push(['data' => array_map(
            static fn (int $id): array => ['id' => $id, 'price' => $id < 15 ? 100 : 220],
            range(11, 20),
        ), 'meta' => ['current_page' => 2, 'last_page' => 2]]);
    $runtime = liveReadRuntimeContext(liveCollectionMapping([
        'type' => 'page',
        'parameter' => 'page',
        'current_path' => 'meta.current_page',
        'last_path' => 'meta.last_page',
    ]));

    $result = app(RuntimeApiOperationExecutor::class)->executeLiveRead($runtime, liveReadPlan($runtime, [
        'filters' => [['field' => 'price', 'operator' => 'lte', 'value' => 200]],
        'result_count' => ['mode' => 'exact', 'value' => 5],
    ]));

    expect($result->success)->toBeTrue()
        ->and($result->data['records'])->toHaveCount(5)
        ->and($result->data['meta']['pages_fetched'])->toBe(2)
        ->and($result->data['meta']['complete'])->toBeTrue();
    Http::assertSentCount(2);
});

test('follows a saved REST next URL and deduplicates records', function () {
    Http::fakeSequence('https://live.example.test/*')
        ->push(['data' => [['id' => 1], ['id' => 2]], 'next_page_url' => 'https://live.example.test/records?page=2'])
        ->push(['data' => [['id' => 2], ['id' => 3]]]);
    $runtime = liveReadRuntimeContext(liveCollectionMapping(['type' => 'next_url', 'next_path' => 'next_page_url']));

    $result = app(RuntimeApiOperationExecutor::class)->executeLiveRead($runtime, liveReadPlan($runtime, ['result_count' => ['mode' => 'all']]));

    expect($result->success)->toBeTrue()->and($result->data['records'])->toHaveCount(3)->and($result->data['meta']['confirmed_empty'])->toBeFalse();
    Http::assertSentCount(2);
});

test('globally sorts local results across all pages', function () {
    Http::fakeSequence('https://live.example.test/*')
        ->push(['data' => [['id' => 1, 'price' => 100], ['id' => 2, 'price' => 110], ['id' => 3, 'price' => 120]], 'meta' => ['current_page' => 1, 'last_page' => 2]])
        ->push(['data' => [['id' => 4, 'price' => 10], ['id' => 5, 'price' => 20], ['id' => 6, 'price' => 30]], 'meta' => ['current_page' => 2, 'last_page' => 2]]);
    $runtime = liveReadRuntimeContext(liveCollectionMapping(['type' => 'page', 'parameter' => 'page', 'current_path' => 'meta.current_page', 'last_path' => 'meta.last_page']));

    $result = app(RuntimeApiOperationExecutor::class)->executeLiveRead($runtime, liveReadPlan($runtime, ['sorts' => [['field' => 'price', 'direction' => 'asc']], 'result_count' => ['mode' => 'exact', 'value' => 3]]));

    expect(array_column($result->data['records'], 'price'))->toBe([10, 20, 30])
        ->and($result->data['meta']['sort_mode'])->toBe('complete_local')
        ->and($result->data['meta']['global_sort_guaranteed'])->toBeTrue();
    Http::assertSentCount(2);
});

test('marks local sorting as bounded when the page budget prevents an exhaustive scan', function () {
    Config::set('live-read.max_pages', 1);
    Http::fake(['https://live.example.test/*' => Http::response([
        'data' => [
            ['id' => 1, 'price' => 220],
            ['id' => 2, 'price' => 45],
            ['id' => 3, 'price' => 160],
        ],
        'meta' => ['current_page' => 1, 'last_page' => 2],
    ])]);
    $runtime = liveReadRuntimeContext(liveCollectionMapping([
        'type' => 'page',
        'parameter' => 'page',
        'current_path' => 'meta.current_page',
        'last_path' => 'meta.last_page',
    ]));

    $result = app(RuntimeApiOperationExecutor::class)->executeLiveRead($runtime, liveReadPlan($runtime, [
        'sorts' => [['field' => 'price', 'direction' => 'asc']],
        'result_count' => ['mode' => 'exact', 'value' => 3],
    ]));

    expect(array_column($result->data['records'], 'price'))->toBe([45, 160, 220])
        ->and($result->data['meta']['sort_mode'])->toBe('local_bounded')
        ->and($result->data['meta']['global_sort_guaranteed'])->toBeFalse()
        ->and($result->data['meta']['sort_complete'])->toBeFalse();
});

test('distinguishes confirmed empty from incomplete empty and preserves partial failures', function () {
    Http::fakeSequence('https://live.example.test/*')
        ->push(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 2]])
        ->push(['data' => [], 'meta' => ['current_page' => 2, 'last_page' => 2]])
        ->push(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 2]]);
    $runtime = liveReadRuntimeContext(liveCollectionMapping(['type' => 'page', 'parameter' => 'page', 'current_path' => 'meta.current_page', 'last_path' => 'meta.last_page']));
    $empty = app(RuntimeApiOperationExecutor::class)->executeLiveRead($runtime, liveReadPlan($runtime, ['result_count' => ['mode' => 'exact', 'value' => 1]]));
    expect($empty->data['meta']['confirmed_empty'])->toBeTrue()->and($empty->data['meta']['complete'])->toBeTrue();

    Config::set('live-read.max_pages', 1);
    $incomplete = app(RuntimeApiOperationExecutor::class)->executeLiveRead($runtime, liveReadPlan($runtime, ['result_count' => ['mode' => 'exact', 'value' => 1]]));
    expect($incomplete->data['meta']['confirmed_empty'])->toBeFalse()->and($incomplete->data['meta']['truncated'])->toBeTrue();
});

test('returns partial results when a later page fails', function () {
    Http::fakeSequence('https://live.example.test/*')
        ->push(['data' => [['id' => 1, 'name' => 'first']], 'meta' => ['current_page' => 1, 'last_page' => 2]])
        ->push(['error' => 'temporary failure'], 503);
    $runtime = liveReadRuntimeContext(liveCollectionMapping([
        'type' => 'page',
        'parameter' => 'page',
        'current_path' => 'meta.current_page',
        'last_path' => 'meta.last_page',
    ]));

    $result = app(RuntimeApiOperationExecutor::class)->executeLiveRead(
        $runtime,
        liveReadPlan($runtime, ['result_count' => ['mode' => 'exact', 'value' => 2]]),
    );

    expect($result->success)->toBeTrue()
        ->and($result->data['records'])->toHaveCount(1)
        ->and($result->data['meta']['truncated'])->toBeTrue()
        ->and($result->data['meta']['complete'])->toBeFalse();
});

test('supports dynamic typed non-product fields and enforces the candidate budget', function () {
    Config::set('live-read.max_candidates', 2);
    Http::fake(['https://live.example.test/*' => Http::response(['data' => [
        ['id' => 1, 'capacity' => 8.5, 'region_code' => 'TB', 'activation_date' => '2026-02-01'],
        ['id' => 2, 'capacity' => 6, 'region_code' => 'TB', 'activation_date' => '2025-01-01'],
        ['id' => 3, 'capacity' => 9, 'region_code' => 'TB'],
    ]])]);
    $runtime = liveReadRuntimeContext(liveCollectionMapping());
    $result = app(RuntimeApiOperationExecutor::class)->executeLiveRead($runtime, liveReadPlan($runtime, ['filters' => [
        ['field' => 'capacity', 'operator' => 'gte', 'value' => 7.5],
        ['field' => 'activation_date', 'operator' => 'gte', 'value' => '2026-01-01'],
        ['field' => 'region_code', 'operator' => 'contains', 'value' => 'TB'],
    ]]));

    expect($result->data['records'])->toHaveCount(1)->and($result->data['meta']['candidates_examined'])->toBe(2)->and($result->data['meta']['truncated'])->toBeTrue();
});

test('advances the saved GraphQL relay cursor', function () {
    $mapping = liveCollectionMapping([
        'type' => 'relay_cursor',
        'cursor_variable' => 'after',
        'has_next_path' => 'products.pageInfo.hasNextPage',
        'cursor_path' => 'products.pageInfo.endCursor',
    ]);
    $mapping['collection'] = [
        'path' => 'products.nodes',
        'fields' => [
            'id' => ['path' => 'id', 'type' => 'integer'],
        ],
    ];
    $runtime = liveReadRuntimeContext($mapping);
    $runtime->dataSource->update([
        'type' => 'graphql_api',
        'config' => [
            'endpoint' => 'https://live.example.test/graphql',
            'default_variables' => ['after' => null],
        ],
    ]);
    $runtime->operation->update([
        'request_mapping' => [
            'graphql' => [
                'document' => 'query FindRecords($after: String) { products(after: $after) { nodes { id } pageInfo { hasNextPage endCursor } } }',
                'variables' => [
                    'after' => ['source' => 'tool_argument', 'argument' => 'after'],
                ],
            ],
        ],
    ]);
    Http::fake(['https://live.example.test/graphql' => function ($request) {
        $after = $request->data()['variables']['after'] ?? null;

        return Http::response(['data' => [
            'products' => [
                'nodes' => [['id' => $after === null ? 1 : 2]],
                'pageInfo' => [
                    'hasNextPage' => $after === null,
                    'endCursor' => $after === null ? 'cursor-1' : null,
                ],
            ],
        ]]);
    }]);
    $executor = app(RuntimeApiOperationExecutor::class);

    $result = $executor->executeLiveRead($runtime, liveReadPlan($runtime, ['result_count' => ['mode' => 'exact', 'value' => 2]]));

    expect($result->success)->toBeTrue()->and($result->data['records'])->toHaveCount(2);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => ($request->data()['variables']['after'] ?? null) === 'cursor-1');
});

test('rejects oversized live responses without claiming success', function () {
    Config::set('live-read.max_response_bytes', 10);
    Http::fake(['https://live.example.test/*' => Http::response(['data' => [['id' => 1, 'name' => 'too large']]], 200, ['Content-Type' => 'application/json', 'Content-Length' => '100'])]);
    $runtime = liveReadRuntimeContext(liveCollectionMapping());

    $result = app(RuntimeApiOperationExecutor::class)->executeLiveRead($runtime, liveReadPlan($runtime, ['result_count' => ['mode' => 'exact', 'value' => 1]]));

    expect($result->success)->toBeFalse()->and($result->error)->toBe('integration_error');
});
