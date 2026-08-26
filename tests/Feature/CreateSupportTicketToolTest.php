<?php

use App\Enums\ApiOperationMode;
use App\Enums\ToolRunStatus;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\BotDataset;
use App\Models\Conversation;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\DataSource;
use App\Models\Message;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Ai\AiToolSchemaBuilder;
use App\Services\Ai\BotToolRegistry;
use App\Services\Ai\Tools\CreateSupportTicketTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Ai\WriteActionManager;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $settings
 * @param  array<string, mixed>  $operationOverrides
 * @return array{0: User, 1: Bot, 2: ApiOperation, 3: BotApiOperation, 4: ToolExecutionContext, 5: Dataset|null, 6: DatasetRecord|null}
 */
function createSupportTicketContext(array $settings = [], array $operationOverrides = [], bool $withProduct = false): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $dataSource = DataSource::factory()->ready()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://support.example.test'],
    ]);
    $dataset = null;
    $record = null;

    if ($withProduct) {
        $dataset = Dataset::factory()->ready()->create([
            'team_id' => $team->id,
            'entity_type' => 'catalog',
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
        $record = DatasetRecord::factory()->create([
            'dataset_id' => $dataset->id,
            'external_id' => 'product-1',
            'payload' => [
                'sku' => 'SKU-001',
                'name' => 'Gaming Laptop',
            ],
            'is_active' => true,
        ]);
    }

    $operation = ApiOperation::factory()->create([
        'data_source_id' => $dataSource->id,
        'key' => 'support_ticket_create',
        'name' => 'Create support ticket',
        'type' => 'action',
        'execution_mode' => ApiOperationMode::Write->value,
        'method' => 'POST',
        'path' => '/tickets',
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'subject' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'priority' => ['type' => 'string'],
                'customer_name' => ['type' => 'string'],
                'customer_email' => ['type' => 'string'],
                'customer_phone' => ['type' => 'string'],
                'order_number' => ['type' => 'string'],
                'product_sku' => ['type' => 'string'],
            ],
            'required' => ['description', 'customer_email'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'body' => [
                'subject' => 'ticket.subject',
                'description' => 'ticket.description',
                'category' => 'ticket.category',
                'priority' => 'ticket.priority',
                'customer_name' => 'customer.name',
                'customer_email' => 'customer.email',
                'customer_phone' => 'customer.phone',
                'order_number' => 'order.number',
                'product_sku' => 'product.sku',
            ],
            'idempotency_header' => 'Idempotency-Key',
        ],
        'response_mapping' => [
            'output' => [
                'ticket_reference' => 'data.reference',
                'status' => 'data.status',
                'support_url' => ['path' => 'data.url', 'required' => false],
            ],
        ],
        ...$operationOverrides,
    ]);
    $attachment = BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'create_support_ticket',
        'is_enabled' => true,
        'settings' => $settings ?: [
            'input_mapping' => [
                'subject' => [
                    'source' => 'model_input',
                    'model_input' => 'subject',
                    'operation_argument' => 'subject',
                ],
                'description' => [
                    'source' => 'model_input',
                    'model_input' => 'description',
                    'operation_argument' => 'description',
                ],
                'category' => [
                    'source' => 'model_input',
                    'model_input' => 'category',
                    'operation_argument' => 'category',
                ],
                'priority' => [
                    'source' => 'model_input',
                    'model_input' => 'priority',
                    'operation_argument' => 'priority',
                ],
                'name' => [
                    'source' => 'model_input',
                    'model_input' => 'name',
                    'operation_argument' => 'customer_name',
                ],
                'email' => [
                    'source' => 'model_input',
                    'model_input' => 'email',
                    'operation_argument' => 'customer_email',
                ],
                'phone' => [
                    'source' => 'model_input',
                    'model_input' => 'phone',
                    'operation_argument' => 'customer_phone',
                ],
                'order_reference' => [
                    'source' => 'model_input',
                    'model_input' => 'order_reference',
                    'operation_argument' => 'order_number',
                ],
            ],
        ],
    ]);
    $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
    ]);

    return [
        $user,
        $bot,
        $operation,
        $attachment,
        ToolExecutionContext::forBot($bot, $conversation, $message),
        $dataset,
        $record,
    ];
}

/**
 * @param  array<string, mixed>  $arguments
 */
