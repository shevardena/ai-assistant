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
use App\Services\Ai\Tools\CompareProductsTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Cards\ProductCardFormatter;
use App\Services\Conversations\Blocks\ComparisonBlock;

/**
 * @return array{0: User, 1: Team, 2: Bot, 3: Dataset, 4: list<DatasetField>, 5: list<DatasetRecord>}
 */
function compareProductsContext(): array
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
            'position' => 1,
        ]),
        DatasetField::factory()->create([
            'dataset_id' => $dataset->id,
            'key' => 'brand',
            'is_displayable' => true,
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
            'external_id' => 'sku-1',
            'payload' => [
                'name' => 'Laptop A',
                'brand' => 'Mamos',
                'price' => 1299,
                'internal_note' => 'Private note A.',
            ],
            'searchable_text' => 'Laptop A Mamos 1299',
            'is_active' => true,
        ]),
        DatasetRecord::factory()->create([
            'dataset_id' => $dataset->id,
            'external_id' => 'sku-2',
            'payload' => [
                'name' => 'Laptop B',
                'brand' => 'Mamos',
                'price' => 1599,
                'internal_note' => 'Private note B.',
            ],
            'searchable_text' => 'Laptop B Mamos 1599',
            'is_active' => true,
        ]),
        DatasetRecord::factory()->create([
            'dataset_id' => $dataset->id,
            'external_id' => 'sku-3',
            'payload' => [
                'name' => 'Laptop C',
                'brand' => 'Mamos',
                'price' => 1799,
                'internal_note' => 'Private note C.',
            ],
            'searchable_text' => 'Laptop C Mamos 1799',
            'is_active' => true,
        ]),
    ];

    return [$user, $team, $bot, $dataset, $fields, $records];
}

function executeComparison(Bot $bot, array $arguments): ToolResult
{
    return app(CompareProductsTool::class)->execute(
        $bot,
        $arguments,
        ToolExecutionContext::forBot($bot),
    );
}

test('the registry exposes comparison for catalog datasets but not knowledge datasets', function () {
    [, , $bot] = compareProductsContext();
    $registry = app(BotToolRegistry::class);

    expect($registry->find($bot, 'compare_products'))->toBeInstanceOf(CompareProductsTool::class);

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $knowledgeBot = Bot::factory()->create(['team_id' => $team->id]);
    $knowledgeDataset = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'entity_type' => 'knowledge',
    ]);
    BotDataset::factory()->create([
        'bot_id' => $knowledgeBot->id,
        'dataset_id' => $knowledgeDataset->id,
        'is_enabled' => true,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $knowledgeDataset->id,
        'is_displayable' => true,
    ]);

    expect($registry->find($knowledgeBot, 'compare_products'))->toBeNull();
});

test('compare_products has a strict bounded reference schema', function () {
    [, , $bot] = compareProductsContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'compare_products');
    $schema = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($schema)->toMatchArray([
        'type' => 'function',
        'name' => 'compare_products',
        'strict' => true,
    ])
        ->and($schema['parameters']['required'])->toBe(['product_references'])
        ->and($schema['parameters']['additionalProperties'])->toBeFalse()
        ->and($schema['parameters']['properties']['product_references'])->toMatchArray([
            'type' => 'array',
            'minItems' => 2,
            'maxItems' => 5,
        ]);
});

