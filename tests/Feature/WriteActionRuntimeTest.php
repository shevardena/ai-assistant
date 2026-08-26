<?php

use App\Enums\ApiOperationMode;
use App\Enums\ToolRunStatus;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\Conversation;
use App\Models\DataSource;
use App\Models\Message;
use App\Models\ToolRun;
use App\Models\User;
use App\Models\WidgetVisitor;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Ai\WriteActionManager;
use App\Services\Api\RuntimeApiOperation;
use App\Services\Api\RuntimeApiOperationResolver;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: User, 1: Bot, 2: DataSource, 3: ApiOperation, 4: Conversation, 5: Message, 6: ToolExecutionContext}
 */
function writeActionContext(bool $withVisitor = false): array
{
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
        'key' => 'lead_create',
        'name' => 'Create lead',
        'type' => 'action',
        'execution_mode' => ApiOperationMode::Write->value,
        'method' => 'POST',
        'path' => '/leads',
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'metadata' => ['type' => 'object'],
            ],
            'required' => ['name', 'email'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'body' => [
                'name' => 'customer.name',
                'email' => 'customer.email',
            ],
            'idempotency_header' => 'Idempotency-Key',
        ],
        'response_mapping' => [
            'output' => [
                'reference' => 'data.reference',
                'status' => 'data.status',
            ],
        ],
    ]);
    BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'capture_lead',
        'is_enabled' => true,
    ]);

    $visitor = $withVisitor ? WidgetVisitor::factory()->create(['bot_id' => $bot->id]) : null;
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $visitor?->id,
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
    ]);
    $context = ToolExecutionContext::forBot($bot, $conversation, $message, $visitor);

    return [$user, $bot, $dataSource, $operation, $conversation, $message, $context];
}

test('the read resolver rejects writes while the distinct write resolver accepts only writes', function () {
    [, $bot, , $operation] = writeActionContext();
    $resolver = app(RuntimeApiOperationResolver::class);

    expect($resolver->resolve($bot, 'capture_lead'))->toBeNull()
        ->and($resolver->resolveRead($bot, 'capture_lead'))->toBeNull()
        ->and($resolver->resolveWrite($bot, 'capture_lead'))->toBeInstanceOf(RuntimeApiOperation::class);

    $operation->update(['execution_mode' => ApiOperationMode::Read->value]);

    expect($resolver->resolveWrite($bot, 'capture_lead'))->toBeNull()
        ->and($resolver->resolve($bot, 'capture_lead'))->toBeInstanceOf(RuntimeApiOperation::class);
});

test('a write proposal is persisted as pending confirmation without making an HTTP request', function () {
    Http::preventStrayRequests();
    [$user, $bot, , , , , $context] = writeActionContext();
    $result = app(WriteActionManager::class)->propose(
        $bot,
        'capture_lead',
        [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'metadata' => ['api_key' => 'top-secret', 'source' => 'widget'],
        ],
        'Submit Jane Doe as a lead.',
        $context,
    );

    $run = ToolRun::query()->firstOrFail();

    expect($user->current_team_id)->toBe($bot->team_id)
        ->and($result->data['requires_confirmation'])->toBeTrue()
        ->and($result->data['action_reference'])->toBe($run->action_reference)
        ->and($run->status)->toBe(ToolRunStatus::PendingConfirmation)
        ->and($run->safe_arguments)->toBe([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'metadata' => ['source' => 'widget'],
        ])
        ->and($run->idempotency_key)->not->toBeEmpty();
});

