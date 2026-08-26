<?php

use App\Enums\TeamRole;
use App\Enums\ToolRunStatus;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Models\WidgetVisitor;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function () {
    Carbon::setTestNow();
});

function actionHistoryContext(string $teamName = 'Action Team'): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => $teamName]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Store Assistant']);

    return [$user, $team, $bot];
}

function actionHistoryRun(Bot $bot, array $overrides = []): ToolRun
{
    $status = $overrides['status'] ?? ToolRunStatus::PendingConfirmation->value;

    return ToolRun::factory()->create([
        'team_id' => $bot->team_id,
        'bot_id' => $bot->id,
        'api_operation_id' => null,
        'tool_name' => 'capture_lead',
        'status' => $status instanceof ToolRunStatus ? $status->value : $status,
        'safe_arguments' => ['email' => 'private@example.com', 'secret' => 'do-not-show'],
        ...$overrides,
    ]);
}

test('lists only current team actions with safe summaries and aggregate metrics', function () {
    [$user, $team, $bot] = actionHistoryContext();
    $foreignBot = Bot::factory()->create(['name' => 'Foreign Bot']);
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'metadata' => ['source' => 'widget'],
    ]);

    actionHistoryRun($bot, [
        'tool_name' => 'capture_lead',
        'status' => ToolRunStatus::Completed->value,
        'conversation_id' => $conversation->id,
        'safe_result' => ['lead_reference' => 'LEAD-1832', 'status' => 'created'],
        'completed_at' => now(),
        'duration_ms' => 342,
    ]);
    actionHistoryRun($bot, [
        'tool_name' => 'create_support_ticket',
        'status' => ToolRunStatus::Failed->value,
        'error_code' => 'integration_unavailable',
        'failed_at' => now(),
    ]);
    actionHistoryRun($bot, [
        'tool_name' => 'add_to_cart',
        'status' => ToolRunStatus::Cancelled->value,
        'error_code' => 'cancelled',
        'cancelled_at' => now(),
    ]);
    actionHistoryRun($bot, [
        'tool_name' => 'book_appointment',
        'status' => ToolRunStatus::PendingConfirmation->value,
    ]);
    actionHistoryRun($foreignBot, [
        'tool_name' => 'capture_lead',
        'status' => ToolRunStatus::Completed->value,
        'safe_result' => ['lead_reference' => 'FOREIGN-1'],
    ]);

    $response = $this->actingAs($user)
        ->get(route('actions.index', ['current_team' => $team->slug]));

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('actions/index')
            ->where('filters.range', '30d')
            ->where('summary.total', 4)
            ->where('summary.completed', 1)
            ->where('summary.failed', 1)
            ->where('summary.cancelled', 1)
            ->where('summary.pending', 1)
            ->where('summary.successRate', 33.3)
            ->where('actions.total', 4)
            ->where('actions.data.0.label', 'Book appointment')
            ->where('actions.data.1.statusLabel', 'Cancelled')
            ->where('actions.data.2.errorSummary', 'Integration unavailable.')
            ->where('actions.data.3.label', 'Capture lead')
            ->where('actions.data.3.conversationReference', $conversation->public_id)
            ->missing('Foreign Bot')
            ->missing('FOREIGN-1')
            ->missing('private@example.com')
            ->missing('do-not-show')
            ->missing('safeArguments')
            ->missing('safeResult'));

    $foreignFilter = $this->actingAs($user)
        ->get(route('actions.index', [
            'current_team' => $team->slug,
            'bot' => $foreignBot->slug,
        ]));

    $foreignFilter->assertInertia(fn (Assert $page) => $page
        ->where('filters.bot', null)
        ->where('actions.total', 4)
        ->missing('Foreign Bot'));
});

