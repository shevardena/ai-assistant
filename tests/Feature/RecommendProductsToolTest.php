<?php

use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\BotCardTemplate;
use App\Models\BotDataset;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiSearchOrchestrator;
use App\Services\Ai\AiToolSchemaBuilder;
use App\Services\Ai\BotToolRegistry;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\Tools\RecommendProductsTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Cards\ProductCardFormatter;

/**
 * @return array{0: User, 1: Team, 2: Bot, 3: Dataset, 4: list<DatasetField>, 5: list<DatasetRecord>}
 */
function recommendProductsContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $dataset = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'entity_type' => 'product',
        'slug' => 'products',
    ]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'is_enabled' => true,
    ]);

    $fields = [
        DatasetField::factory()->create([
            'dataset_id' => $dataset->id,
            'key' => 'name',
            'is_displayable' => true,
            'is_searchable' => true,
            'position' => 1,
        ]),
        DatasetField::factory()->create([
            'dataset_id' => $dataset->id,
            'key' => 'brand',
            'is_displayable' => true,
            'is_searchable' => true,
            'position' => 2,
        ]),
        DatasetField::factory()->create([
            'dataset_id' => $dataset->id,
            'key' => 'price',
            'is_displayable' => true,
            'position' => 3,
        ]),
        DatasetField::factory()->create([
            'dataset_id' => $dataset->id,
            'key' => 'internal_note',
            'is_displayable' => false,
            'position' => 4,
        ]),
    ];
    $records = [
        DatasetRecord::factory()->create([
            'dataset_id' => $dataset->id,
            'external_id' => 'sku-laptop-1',
            'payload' => [
                'name' => 'Video Laptop',
                'brand' => 'Mamos',
                'price' => 1599,
                'internal_note' => 'Do not expose.',
            ],
            'searchable_text' => 'Video Laptop Mamos 1599',
            'is_active' => true,
        ]),
        DatasetRecord::factory()->create([
            'dataset_id' => $dataset->id,
            'external_id' => 'sku-laptop-2',
            'payload' => [
                'name' => 'Creator Laptop',
                'brand' => 'Mamos',
                'price' => 1799,
                'internal_note' => 'Private margin note.',
            ],
            'searchable_text' => 'Creator Laptop Mamos 1799',
            'is_active' => true,
        ]),
    ];

    return [$user, $team, $bot, $dataset, $fields, $records];
}

function executeRecommendation(Bot $bot, array $arguments): ToolResult
{
    return app(RecommendProductsTool::class)->execute(
        $bot,
        $arguments,
        ToolExecutionContext::forBot($bot),
    );
}

test('the registry exposes recommendations for catalog datasets but not knowledge datasets', function () {
    [, , $bot] = recommendProductsContext();
    $registry = app(BotToolRegistry::class);

    expect($registry->find($bot, 'recommend_products'))->toBeInstanceOf(RecommendProductsTool::class);

    [, , $knowledgeBot] = lookupKnowledgeOnlyBot();

    expect($registry->find($knowledgeBot, 'recommend_products'))->toBeNull();
});

test('recommend_products has a strict bounded schema', function () {
    [, , $bot] = recommendProductsContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'recommend_products');
    $schema = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($schema)->toMatchArray([
        'type' => 'function',
        'name' => 'recommend_products',
        'strict' => true,
    ])
        ->and($schema['parameters']['required'])->toBe(['query', 'limit'])
        ->and($schema['parameters']['additionalProperties'])->toBeFalse()
        ->and($schema['parameters']['properties']['limit'])->toMatchArray([
            'type' => ['integer', 'null'],
            'minimum' => 1,
            'maximum' => 10,
        ]);
});

test('all catalog tools sent with recommend_products satisfy strict object requirements', function () {
    [, , $bot] = recommendProductsContext();

    foreach (app(BotToolRegistry::class)->forBot($bot) as $tool) {
        $definition = app(AiToolSchemaBuilder::class)->build($tool, $bot);

        expect($definition['strict'])->toBeTrue();
        assertStrictAiObjectSchema($definition['parameters'], $tool->name());
    }
});

test('recommend_products accepts a required null limit and applies the normal runtime default', function () {
    [, , $bot] = recommendProductsContext();

    $result = executeRecommendation($bot, [
        'query' => 'laptop',
        'limit' => null,
    ]);

    expect($result->data['recommendations'])->toHaveCount(2);
});

test('recommend_products returns real authorized candidates with safe references and fields', function () {
    [, , $bot, $dataset, , $records] = recommendProductsContext();

    $result = executeRecommendation($bot, [
        'query' => 'laptop',
        'limit' => 2,
    ]);
    $recommendations = $result->data['recommendations'];

    expect($recommendations)->toHaveCount(2)
        ->and(collect($recommendations)->pluck('product_reference')->all())
        ->toContain('sku-laptop-1', 'sku-laptop-2')
        ->and($recommendations[0])->not->toHaveKey('internal_note')
        ->and($recommendations[0])->not->toHaveKey('id')
        ->and($recommendations[0])->not->toHaveKey('dataset_id')
        ->and($result->metadata['card_source'])->toMatchArray([
            'dataset_id' => $dataset->id,
            'record_ids' => [$records[1]->id, $records[0]->id],
        ]);
});