function executeCreateSupportTicket(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
{
    return app(CreateSupportTicketTool::class)->execute($bot, $arguments, $context);
}

test('create_support_ticket exposes a strict bounded schema with operation-driven required fields', function () {
    [, $bot] = createSupportTicketContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'create_support_ticket');
    $schema = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($tool)->toBeInstanceOf(CreateSupportTicketTool::class)
        ->and($schema)->toMatchArray([
            'type' => 'function',
            'name' => 'create_support_ticket',
            'strict' => true,
        ])
        ->and($schema['parameters']['required'])->toBe(['description', 'email'])
        ->and($schema['parameters']['additionalProperties'])->toBeFalse()
        ->and($schema['parameters']['properties'])->toHaveKeys([
            'subject',
            'description',
            'category',
            'priority',
            'name',
            'email',
            'phone',
            'order_reference',
            'product_reference',
        ]);
});

test('registry exposes create_support_ticket without requiring a catalog dataset', function () {
    [, $bot, , $attachment] = createSupportTicketContext();

    expect(app(BotToolRegistry::class)->find($bot, 'create_support_ticket'))
        ->toBeInstanceOf(CreateSupportTicketTool::class);

    $attachment->update(['is_enabled' => false]);
    expect(app(BotToolRegistry::class)->find($bot, 'create_support_ticket'))->toBeNull();
});

test('registry does not expose create_support_ticket without a valid enabled operation', function () {
    [, $bot, $operation] = createSupportTicketContext();
    $unconfiguredBot = Bot::factory()->create(['team_id' => $bot->team_id]);

    expect(app(BotToolRegistry::class)->find($unconfiguredBot, 'create_support_ticket'))->toBeNull();

    $operation->update(['is_enabled' => false]);
    expect(app(BotToolRegistry::class)->find($bot, 'create_support_ticket'))->toBeNull();
});

test('registry does not expose create_support_ticket for read operations', function () {
    [, $bot, $operation] = createSupportTicketContext();

    $operation->update(['execution_mode' => ApiOperationMode::Read->value]);

    expect(app(BotToolRegistry::class)->find($bot, 'create_support_ticket'))->toBeNull();
});

test('create_support_ticket rejects unexpected, malformed, oversized, and control-character input', function () {
    Http::preventStrayRequests();
    [, $bot, , , $context] = createSupportTicketContext();

    expect(executeCreateSupportTicket($bot, [
        'description' => 'The laptop will not turn on.',
        'email' => 'not-an-email',
    ], $context)->data)->toMatchArray(['ok' => false, 'error' => 'invalid_arguments'])
        ->and(executeCreateSupportTicket($bot, [
            'description' => str_repeat('x', 2001),
            'email' => 'jane@example.com',
        ], $context)->data)->toMatchArray(['ok' => false, 'error' => 'invalid_arguments'])
        ->and(executeCreateSupportTicket($bot, [
            'description' => "bad\ninput",
            'email' => 'jane@example.com',
        ], $context)->data)->toMatchArray(['ok' => false, 'error' => 'invalid_arguments'])
        ->and(executeCreateSupportTicket($bot, [
            'description' => 'The laptop will not turn on.',
            'email' => 'jane@example.com',
            'unexpected' => 'value',
        ], $context)->data)->toMatchArray(['ok' => false, 'error' => 'invalid_arguments'])
        ->and(ToolRun::query()->count())->toBe(0);
});

test('create_support_ticket proposes without making an HTTP request and stores safe arguments', function () {
    Http::preventStrayRequests();
    [, $bot, , , $context] = createSupportTicketContext();

    $result = executeCreateSupportTicket($bot, [
        'subject' => 'Wrong item received',
        'description' => 'The delivered item does not match my order.',
        'category' => 'order',
        'priority' => 'normal',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+995555123456',
        'order_reference' => 'AB123',
    ], $context);
    $run = ToolRun::query()->firstOrFail();

    expect($result->data['requires_confirmation'])->toBeTrue()
        ->and($result->data['summary'])->toContain('Wrong item received')
        ->and($run->status)->toBe(ToolRunStatus::PendingConfirmation)
        ->and($run->idempotency_key)->not->toBeEmpty()
        ->and($run->safe_arguments)->toEqualCanonicalizing([
            'subject' => 'Wrong item received',
            'description' => 'The delivered item does not match my order.',
            'category' => 'order',
            'priority' => 'normal',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '+995555123456',
            'order_number' => 'AB123',
        ]);
});