test('confirmation executes a write once with a server-generated idempotency key and safe output', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/*' => Http::response([
            'data' => ['reference' => 'lead-123', 'status' => 'created'],
        ], 201, ['Content-Type' => 'application/json']),
    ]);
    [, $bot, , , , , $context] = writeActionContext();
    $manager = app(WriteActionManager::class);
    $proposal = $manager->propose(
        $bot,
        'capture_lead',
        ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
        'Submit Jane Doe as a lead.',
        $context,
    );
    $reference = $proposal->data['action_reference'];
    $run = ToolRun::query()->firstOrFail();

    $result = $manager->confirm($bot, $context, $reference);

    expect($result->data)->toMatchArray([
        'ok' => true,
        'status' => ToolRunStatus::Completed->value,
        'result' => ['reference' => 'lead-123', 'status' => 'created'],
    ])
        ->and($run->fresh()->status)->toBe(ToolRunStatus::Completed)
        ->and($run->fresh()->safe_result)->toMatchArray(['reference' => 'lead-123', 'status' => 'created']);

    Http::assertSent(function ($request) use ($run): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.example.test/leads'
            && $request->data() === [
                'customer' => [
                    'name' => 'Jane Doe',
                    'email' => 'jane@example.com',
                ],
            ]
            && $request->header('Idempotency-Key') === [$run->idempotency_key];
    });
});

test('duplicate confirmation returns the persisted result without issuing a second write', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response([
            'data' => ['reference' => 'lead-123', 'status' => 'created'],
        ], 201, ['Content-Type' => 'application/json']),
    ]);
    [, $bot, , , , , $context] = writeActionContext();
    $manager = app(WriteActionManager::class);
    $proposal = $manager->propose(
        $bot,
        'capture_lead',
        ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
        'Submit Jane Doe as a lead.',
        $context,
    );

    $first = $manager->confirm($bot, $context, $proposal->data['action_reference']);
    $second = $manager->confirm($bot, $context, $proposal->data['action_reference']);

    expect($second->data)->toBe($first->data);
    Http::assertSentCount(1);
});

test('a pending action can be cancelled and cannot execute afterward', function () {
    Http::preventStrayRequests();
    [, $bot, , , , , $context] = writeActionContext();
    $manager = app(WriteActionManager::class);
    $proposal = $manager->propose(
        $bot,
        'capture_lead',
        ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
        'Submit Jane Doe as a lead.',
        $context,
    );

    $cancelled = $manager->cancel($bot, $context, $proposal->data['action_reference']);
    $confirmed = $manager->confirm($bot, $context, $proposal->data['action_reference']);

    expect($cancelled->data['status'])->toBe(ToolRunStatus::Cancelled->value)
        ->and($confirmed->data['error'])->toBe('action_cancelled')
        ->and(ToolRun::query()->firstOrFail()->status)->toBe(ToolRunStatus::Cancelled);
});

test('a confirmation is isolated to its original conversation and widget visitor', function () {
    Http::preventStrayRequests();
    [, $bot, , , , , $context] = writeActionContext(true);
    $manager = app(WriteActionManager::class);
    $proposal = $manager->propose(
        $bot,
        'capture_lead',
        ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
        'Submit Jane Doe as a lead.',
        $context,
    );
    $otherVisitor = WidgetVisitor::factory()->create(['bot_id' => $bot->id]);
    $otherConversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $otherVisitor->id,
    ]);
    $otherContext = ToolExecutionContext::forBot($bot, $otherConversation, null, $otherVisitor);

    $result = $manager->confirm($bot, $otherContext, $proposal->data['action_reference']);

    expect($result->data['error'])->toBe('action_not_available');
    Http::assertNothingSent();
});

test('write connection failures are not retried and are audited without upstream details', function () {
    Http::fake([
        'https://api.example.test/*' => Http::failedConnection(),
    ]);
    [, $bot, , , , , $context] = writeActionContext();
    $manager = app(WriteActionManager::class);
    $proposal = $manager->propose(
        $bot,
        'capture_lead',
        ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
        'Submit Jane Doe as a lead.',
        $context,
    );

    $result = $manager->confirm($bot, $context, $proposal->data['action_reference']);
    $run = ToolRun::query()->firstOrFail();

    expect($result->data['error'])->toBe('action_failed')
        ->and($run->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_code)->toBe('unavailable')
        ->and(json_encode($run->toArray(), JSON_THROW_ON_ERROR))->not->toContain('Authorization');

    Http::assertSentCount(1);
});

test('invalid write arguments are rejected before a pending run is created', function () {
    Http::preventStrayRequests();
    [, $bot, , , , , $context] = writeActionContext();

    $result = app(WriteActionManager::class)->propose(
        $bot,
        'capture_lead',
        ['name' => 'Jane Doe'],
        'Submit Jane Doe as a lead.',
        $context,
    );

    expect($result->data['error'])->toBe('invalid_request')
        ->and(ToolRun::query()->count())->toBe(0);
});

test('confirmation-required results have a model-safe action reference shape', function () {
    $result = ToolResult::requiresConfirmation('123e4567-e89b-12d3-a456-426614174000', 'Confirm this action.');

    expect($result->modelData())->toBe([
        'ok' => false,
        'requires_confirmation' => true,
        'action_reference' => '123e4567-e89b-12d3-a456-426614174000',
        'summary' => 'Confirm this action.',
    ]);
});
