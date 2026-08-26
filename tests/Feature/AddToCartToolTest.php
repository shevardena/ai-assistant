<?php

use App\Enums\ApiOperationMode;
use App\Enums\ToolRunStatus;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\BotDataset;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\DataSource;
use App\Models\Message;
use App\Models\ToolRun;
use App\Models\User;
use App\Models\WidgetVisitor;
use App\Services\Ai\AiToolSchemaBuilder;
use App\Services\Ai\BotToolRegistry;
use App\Services\Ai\Tools\AddToCartTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Ai\WriteActionManager;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $operationOverrides
 * @param  array<string, mixed>  $settings
 * @return array{0: User, 1: Bot, 2: Dataset, 3: DatasetRecord, 4: ApiOperation, 5: BotApiOperation, 6: ToolExecutionContext}
 */
function addToCartContext(
    array $operationOverrides = [],
    array $settings = [],
    bool $withStock = false,
    array $stateMemory = [],
): array {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $dataSource = DataSource::factory()->ready()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://cart.example.test'],
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
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'sku',
        'is_displayable' => false,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'name',
        'is_displayable' => true,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'variant_code',
        'is_displayable' => false,
    ]);
    $record = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'product-1',
        'payload' => [
            'sku' => 'SKU-001',
            'name' => 'Gaming Laptop',
            'variant_code' => 'BLACK-42',
            'internal_note' => 'private',
        ],
        'is_active' => true,
    ]);

    $operation = ApiOperation::factory()->create([
        'data_source_id' => $dataSource->id,
        'key' => 'cart_add',
        'name' => 'Add to cart',
        'type' => 'action',
        'execution_mode' => ApiOperationMode::Write->value,
        'method' => 'POST',
        'path' => '/cart/items',
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string'],
                'quantity' => ['type' => 'integer'],
                'variant' => ['type' => 'string'],
                'color' => ['type' => 'string'],
                'cart_id' => ['type' => 'string'],
            ],
            'required' => ['sku', 'quantity'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'body' => [
                'sku' => 'item.sku',
                'quantity' => 'item.quantity',
                'variant' => 'item.variant',
                'color' => 'item.color',
                'cart_id' => 'cart.id',
            ],
            'idempotency_header' => 'Idempotency-Key',
        ],
        'response_mapping' => [
            'output' => [
                'cart_reference' => 'data.cart_reference',
                'cart_status' => 'data.status',
                'item_quantity' => ['path' => 'data.item_quantity', 'required' => false],
                'checkout_url' => ['path' => 'data.checkout_url', 'required' => false],
            ],
        ],
        ...$operationOverrides,
    ]);
    $defaultSettings = [
        'variant_dataset_field' => 'variant_code',
        'input_mapping' => [
            'product_reference' => [
                'source' => 'dataset_field',
                'dataset_field' => 'sku',
                'operation_argument' => 'sku',
            ],
            'quantity' => [
                'source' => 'model_input',
                'model_input' => 'quantity',
                'operation_argument' => 'quantity',
            ],
            'variant_reference' => [
                'source' => 'model_input',
                'model_input' => 'variant_reference',
                'operation_argument' => 'variant',
            ],
            'options.color' => [
                'source' => 'model_input',
                'model_input' => 'options.color',
                'operation_argument' => 'color',
            ],
            'cart_reference' => [
                'source' => 'context_value',
                'context_key' => 'cart_reference',
                'operation_argument' => 'cart_id',
            ],
        ],
    ];
    $attachment = BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'add_to_cart',
        'is_enabled' => true,
        'settings' => $settings ?: $defaultSettings,
    ]);

    if ($withStock) {
        $stockOperation = ApiOperation::factory()->create([
            'data_source_id' => $dataSource->id,
            'key' => 'stock_lookup',
            'name' => 'Stock lookup',
            'type' => 'query',
            'execution_mode' => ApiOperationMode::Read->value,
            'method' => 'GET',
            'path' => '/stock/{sku}',
            'request_schema' => [
                'type' => 'object',
                'properties' => ['sku' => ['type' => 'string']],
                'required' => ['sku'],
                'additionalProperties' => false,
            ],
            'request_mapping' => ['path' => ['sku' => 'sku']],
            'response_mapping' => [
                'output' => ['available' => 'data.available'],
            ],
        ]);
        BotApiOperation::factory()->create([
            'bot_id' => $bot->id,
            'api_operation_id' => $stockOperation->id,
            'tool_name' => 'check_stock',
            'is_enabled' => true,
            'settings' => [
                'input_mapping' => [
                    'product_reference' => [
                        'source' => 'dataset_field',
                        'dataset_field' => 'sku',
                        'operation_argument' => 'sku',
                    ],
                ],
            ],
        ]);
    }

    $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
    ]);

    if ($stateMemory !== []) {
        ConversationState::factory()->create([
            'conversation_id' => $conversation->id,
            'memory' => $stateMemory,
        ]);
    }

    return [
        $user,
        $bot,
        $dataset,
        $record,
        $operation,
        $attachment,
        ToolExecutionContext::forBot($bot, $conversation, $message),
    ];
}