test('compare_products returns safe displayable data for authorized products', function () {
    [, , $bot] = compareProductsContext();

    $result = executeComparison($bot, [
        'product_references' => ['sku-1', 'sku-2'],
    ]);

    expect($result->data)->toBe([
        'ok' => true,
        'products' => [
            [
                'product_reference' => 'sku-1',
                'fields' => [
                    'name' => 'Laptop A',
                    'brand' => 'Mamos',
                    'price' => 1299,
                ],
            ],
            [
                'product_reference' => 'sku-2',
                'fields' => [
                    'name' => 'Laptop B',
                    'brand' => 'Mamos',
                    'price' => 1599,
                ],
            ],
        ],
    ])
        ->and($result->data['products'][0]['fields'])->not->toHaveKey('internal_note')
        ->and($result->data['products'][0]['fields'])->not->toHaveKey('id')
        ->and($result->blocks)->toMatchArray([[
            'type' => 'comparison',
            'data' => [
                'items' => [
                    ['product_reference' => 'sku-1', 'label' => 'Laptop A'],
                    ['product_reference' => 'sku-2', 'label' => 'Laptop B'],
                ],
                'fields' => [
                    ['key' => 'name', 'label' => 'Name', 'values' => ['Laptop A', 'Laptop B']],
                    ['key' => 'brand', 'label' => 'Brand', 'values' => ['Mamos', 'Mamos']],
                    ['key' => 'price', 'label' => 'Price', 'values' => [1299, 1599]],
                ],
            ],
        ]])
        ->and($result->blocks[0]['data']['items'][0])->not->toHaveKey('id')
        ->and($result->blocks[0]['data']['fields'][0])->not->toHaveKey('source_path')
        ->and($result->metadata)->toBe([]);
});

test('compare_products supports three products and the existing ProductCard flow', function () {
    [, , $bot, $dataset, $fields, $records] = compareProductsContext();
    BotCardTemplate::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'mapping' => [
            'title' => $fields[0]->id,
            'subtitle' => $fields[1]->id,
            'price' => $fields[2]->id,
        ],
    ]);

    $result = executeComparison($bot, [
        'product_references' => ['sku-1', 'sku-2', 'sku-3'],
    ]);
    $cards = app(ProductCardFormatter::class)->formatSearchSources($bot, $result->metadata['card_sources']);

    expect($result->data['products'])->toHaveCount(3)
        ->and($result->metadata['card_source'])->toMatchArray([
            'dataset_id' => $dataset->id,
            'record_ids' => [$records[0]->id, $records[1]->id, $records[2]->id],
        ])
        ->and($cards)->toHaveCount(3)
        ->and(collect($cards)->pluck('id')->all())->toBe(['sku-1', 'sku-2', 'sku-3']);
});

test('comparison blocks safely union displayable fields across datasets', function () {
    [, $team, $bot, $dataset] = compareProductsContext();
    $otherDataset = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'entity_type' => 'product',
    ]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $otherDataset->id,
        'is_enabled' => true,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $otherDataset->id,
        'key' => 'name',
        'canonical_name' => 'name',
        'label' => 'Product name',
        'semantic_type' => 'name',
        'is_displayable' => true,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $otherDataset->id,
        'key' => 'stock',
        'label' => 'In stock',
        'is_displayable' => true,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $otherDataset->id,
        'key' => 'private_cost',
        'label' => 'Private cost',
        'is_displayable' => false,
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $otherDataset->id,
        'external_id' => 'sku-other',
        'payload' => [
            'name' => 'Laptop Other',
            'stock' => true,
            'private_cost' => 500,
        ],
        'is_active' => true,
    ]);

    $result = executeComparison($bot, [
        'product_references' => ['sku-1', 'sku-other'],
    ]);
    $fields = collect($result->blocks[0]['data']['fields'])->keyBy('key');

    expect($result->blocks[0]['data']['items'])->toBe([
        ['product_reference' => 'sku-1', 'label' => 'Laptop A'],
        ['product_reference' => 'sku-other', 'label' => 'Laptop Other'],
    ])
        ->and($fields->get('brand')['values'])->toBe(['Mamos', null])
        ->and($fields->get('stock')['values'])->toBe([null, true])
        ->and($fields->has('private_cost'))->toBeFalse();
});

test('comparison blocks cap rows deterministically', function () {
    [, , $bot, $dataset, , $records] = compareProductsContext();
    $payload = [];

    for ($index = 0; $index < ComparisonBlock::MAX_FIELDS + 6; $index++) {
        $key = 'comparison-'.$index;
        DatasetField::factory()->create([
            'dataset_id' => $dataset->id,
            'key' => $key,
            'label' => 'Comparison '.$index,
            'position' => 10 + $index,
            'is_displayable' => true,
        ]);
        $payload[$key] = 'value-'.$index;
    }

    foreach (array_slice($records, 0, 2) as $record) {
        $record->update(['payload' => [...$record->payload, ...$payload]]);
    }

    $result = executeComparison($bot, [
        'product_references' => ['sku-1', 'sku-2'],
    ]);

    expect($result->blocks[0]['data']['fields'])->toHaveCount(ComparisonBlock::MAX_FIELDS)
        ->and($result->blocks[0]['data']['fields'][0]['key'])->toBe('name');
});