test('required operation inputs fail safely before a write', function () {
    Http::preventStrayRequests();
    [, $bot, , , $context] = createSupportTicketContext();

    $result = executeCreateSupportTicket($bot, [
        'subject' => 'Need help',
    ], $context);

    expect($result->data)->toMatchArray(['ok' => false, 'error' => 'invalid_request'])
        ->and(ToolRun::query()->count())->toBe(0);
});

test('confirmed create_support_ticket writes once and returns only mapped safe output', function () {
    Http::fake([
        'https://support.example.test/*' => Http::response([
            'data' => [
                'reference' => 'SUP-4812',
                'status' => 'open',
                'url' => 'https://support.example.test/tickets/SUP-4812',
                'agent_url' => 'https://internal.example.test/tickets/SUP-4812',
                'internal_note' => 'private',
            ],
        ], 201, ['Content-Type' => 'application/json']),
    ]);
    [, $bot, , , $context] = createSupportTicketContext();
    $manager = app(WriteActionManager::class);
    $proposal = executeCreateSupportTicket($bot, [
        'description' => 'The wrong item arrived.',
        'email' => 'jane@example.com',
    ], $context);

    $result = $manager->confirm($bot, $context, $proposal->data['action_reference']);
    $run = ToolRun::query()->firstOrFail();

    expect($result->data)->toMatchArray([
        'ok' => true,
        'status' => 'completed',
        'result' => [
            'ticket_reference' => 'SUP-4812',
            'status' => 'open',
            'support_url' => 'https://support.example.test/tickets/SUP-4812',
        ],
    ])
        ->and($run->status)->toBe(ToolRunStatus::Completed)
        ->and($run->safe_result)->toEqualCanonicalizing([
            'ticket_reference' => 'SUP-4812',
            'status' => 'open',
            'support_url' => 'https://support.example.test/tickets/SUP-4812',
        ]);

    Http::assertSent(function ($request) use ($run): bool {
        return $request->method() === 'POST'
            && $request->data() === [
                'ticket' => ['description' => 'The wrong item arrived.'],
                'customer' => ['email' => 'jane@example.com'],
            ]
            && $request->header('Idempotency-Key') === [$run->idempotency_key];
    });
});

test('duplicate confirmation creates one support ticket and reuses the completed result', function () {
    Http::fake([
        'https://support.example.test/*' => Http::response([
            'data' => ['reference' => 'SUP-4812', 'status' => 'open'],
        ], 201, ['Content-Type' => 'application/json']),
    ]);
    [, $bot, , , $context] = createSupportTicketContext();
    $manager = app(WriteActionManager::class);
    $proposal = executeCreateSupportTicket($bot, [
        'description' => 'The wrong item arrived.',
        'email' => 'jane@example.com',
    ], $context);

    $first = $manager->confirm($bot, $context, $proposal->data['action_reference']);
    $second = $manager->confirm($bot, $context, $proposal->data['action_reference']);

    expect($second->data)->toBe($first->data);
    Http::assertSentCount(1);
});

test('cancelled support ticket proposals never execute', function () {
    Http::preventStrayRequests();
    [, $bot, , , $context] = createSupportTicketContext();
    $manager = app(WriteActionManager::class);
    $proposal = executeCreateSupportTicket($bot, [
        'description' => 'The wrong item arrived.',
        'email' => 'jane@example.com',
    ], $context);

    $cancelled = $manager->cancel($bot, $context, $proposal->data['action_reference']);
    $confirmed = $manager->confirm($bot, $context, $proposal->data['action_reference']);

    expect($cancelled->data['status'])->toBe('cancelled')
        ->and($confirmed->data['error'])->toBe('action_cancelled')
        ->and(ToolRun::query()->firstOrFail()->status)->toBe(ToolRunStatus::Cancelled);
});