/**
 * @param  array<string, mixed>  $arguments
 */
function executeAddToCart(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
{
    return app(AddToCartTool::class)->execute($bot, $arguments, $context);
}

test('add_to_cart exposes a strict bounded schema and only a valid write integration', function () {
    [, $bot] = addToCartContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'add_to_cart');
    $schema = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($tool)->toBeInstanceOf(AddToCartTool::class)
        ->and($schema['strict'])->toBeTrue()
        ->and($schema['parameters']['required'])->toBe(['product_reference'])
        ->and($schema['parameters']['additionalProperties'])->toBeFalse()
        ->and($schema['parameters']['properties']['quantity'])->toMatchArray([
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 100,
        ])
        ->and($schema['parameters']['properties']['options']['additionalProperties'])->toBeFalse();
});

test('registry does not expose add_to_cart without a valid enabled write mapping', function () {
    [, $bot, , , $operation, $attachment] = addToCartContext();

    $registry = app(BotToolRegistry::class);
    expect($registry->find($bot, 'add_to_cart'))->toBeInstanceOf(AddToCartTool::class);

    $operation->update(['execution_mode' => ApiOperationMode::Read->value]);
    expect($registry->find($bot, 'add_to_cart'))->toBeNull();

    $operation->update(['execution_mode' => ApiOperationMode::Write->value]);
    $attachment->update(['is_enabled' => false]);
    expect($registry->find($bot, 'add_to_cart'))->toBeNull();

    $attachment->update([
        'is_enabled' => true,
        'settings' => ['input_mapping' => [
            'product_reference' => [
                'source' => 'model_input',
                'model_input' => 'product_reference',
                'operation_argument' => 'sku',
            ],
            'quantity' => [
                'source' => 'model_input',
                'model_input' => 'quantity',
                'operation_argument' => 'quantity',
            ],
        ]],
    ]);
    expect($registry->find($bot, 'add_to_cart'))->toBeNull();
});

test('valid product mapping proposes without writing and maps quantity variant and flat options', function () {
    Http::preventStrayRequests();
    [, $bot, , , , , $context] = addToCartContext();

    $result = executeAddToCart($bot, [
        'product_reference' => 'product-1',
        'quantity' => 2,
        'variant_reference' => 'BLACK-42',
        'options' => ['color' => 'black'],
    ], $context);
    $run = ToolRun::query()->firstOrFail();

    expect($result->data['requires_confirmation'])->toBeTrue()
        ->and($result->data['summary'])->toContain('Gaming Laptop')
        ->and($run->status)->toBe(ToolRunStatus::PendingConfirmation)
        ->and($run->safe_arguments)->toMatchArray([
            'sku' => 'SKU-001',
            'quantity' => 2,
            'variant' => 'BLACK-42',
            'color' => 'black',
        ]);
});

