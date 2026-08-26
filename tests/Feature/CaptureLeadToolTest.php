<?php

use App\Enums\ApiOperationMode;
use App\Enums\ToolRunStatus;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\BotDataset;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\DataSource;
use App\Models\Lead;
use App\Models\Message;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Ai\AiToolSchemaBuilder;
use App\Services\Ai\BotToolRegistry;
use App\Services\Ai\Tools\CaptureLeadTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Ai\WriteActionManager;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $settings
 * @param  array<string, mixed>  $operationOverrides
 * @return array{0: User, 1: Bot, 2: ApiOperation, 3: BotApiOperation, 4: ToolExecutionContext, 5: Dataset|null, 6: DatasetRecord|null}
 */
function captureLeadContext(array $settings = [], array $operationOverrides = [], bool $withProduct = false): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $dataSource = DataSource::factory()->ready()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
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
        'key' => 'lead_create',
        'name' => 'Create lead',
        'type' => 'action',
        'execution_mode' => ApiOperationMode::Write->value,
        'method' => 'POST',
        'path' => '/leads',
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'customer_name' => ['type' => 'string'],
                'customer_email' => ['type' => 'string'],
                'product_sku' => ['type' => 'string'],
            ],
            'required' => ['customer_email'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'body' => [
                'customer_name' => 'customer.name',
                'customer_email' => 'customer.email',
                'product_sku' => 'product.sku',
            ],
            'idempotency_header' => 'Idempotency-Key',
        ],
        'response_mapping' => [
            'output' => [
                'lead_reference' => 'data.reference',
                'status' => 'data.status',
            ],
        ],
        ...$operationOverrides,
    ]);
    $attachment = BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'capture_lead',
        'is_enabled' => true,
        'settings' => $settings ?: [
            'input_mapping' => [
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
function executeCaptureLead(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
{
    return app(CaptureLeadTool::class)->execute($bot, $arguments, $context);
}

test('capture_lead exposes a strict optional contact schema', function () {
    [, $bot] = captureLeadContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'capture_lead');
    $schema = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($schema)->toMatchArray([
        'type' => 'function',
        'name' => 'capture_lead',
        'strict' => true,
    ])
        ->and($schema['parameters']['required'])->toBe(['email'])
        ->and($schema['parameters']['additionalProperties'])->toBeFalse()
        ->and($schema['parameters']['properties'])->toHaveKeys([
            'name',
            'email',
            'phone',
            'message',
            'product_reference',
        ]);
});

test('registry exposes capture_lead for a valid model-input write integration without a catalog dataset', function () {
    [, $bot, , $attachment] = captureLeadContext();

    expect(app(BotToolRegistry::class)->find($bot, 'capture_lead'))->toBeInstanceOf(CaptureLeadTool::class);

    $attachment->update(['is_enabled' => false]);
    expect(app(BotToolRegistry::class)->find($bot, 'capture_lead'))->toBeNull();
});

test('registry does not expose capture_lead for read operations', function () {
    [, $bot, $operation] = captureLeadContext();

    $operation->update(['execution_mode' => ApiOperationMode::Read->value]);

    expect(app(BotToolRegistry::class)->find($bot, 'capture_lead'))->toBeNull();
});

test('capture_lead creates a pending proposal without making an HTTP request', function () {
    Http::preventStrayRequests();
    [, $bot, , , $context] = captureLeadContext();

    $result = executeCaptureLead($bot, [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], $context);
    $run = ToolRun::query()->firstOrFail();

    expect($result->data['requires_confirmation'])->toBeTrue()
        ->and($result->data['action_reference'])->toBe($run->action_reference)
        ->and($run->status)->toBe(ToolRunStatus::PendingConfirmation)
        ->and($run->idempotency_key)->not->toBeEmpty()
        ->and($run->safe_arguments)->toBe([
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
        ]);
});

test('capture_lead rejects invalid input and asks for missing configured values before proposal', function () {
    Http::preventStrayRequests();
    [, $bot, , , $context] = captureLeadContext();

    expect(executeCaptureLead($bot, ['email' => 'not-an-email'], $context)->data)
        ->toMatchArray(['ok' => false, 'error' => 'invalid_arguments'])
        ->and(executeCaptureLead($bot, ['name' => 'Jane Doe'], $context)->data)
        ->toMatchArray(['ok' => false, 'error' => 'missing_input'])
        ->and(executeCaptureLead($bot, ['name' => 'Jane Doe'], $context)->blocks[0]['type'])
        ->toBe('form')
        ->and(ToolRun::query()->count())->toBe(0);
});

test('confirmed capture_lead writes once and returns the mapped safe result', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => Http::response([
            'data' => [
                'reference' => 'LEAD-1832',
                'status' => 'created',
                'internal_note' => 'private',
            ],
        ], 201, ['Content-Type' => 'application/json']),
    ]);
    [, $bot, , , $context] = captureLeadContext();
    $manager = app(WriteActionManager::class);
    $proposal = executeCaptureLead($bot, [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], $context);

    $result = $manager->confirm($bot, $context, $proposal->data['action_reference']);
    $run = ToolRun::query()->firstOrFail();

    expect($result->data)->toMatchArray([
        'ok' => true,
        'status' => 'completed',
        'result' => [
            'lead_reference' => 'LEAD-1832',
            'status' => 'created',
        ],
    ])
        ->and($run->status)->toBe(ToolRunStatus::Completed)
        ->and(Lead::query()->where('tool_run_id', $run->id)->count())->toBe(1)
        ->and(Lead::query()->where('tool_run_id', $run->id)->firstOrFail()->customer_id)->not->toBeNull()
        ->and(Customer::query()->where('team_id', $bot->team_id)->where('normalized_email', 'jane@example.com')->count())->toBe(1)
        ->and($run->safe_result)->toEqualCanonicalizing([
            'lead_reference' => 'LEAD-1832',
            'status' => 'created',
        ]);

    Http::assertSent(function ($request) use ($run): bool {
        return $request->method() === 'POST'
            && $request->data() === [
                'customer' => [
                    'name' => 'Jane Doe',
                    'email' => 'jane@example.com',
                ],
            ]
            && $request->header('Idempotency-Key') === [$run->idempotency_key];
    });
});