test('model-input mappings include customer and order context but never locally resolve an order', function () {
    $settings = [
        'input_mapping' => [
            'subject' => [
                'source' => 'model_input',
                'model_input' => 'subject',
                'operation_argument' => 'subject',
            ],
            'description' => [
                'source' => 'model_input',
                'model_input' => 'description',
                'operation_argument' => 'description',
            ],
            'category' => [
                'source' => 'model_input',
                'model_input' => 'category',
                'operation_argument' => 'category',
            ],
            'priority' => [
                'source' => 'model_input',
                'model_input' => 'priority',
                'operation_argument' => 'priority',
            ],
            'name' => [
                'source' => 'model_input',
                'model_input' => 'name',
                'operation_argument' => 'customer_name',
            ],
            'email' => [
                'source' => 'model_input',
                'model_input' => 'email',
                'operation_argument' => 'customer_email',
            ],
            'phone' => [
                'source' => 'model_input',
                'model_input' => 'phone',
                'operation_argument' => 'customer_phone',
            ],
            'order_reference' => [
                'source' => 'model_input',
                'model_input' => 'order_reference',
                'operation_argument' => 'order_number',
            ],
        ],
    ];
    [, $bot, , , $context] = createSupportTicketContext($settings);

    $result = executeCreateSupportTicket($bot, [
        'subject' => 'Wrong item',
        'description' => 'The delivered item is incorrect.',
        'category' => 'order',
        'priority' => 'normal',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+995555123456',
        'order_reference' => 'AB123',
    ], $context);

    expect($result->data['requires_confirmation'])->toBeTrue()
        ->and(ToolRun::query()->firstOrFail()->safe_arguments)->toEqualCanonicalizing([
            'subject' => 'Wrong item',
            'description' => 'The delivered item is incorrect.',
            'category' => 'order',
            'priority' => 'normal',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '+995555123456',
            'order_number' => 'AB123',
        ]);
});

test('authorized product context enriches a support ticket and foreign products do not', function () {
    $settings = [
        'input_mapping' => [
            'description' => [
                'source' => 'model_input',
                'model_input' => 'description',
                'operation_argument' => 'description',
            ],
            'email' => [
                'source' => 'model_input',
                'model_input' => 'email',
                'operation_argument' => 'customer_email',
            ],
            'product_reference' => [
                'source' => 'dataset_field',
                'dataset_field' => 'sku',
                'operation_argument' => 'product_sku',
            ],
        ],
    ];
    [, $bot, , , $context, , $record] = createSupportTicketContext($settings, [
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'description' => ['type' => 'string'],
                'customer_email' => ['type' => 'string'],
                'product_sku' => ['type' => 'string'],
            ],
            'required' => ['description', 'customer_email', 'product_sku'],
        ],
    ], true);

    $result = executeCreateSupportTicket($bot, [
        'description' => 'The laptop is defective.',
        'email' => 'jane@example.com',
        'product_reference' => $record->external_id,
    ], $context);

    expect($result->data['requires_confirmation'])->toBeTrue()
        ->and(ToolRun::query()->firstOrFail()->safe_arguments)->toEqualCanonicalizing([
            'description' => 'The laptop is defective.',
            'customer_email' => 'jane@example.com',
            'product_sku' => 'SKU-001',
        ]);

    ToolRun::query()->delete();
    $foreignDataset = Dataset::factory()->ready()->create([
        'team_id' => User::factory()->create()->currentTeam->id,
        'entity_type' => 'catalog',
    ]);
    DatasetField::factory()->create(['dataset_id' => $foreignDataset->id, 'key' => 'sku']);
    $foreignRecord = DatasetRecord::factory()->create([
        'dataset_id' => $foreignDataset->id,
        'external_id' => 'foreign-product',
        'payload' => ['sku' => 'FOREIGN-1'],
    ]);

    expect(executeCreateSupportTicket($bot, [
        'description' => 'The laptop is defective.',
        'email' => 'jane@example.com',
        'product_reference' => $foreignRecord->external_id,
    ], $context)->data)->toMatchArray(['ok' => false, 'error' => 'not_found'])
        ->and(ToolRun::query()->count())->toBe(0);
});

test('a support ticket cannot use a foreign bot context', function () {
    [, $bot] = createSupportTicketContext();
    $foreignUser = User::factory()->create();
    $foreignBot = Bot::factory()->create(['team_id' => $foreignUser->currentTeam->id]);
    $foreignConversation = Conversation::factory()->create(['bot_id' => $foreignBot->id]);
    $foreignMessage = Message::factory()->create([
        'conversation_id' => $foreignConversation->id,
        'role' => 'user',
    ]);
    $foreignContext = ToolExecutionContext::forBot($foreignBot, $foreignConversation, $foreignMessage);

    $result = executeCreateSupportTicket($bot, [
        'description' => 'The wrong item arrived.',
        'email' => 'jane@example.com',
    ], $foreignContext);

    expect($result->data)->toMatchArray(['ok' => false, 'error' => 'action_not_available'])
        ->and(ToolRun::query()->count())->toBe(0);
});