test('invalid quantities options and unknown products are rejected without a proposal', function () {
    Http::preventStrayRequests();
    [, $bot, , , , , $context] = addToCartContext();

    foreach ([
        ['product_reference' => 'product-1', 'quantity' => 0],
        ['product_reference' => 'product-1', 'quantity' => 101],
        ['product_reference' => 'product-1', 'quantity' => 1.5],
        ['product_reference' => 'product-1', 'variant_reference' => 'WHITE-99'],
        ['product_reference' => 'product-1', 'options' => ['color' => ['nested']]],
        ['product_reference' => 'product-1', 'options' => ['unknown' => 'value']],
        ['product_reference' => 'missing-product'],
    ] as $arguments) {
        $result = executeAddToCart($bot, $arguments, $context);

        expect($result->data['ok'])->toBeFalse();
    }

    expect(ToolRun::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('foreign, unattached, and inactive catalog records cannot be added', function () {
    Http::preventStrayRequests();
    [, $bot, $dataset, $record, , , $context] = addToCartContext();

    $foreignUser = User::factory()->create();
    $foreignDataset = Dataset::factory()->ready()->create([
        'team_id' => $foreignUser->currentTeam->id,
        'entity_type' => 'product',
    ]);
    DatasetField::factory()->create(['dataset_id' => $foreignDataset->id, 'key' => 'sku']);
    DatasetRecord::factory()->create([
        'dataset_id' => $foreignDataset->id,
        'external_id' => 'foreign-product',
        'payload' => ['sku' => 'FOREIGN-1'],
    ]);

    $unattached = Dataset::factory()->ready()->create([
        'team_id' => $dataset->team_id,
        'entity_type' => 'product',
    ]);
    DatasetField::factory()->create(['dataset_id' => $unattached->id, 'key' => 'sku']);
    DatasetRecord::factory()->create([
        'dataset_id' => $unattached->id,
        'external_id' => 'unattached-product',
        'payload' => ['sku' => 'UNATTACHED-1'],
    ]);

    foreach (['foreign-product', 'unattached-product'] as $reference) {
        expect(executeAddToCart($bot, ['product_reference' => $reference], $context)->data['error'])
            ->toBe('not_found');
    }

    $record->update(['is_active' => false]);
    expect(executeAddToCart($bot, ['product_reference' => 'product-1'], $context)->data['error'])
        ->toBe('not_found');

    expect(ToolRun::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('confirmed add_to_cart writes once, persists only a server cart reference, and returns mapped safe output', function () {
    Http::fake([
        'https://cart.example.test/*' => Http::response([
            'data' => [
                'cart_reference' => 'cart-opaque-1',
                'status' => 'updated',
                'item_quantity' => 2,
                'checkout_url' => 'https://shop.example.test/checkout/cart-opaque-1',
                'internal_note' => 'private',
            ],
        ], 201),
    ]);
    [, $bot, , , , , $context] = addToCartContext();
    $tool = app(AddToCartTool::class);
    $proposal = $tool->execute($bot, [
        'product_reference' => 'product-1',
        'quantity' => 2,
    ], $context);

    $result = $tool->confirm($bot, $context, $proposal->data['action_reference']);
    $run = ToolRun::query()->firstOrFail();
    $state = $context->conversation->state()->firstOrFail();

    expect($result->data)->toMatchArray([
        'ok' => true,
        'status' => 'completed',
        'result' => [
            'cart_status' => 'updated',
            'item_quantity' => 2,
            'checkout_url' => 'https://shop.example.test/checkout/cart-opaque-1',
        ],
    ])
        ->and($run->status)->toBe(ToolRunStatus::Completed)
        ->and($run->safe_result)->not->toHaveKey('cart_reference')
        ->and($run->safe_result)->not->toHaveKey('internal_note')
        ->and($state->memory)->toMatchArray(['cart_reference' => 'cart-opaque-1']);

    Http::assertSent(function ($request) use ($run): bool {
        return $request->data() === [
            'item' => [
                'sku' => 'SKU-001',
                'quantity' => 2,
            ],
        ]
            && $request->header('Idempotency-Key') === [$run->idempotency_key];
    });
    Http::assertSentCount(1);
});

test('existing cart state is mapped internally and visitor or conversation scope cannot be crossed', function () {
    Http::preventStrayRequests();
    [, $bot, , , , , $context] = addToCartContext(stateMemory: ['cart_reference' => 'cart-opaque-2']);

    $proposal = executeAddToCart($bot, ['product_reference' => 'product-1'], $context);
    expect(ToolRun::query()->firstOrFail()->safe_arguments)->toMatchArray([
        'sku' => 'SKU-001',
        'quantity' => 1,
        'cart_id' => 'cart-opaque-2',
    ]);

    $foreignUser = User::factory()->create();
    $foreignBot = Bot::factory()->create(['team_id' => $foreignUser->currentTeam->id]);
    $foreignConversation = Conversation::factory()->create(['bot_id' => $foreignBot->id]);
    $foreignMessage = Message::factory()->create([
        'conversation_id' => $foreignConversation->id,
        'role' => 'user',
    ]);
    $foreignContext = ToolExecutionContext::forBot($foreignBot, $foreignConversation, $foreignMessage);

    expect($proposal->data['requires_confirmation'])->toBeTrue()
        ->and(executeAddToCart($bot, ['product_reference' => 'product-1'], $foreignContext)->data['error'])
        ->toBe('action_not_available');
});

test('a visitor cannot confirm another visitor cart action on the same bot', function () {
    Http::preventStrayRequests();
    [, $bot, , , , , $originalContext] = addToCartContext();
    $visitorA = WidgetVisitor::factory()->create(['bot_id' => $bot->id]);
    $visitorB = WidgetVisitor::factory()->create(['bot_id' => $bot->id]);
    $conversationA = $originalContext->conversation;
    $conversationA->update(['visitor_id' => $visitorA->id]);
    $conversationB = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $visitorB->id,
    ]);
    $messageA = Message::factory()->create([
        'conversation_id' => $conversationA->id,
        'role' => 'user',
    ]);
    $messageB = Message::factory()->create([
        'conversation_id' => $conversationB->id,
        'role' => 'user',
    ]);
    ConversationState::factory()->create([
        'conversation_id' => $conversationA->id,
        'memory' => ['cart_reference' => 'visitor-a-cart'],
    ]);
    ConversationState::factory()->create([
        'conversation_id' => $conversationB->id,
        'memory' => ['cart_reference' => 'visitor-b-cart'],
    ]);
    $contextA = ToolExecutionContext::forBot($bot, $conversationA->fresh(), $messageA);
    $contextB = ToolExecutionContext::forBot($bot, $conversationB, $messageB);
    $tool = app(AddToCartTool::class);
    $proposal = $tool->execute($bot, ['product_reference' => 'product-1'], $contextA);

    expect($proposal->data['requires_confirmation'])->toBeTrue()
        ->and($tool->confirm($bot, $contextB, $proposal->data['action_reference'])->data['error'])
        ->toBe('action_not_available');
});

test('known unavailable stock prevents proposal and stale stock prevents the confirmed write', function () {
    Http::fakeSequence('https://cart.example.test/*')
        ->push(['data' => ['available' => true]])
        ->push(['data' => ['available' => false]])
        ->push(['data' => ['available' => false]]);
    [, $bot, , , , , $context] = addToCartContext(withStock: true);
    $tool = app(AddToCartTool::class);

    $proposal = $tool->execute($bot, ['product_reference' => 'product-1'], $context);
    $stale = $tool->confirm($bot, $context, $proposal->data['action_reference']);

    expect($stale->data)->toMatchArray(['ok' => false, 'error' => 'out_of_stock'])
        ->and(ToolRun::query()->firstOrFail()->status)->toBe(ToolRunStatus::Failed);
    Http::assertSentCount(2);

    $blocked = $tool->execute($bot, ['product_reference' => 'product-1'], $context);

    expect($blocked->data)->toMatchArray(['ok' => false, 'error' => 'out_of_stock'])
        ->and(ToolRun::query()->count())->toBe(1);
});

test('no stock operation leaves the cart API authoritative and duplicate confirmation is idempotent', function () {
    Http::fake([
        'https://cart.example.test/*' => Http::response([
            'data' => ['cart_reference' => 'cart-opaque-3', 'status' => 'updated'],
        ], 201),
    ]);
    [, $bot, , , , , $context] = addToCartContext();
    $tool = app(AddToCartTool::class);
    $proposal = $tool->execute($bot, ['product_reference' => 'product-1'], $context);
    $first = $tool->confirm($bot, $context, $proposal->data['action_reference']);
    $second = $tool->confirm($bot, $context, $proposal->data['action_reference']);

    expect($second->data)->toBe($first->data);
    Http::assertSentCount(1);

    $newProposal = $tool->execute($bot, ['product_reference' => 'product-1'], $context);
    app(WriteActionManager::class)->cancel(
        $bot,
        $context,
        $newProposal->data['action_reference'],
    );
    $cancelled = $tool->confirm($bot, $context, $newProposal->data['action_reference']);

    expect($cancelled->data['error'])->toBe('action_cancelled')
        ->and(ToolRun::query()->where('status', ToolRunStatus::Cancelled)->count())->toBe(1);
    Http::assertSentCount(1);
});