test('recommendation candidates produce cards through the existing product card flow', function () {
    [, , $bot, $dataset, $fields] = recommendProductsContext();
    BotCardTemplate::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'mapping' => [
            'title' => $fields[0]->id,
            'subtitle' => $fields[1]->id,
            'price' => $fields[2]->id,
        ],
    ]);

    $result = executeRecommendation($bot, ['query' => 'laptop', 'limit' => 1]);
    $cards = app(ProductCardFormatter::class)->formatSearchSources($bot, $result->metadata['card_sources']);

    expect($cards)->toHaveCount(1)
        ->and($cards[0])->toMatchArray([
            'id' => 'sku-laptop-2',
            'title' => 'Creator Laptop',
            'price' => 1799,
        ]);
});

test('recommend_products enforces team, bot, attachment, readiness, and active-record boundaries', function () {
    [, $team, $bot, $dataset, , $records] = recommendProductsContext();

    $unattachedDataset = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'entity_type' => 'product',
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $unattachedDataset->id,
        'key' => 'name',
        'is_displayable' => true,
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $unattachedDataset->id,
        'payload' => ['name' => 'Unattached Product'],
        'searchable_text' => 'laptop unattached',
        'is_active' => true,
    ]);

    $foreignTeam = Team::factory()->create();
    $foreignDataset = Dataset::factory()->ready()->create([
        'team_id' => $foreignTeam->id,
        'entity_type' => 'product',
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $foreignDataset->id,
        'key' => 'name',
        'is_displayable' => true,
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $foreignDataset->id,
        'payload' => ['name' => 'Foreign Product'],
        'searchable_text' => 'laptop foreign',
        'is_active' => true,
    ]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $foreignDataset->id,
        'is_enabled' => true,
    ]);

    $records[0]->update(['is_active' => false]);

    expect(executeRecommendation($bot, ['query' => 'laptop'])->data['recommendations'])
        ->toHaveCount(1)
        ->and(executeRecommendation($bot, ['query' => 'laptop'])->data['recommendations'][0]['product_reference'])
        ->toBe('sku-laptop-2');

    $attachment = BotDataset::query()
        ->where('bot_id', $bot->id)
        ->where('dataset_id', $dataset->id)
        ->firstOrFail();
    $attachment->update(['is_enabled' => false]);

    expect(executeRecommendation($bot, ['query' => 'laptop'])->data['recommendations'])->toBe([]);

    $attachment->update(['is_enabled' => true]);
    $dataset->update(['status' => 'preparing']);

    expect(executeRecommendation($bot, ['query' => 'laptop'])->data['recommendations'])->toBe([]);
});

test('recommend_products rejects invalid limits and never fabricates a no-match result', function () {
    [, , $bot] = recommendProductsContext();

    foreach ([
        [],
        ['query' => ''],
        ['query' => 'laptop', 'limit' => 0],
        ['query' => 'laptop', 'limit' => 11],
        ['query' => 'laptop', 'limit' => '2'],
        ['query' => 'laptop', 'unexpected' => true],
    ] as $arguments) {
        expect(executeRecommendation($bot, $arguments)->data['error'])->toBe('invalid_recommendation');
    }

    expect(executeRecommendation($bot, ['query' => 'does-not-exist'])->data)->toBe([
        'ok' => true,
        'recommendations' => [],
    ]);
});

test('the generic runtime dispatches recommend_products without a tool-specific branch', function () {
    [, , $bot] = recommendProductsContext();
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
                        'call_id' => 'recommendation-call',
                        'name' => 'recommend_products',
                        'arguments' => json_encode(['query' => 'laptop', 'limit' => 1], JSON_THROW_ON_ERROR),
                    ]],
                    'output_text' => null,
                    'usage' => null,
                ]
                : [
                    'output' => [],
                    'output_text' => 'I recommend the Creator Laptop.',
                    'usage' => null,
                ];
        }
    };
    app()->instance(AiClient::class, $fake);

    $response = app(AiSearchOrchestrator::class)->run($bot, 'Recommend a laptop.');
    $functionOutput = collect($fake->payloads[1]['input'])
        ->first(fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output');

    expect($response->answer)->toBe('I recommend the Creator Laptop.')
        ->and($response->toolCallsCount)->toBe(1)
        ->and(collect($fake->payloads[0]['tools'])->contains(
            fn (array $tool): bool => ($tool['name'] ?? null) === 'recommend_products'
                && ($tool['strict'] ?? false) === true
                && ($tool['parameters']['required'] ?? []) === ['query', 'limit'],
        ))->toBeTrue()
        ->and(json_decode($functionOutput['output'], true, 512, JSON_THROW_ON_ERROR)['recommendations'][0]['product_reference'])
        ->toBe('sku-laptop-2');
});

/**
 * @return array{0: User, 1: Team, 2: Bot}
 */
function lookupKnowledgeOnlyBot(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $dataset = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'entity_type' => 'knowledge',
    ]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'is_enabled' => true,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'is_displayable' => true,
    ]);

    return [$user, $team, $bot];
}