test('action detail is team-scoped and exposes only safe result presentation', function () {
    [$user, $team, $bot] = actionHistoryContext();
    $visitor = WidgetVisitor::factory()->create([
        'bot_id' => $bot->id,
        'external_customer_id' => 'visitor-private-id',
    ]);
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $visitor->id,
        'metadata' => ['source' => 'widget', 'form_values' => ['email' => 'secret@example.com']],
    ]);
    $run = actionHistoryRun($bot, [
        'tool_name' => 'add_to_cart',
        'status' => ToolRunStatus::Completed->value,
        'conversation_id' => $conversation->id,
        'visitor_id' => $visitor->id,
        'safe_result' => [
            'cart_status' => 'updated',
            'item_quantity' => 2,
            'cart_reference' => 'cart-secret',
            'checkout_url' => 'https://shop.example.test/secret',
        ],
        'confirmed_at' => now()->subSeconds(3),
        'started_at' => now()->subSeconds(2),
        'completed_at' => now(),
        'duration_ms' => 342,
    ]);
    $foreignRun = actionHistoryRun(Bot::factory()->create(['name' => 'Other Team Bot']), [
        'status' => ToolRunStatus::Completed->value,
    ]);

    $response = $this->actingAs($user)
        ->get(route('actions.show', [
            'current_team' => $team->slug,
            'actionReference' => $run->action_reference,
        ]));

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('actions/show')
            ->where('action.label', 'Add to cart')
            ->where('action.status', 'completed')
            ->where('action.statusLabel', 'Completed')
            ->where('action.result.summary', 'Item added to cart. Quantity: 2.')
            ->where('action.conversation.reference', $conversation->public_id)
            ->where('action.conversation.source', 'widget')
            ->where('action.lifecycle.0.label', 'Proposed')
            ->where('action.lifecycle.1.label', 'Confirmed')
            ->where('action.lifecycle.2.label', 'Executing')
            ->where('action.lifecycle.3.label', 'Completed')
            ->missing('cart-secret')
            ->missing('checkout_url')
            ->missing('visitor-private-id')
            ->missing('secret@example.com')
            ->missing('safe_arguments')
            ->missing('safe_result')
            ->missing('idempotency_key')
            ->missing('api_operation_id'));

    $this->actingAs($user)
        ->get(route('actions.show', [
            'current_team' => $team->slug,
            'actionReference' => $foreignRun->action_reference,
        ]))
        ->assertNotFound();
});

test('action history supports date, Bot, action, status, search, and server pagination', function () {
    Carbon::setTestNow('2026-08-22 12:00:00');
    [$user, $team, $bot] = actionHistoryContext();
    $secondBot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Support Assistant']);

    actionHistoryRun($bot, [
        'tool_name' => 'capture_lead',
        'status' => ToolRunStatus::Completed->value,
        'action_reference' => '11111111-1111-4111-8111-111111111111',
        'created_at' => '2026-08-22 10:00:00',
    ]);
    actionHistoryRun($bot, [
        'tool_name' => 'capture_lead',
        'status' => ToolRunStatus::Failed->value,
        'created_at' => '2026-08-21 10:00:00',
    ]);
    actionHistoryRun($secondBot, [
        'tool_name' => 'create_support_ticket',
        'status' => ToolRunStatus::Completed->value,
        'created_at' => '2026-08-22 09:00:00',
    ]);

    $filtered = $this->actingAs($user)
        ->get(route('actions.index', [
            'current_team' => $team->slug,
            'range' => 'today',
            'bot' => $bot->slug,
            'action' => 'capture_lead',
            'status' => 'completed',
            'search' => '11111111',
        ]));

    $filtered->assertInertia(fn (Assert $page) => $page
        ->where('filters.range', 'today')
        ->where('filters.bot', $bot->slug)
        ->where('filters.action', 'capture_lead')
        ->where('filters.status', 'completed')
        ->where('filters.search', '11111111')
        ->where('actions.total', 1));

    foreach (range(1, 26) as $index) {
        actionHistoryRun($bot, [
            'tool_name' => 'capture_lead',
            'status' => ToolRunStatus::Completed->value,
            'created_at' => now()->subMinutes($index),
        ]);
    }

    $paginated = $this->actingAs($user)
        ->get(route('actions.index', [
            'current_team' => $team->slug,
            'range' => 'all',
        ]));

    $paginated->assertInertia(fn (Assert $page) => $page
        ->where('actions.total', 29)
        ->where('actions.per_page', 25)
        ->has('actions.data', 25)
        ->where('actions.data.0.createdAt', fn (string $createdAt): bool => $createdAt !== ''));
});