test('duplicate capture_lead confirmation does not submit another lead', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response([
            'data' => ['reference' => 'LEAD-1832', 'status' => 'created'],
        ], 201, ['Content-Type' => 'application/json']),
    ]);
    [, $bot, , , $context] = captureLeadContext();
    $manager = app(WriteActionManager::class);
    $proposal = executeCaptureLead($bot, ['email' => 'jane@example.com'], $context);

    $first = $manager->confirm($bot, $context, $proposal->data['action_reference']);
    $second = $manager->confirm($bot, $context, $proposal->data['action_reference']);

    expect($second->data)->toBe($first->data);
    Http::assertSentCount(1);
});

test('capture_lead can enrich a lead from an authorized product record', function () {
    Http::preventStrayRequests();
    $settings = [
        'input_mapping' => [
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
    [, $bot, $operation, , $context, , $record] = captureLeadContext($settings, [
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'customer_email' => ['type' => 'string'],
                'product_sku' => ['type' => 'string'],
            ],
            'required' => ['customer_email', 'product_sku'],
        ],
    ], true);

    $result = executeCaptureLead($bot, [
        'email' => 'jane@example.com',
        'product_reference' => $record->external_id,
    ], $context);
    $run = ToolRun::query()->firstOrFail();

    expect($result->data['requires_confirmation'])->toBeTrue()
        ->and($result->data['summary'])->toContain('Gaming Laptop')
        ->and($run->safe_arguments)->toEqualCanonicalizing([
            'customer_email' => 'jane@example.com',
            'product_sku' => 'SKU-001',
        ])
        ->and($operation->execution_mode)->toBe(ApiOperationMode::Write->value);
});

test('foreign or unattached product context cannot enrich a lead', function () {
    Http::preventStrayRequests();
    $settings = [
        'input_mapping' => [
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
    [, $bot, , , $context] = captureLeadContext($settings, [
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'customer_email' => ['type' => 'string'],
                'product_sku' => ['type' => 'string'],
            ],
            'required' => ['customer_email', 'product_sku'],
        ],
    ], true);
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

    $result = executeCaptureLead($bot, [
        'email' => 'jane@example.com',
        'product_reference' => $foreignRecord->external_id,
    ], $context);

    expect($result->data)->toMatchArray(['ok' => false, 'error' => 'not_found'])
        ->and(ToolRun::query()->count())->toBe(0);
});

test('cancelled capture_lead cannot execute later', function () {
    Http::preventStrayRequests();
    [, $bot, , , $context] = captureLeadContext();
    $manager = app(WriteActionManager::class);
    $proposal = executeCaptureLead($bot, ['email' => 'jane@example.com'], $context);

    $cancelled = $manager->cancel($bot, $context, $proposal->data['action_reference']);
    $confirmed = $manager->confirm($bot, $context, $proposal->data['action_reference']);

    expect($cancelled->data['status'])->toBe('cancelled')
        ->and($confirmed->data['error'])->toBe('action_cancelled')
        ->and(ToolRun::query()->firstOrFail()->status)->toBe(ToolRunStatus::Cancelled);
});