test('compare_products uses atomic failure for foreign, unattached, disabled, non-ready, or inactive records', function () {
    [, $team, $bot, $dataset, , $records] = compareProductsContext();
    $foreignTeam = Team::factory()->create();
    $foreignDataset = Dataset::factory()->ready()->create([
        'team_id' => $foreignTeam->id,
        'entity_type' => 'product',
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $foreignDataset->id,
        'is_displayable' => true,
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $foreignDataset->id,
        'external_id' => 'foreign-sku',
        'payload' => ['name' => 'Foreign Product'],
        'is_active' => true,
    ]);

    $unattachedDataset = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'entity_type' => 'product',
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $unattachedDataset->id,
        'is_displayable' => true,
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $unattachedDataset->id,
        'external_id' => 'unattached-sku',
        'payload' => ['name' => 'Unattached Product'],
        'is_active' => true,
    ]);

    foreach (['foreign-sku', 'unattached-sku'] as $reference) {
        expect(executeComparison($bot, ['product_references' => ['sku-1', $reference]])->data)
            ->toMatchArray([
                'ok' => false,
                'error' => 'products_not_found',
            ]);
    }

    $attachment = BotDataset::query()
        ->where('bot_id', $bot->id)
        ->where('dataset_id', $dataset->id)
        ->firstOrFail();
    $attachment->update(['is_enabled' => false]);

    expect(executeComparison($bot, ['product_references' => ['sku-1', 'sku-2']])->data['error'])
        ->toBe('products_not_found');

    $attachment->update(['is_enabled' => true]);
    $dataset->update(['status' => 'preparing']);

    expect(executeComparison($bot, ['product_references' => ['sku-1', 'sku-2']])->data['error'])
        ->toBe('products_not_found');

    $dataset->update(['status' => 'ready']);
    $records[0]->update(['is_active' => false]);

    expect(executeComparison($bot, ['product_references' => ['sku-1', 'sku-2']])->data['error'])
        ->toBe('products_not_found');
});

test('compare_products rejects malformed and duplicate references', function () {
    [, , $bot] = compareProductsContext();

    foreach ([
        [],
        ['product_references' => []],
        ['product_references' => ['sku-1']],
        ['product_references' => ['sku-1', 'sku-2', 'sku-3', 'sku-4', 'sku-5', 'sku-6']],
        ['product_references' => ['sku-1', 'sku-1']],
        ['product_references' => ['sku-1', '']],
        ['product_references' => ['sku-1', 2]],
        ['product_references' => ['sku-1', 'sku-2'], 'unexpected' => true],
    ] as $arguments) {
        expect(executeComparison($bot, $arguments)->data)->toMatchArray([
            'ok' => false,
            'error' => 'invalid_comparison',
        ]);
    }
});

test('the generic runtime dispatches compare_products without a tool-specific branch', function () {
    [, , $bot] = compareProductsContext();
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
                        'call_id' => 'comparison-call',
                        'name' => 'compare_products',
                        'arguments' => json_encode([
                            'product_references' => ['sku-1', 'sku-2'],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                    'output_text' => null,
                    'usage' => null,
                ]
                : [
                    'output' => [],
                    'output_text' => 'Laptop B costs more than Laptop A.',
                    'usage' => null,
                ];
        }
    };
    app()->instance(AiClient::class, $fake);

    $response = app(AiSearchOrchestrator::class)->run($bot, 'Compare the two laptops.');
    $functionOutput = collect($fake->payloads[1]['input'])
        ->first(fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output');

    expect($response->answer)->toBe('Laptop B costs more than Laptop A.')
        ->and($response->toolCallsCount)->toBe(1)
        ->and($response->blocks[0]['type'])->toBe('comparison')
        ->and(json_decode($functionOutput['output'], true, 512, JSON_THROW_ON_ERROR)['products'])
        ->toHaveCount(2);
});
