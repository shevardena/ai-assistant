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
use App\Services\Ai\WriteActionManager;
use App\Services\Conversations\ConversationService;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: User, 1: Bot, 2: Conversation, 3: Message, 4: WidgetVisitor|null}
 */
function confirmationHttpContext(bool $withVisitor = false): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $bot = Bot::factory()->published()->create(['team_id' => $team->id]);
    $dataSource = DataSource::factory()->ready()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
    ]);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $dataSource->id,
        'key' => 'lead_create',
        'type' => 'action',
        'execution_mode' => ApiOperationMode::Write->value,
        'method' => 'POST',
        'path' => '/leads',
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
            ],
            'required' => ['name', 'email'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'body' => [
                'name' => 'customer.name',
                'email' => 'customer.email',
            ],
        ],
        'response_mapping' => [
            'output' => [
                'reference' => 'data.reference',
            ],
        ],
    ]);
    BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'capture_lead',
    ]);

    $visitor = $withVisitor
        ? WidgetVisitor::factory()->create(['bot_id' => $bot->id])
        : null;

    if ($withVisitor) {
        $bot->domains()->create(['domain' => 'example.com']);
    }
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $visitor?->id,
        'metadata' => ['source' => $withVisitor ? 'widget' : 'dashboard_preview'],
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
    ]);

    return [$user, $bot, $conversation, $message, $visitor];
}

function confirmationProposal(Bot $bot, Conversation $conversation, Message $message, ?WidgetVisitor $visitor = null): ToolRun
{
    $result = app(WriteActionManager::class)->propose(
        $bot,
        'capture_lead',
        ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
        'Submit Jane Doe as a lead.',
        ToolExecutionContext::forBot($bot, $conversation, $message, $visitor),
    );

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'metadata' => ['blocks' => $result->blocks],
    ]);
    $run = ToolRun::query()->where('action_reference', $result->data['action_reference'])->firstOrFail();
    $run->update(['message_id' => $message->id]);

    return $run;
}

test('dashboard confirmation executes the scoped pending action and returns a completed block', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response([
            'data' => ['reference' => 'lead-123'],
        ], 201),
    ]);
    [$user, $bot, $conversation, $message] = confirmationHttpContext();
    $run = confirmationProposal($bot, $conversation, $message);

    $response = $this->actingAs($user)
        ->postJson(route('bots.ai.actions.confirm', [
            'current_team' => $user->currentTeam->slug,
            'bot' => $bot,
            'actionReference' => $run->action_reference,
        ]), [
            'conversation_id' => $conversation->public_id,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('block.type', 'confirmation')
        ->assertJsonPath('block.data.status', 'completed');

    expect($run->fresh()->status)->toBe(ToolRunStatus::Completed)
        ->and(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain($run->idempotency_key)
        ->and(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('tool_run_id');
    Http::assertSentCount(1);
});

test('public confirmation is scoped to the original visitor and conversation', function () {
    Http::preventStrayRequests();
    [, $bot, $conversation, $message, $visitor] = confirmationHttpContext(true);
    $run = confirmationProposal($bot, $conversation, $message, $visitor);
    $otherVisitor = WidgetVisitor::factory()->create(['bot_id' => $bot->id]);
    $otherConversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $otherVisitor->id,
    ]);

    $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.actions.confirm', [
            'botPublicId' => $bot->public_id,
            'actionReference' => $run->action_reference,
        ]), [
            'visitor_id' => $otherVisitor->public_id,
            'conversation_id' => $otherConversation->public_id,
        ])
        ->assertNotFound();

    expect($run->fresh()->status)->toBe(ToolRunStatus::PendingConfirmation);
    Http::assertNothingSent();
});

test('public cancellation returns a terminal block without executing the write', function () {
    Http::preventStrayRequests();
    [, $bot, $conversation, $message, $visitor] = confirmationHttpContext(true);
    $run = confirmationProposal($bot, $conversation, $message, $visitor);
    $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.actions.cancel', [
            'botPublicId' => $bot->public_id,
            'actionReference' => $run->action_reference,
        ]), [
            'visitor_id' => $visitor->public_id,
            'conversation_id' => $conversation->public_id,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('block.data.status', 'cancelled');

    expect($run->fresh()->status)->toBe(ToolRunStatus::Cancelled);
    Http::assertNothingSent();
});

test('history reconciliation reflects the current ToolRun state', function () {
    [, $bot, $conversation, $message] = confirmationHttpContext();
    $run = confirmationProposal($bot, $conversation, $message);
    $assistant = Message::query()->where('role', 'assistant')->firstOrFail();
    $run->update([
        'status' => ToolRunStatus::Completed->value,
        'safe_result' => ['reference' => 'lead-123'],
    ]);

    $blocks = app(ConversationService::class)->messageBlocks($assistant->fresh());

    expect($blocks[0]['data']['status'])->toBe('completed')
        ->and($blocks[0]['data']['result'])->toBe(['reference' => 'lead-123']);
});
