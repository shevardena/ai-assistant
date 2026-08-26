<?php

use App\Enums\SupportTicketStatus;
use App\Enums\TeamRole;
use App\Enums\ToolRunStatus;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\SupportTickets\SupportTicketProjectionService;
use Inertia\Testing\AssertableInertia as Assert;

function supportDashboardContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Support Team']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Support Bot']);
    $operation = ApiOperation::factory()->create();
    BotApiOperation::factory()->create(['bot_id' => $bot->id, 'api_operation_id' => $operation->id, 'tool_name' => 'create_support_ticket', 'settings' => ['input_mapping' => ['subject' => ['source' => 'model_input', 'operation_argument' => 'subject'], 'description' => ['source' => 'model_input', 'operation_argument' => 'description'], 'name' => ['source' => 'model_input', 'operation_argument' => 'customer_name'], 'email' => ['source' => 'model_input', 'operation_argument' => 'customer_email']]]]);

    return [$user, $team, $bot, $operation];
}

function completedSupportRun(Bot $bot, ApiOperation $operation, array $overrides = []): ToolRun
{
    return ToolRun::factory()->create(['team_id' => $bot->team_id, 'bot_id' => $bot->id, 'api_operation_id' => $operation->id, 'tool_name' => 'create_support_ticket', 'status' => ToolRunStatus::Completed->value, 'safe_arguments' => ['subject' => 'Wrong item', 'description' => 'The delivered item is incorrect.', 'customer_name' => 'Jane Doe', 'customer_email' => 'jane@example.com'], 'safe_result' => ['ticket_reference' => 'SUP-42', 'support_url' => 'https://support.example.test/tickets/SUP-42'], 'completed_at' => now(), ...$overrides]);
}

test('completed support actions project once and validate provider links', function (): void {
    [, $team, $bot, $operation] = supportDashboardContext();
    $run = completedSupportRun($bot, $operation);
    $service = app(SupportTicketProjectionService::class);
    $ticket = $service->project($run);
    $repeat = $service->project($run->fresh());

    expect($ticket)->toBeInstanceOf(SupportTicket::class)
        ->and($repeat?->id)->toBe($ticket?->id)
        ->and(SupportTicket::query()->where('team_id', $team->id)->count())->toBe(1)
        ->and($ticket?->subject)->toBe('Wrong item')
        ->and($ticket?->status)->toBe(SupportTicketStatus::Open)
        ->and($ticket?->customer_id)->not->toBeNull()
        ->and(Customer::query()->where('team_id', $team->id)->count())->toBe(1)
        ->and($ticket?->external_url)->toBe('https://support.example.test/tickets/SUP-42');
});

test('failed, cancelled, and preview support actions do not project', function (): void {
    [, , $bot, $operation] = supportDashboardContext();
    $service = app(SupportTicketProjectionService::class);
    foreach ([ToolRunStatus::PendingConfirmation, ToolRunStatus::Failed, ToolRunStatus::Cancelled] as $status) {
        expect($service->project(completedSupportRun($bot, $operation, ['status' => $status->value])))->toBeNull();
    }
    $preview = Conversation::factory()->create(['bot_id' => $bot->id, 'metadata' => ['source' => 'dashboard_preview']]);
    expect($service->project(completedSupportRun($bot, $operation, ['conversation_id' => $preview->id])))->toBeNull()->and(SupportTicket::query()->count())->toBe(0);
});

test('support ticket dashboard is current-team scoped and filters safely', function (): void {
    [$user, $team, $bot] = supportDashboardContext();
    SupportTicket::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'subject' => 'Printer issue']);
    $foreignTeam = Team::factory()->create();
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id]);
    $foreign = SupportTicket::factory()->create(['team_id' => $foreignTeam->id, 'bot_id' => $foreignBot->id, 'subject' => 'Foreign ticket']);

    $this->actingAs($user)->get(route('support-tickets.index', ['current_team' => $team->slug, 'search' => 'Printer']))->assertSuccessful()->assertInertia(fn (Assert $page) => $page->component('support-tickets/index')->where('tickets.total', 1)->where('summary.open', 1));
    $this->actingAs($user)->patch(route('support-tickets.update', ['current_team' => $team->slug, 'supportTicket' => $foreign]), ['status' => SupportTicketStatus::Resolved->value])->assertNotFound();
    expect($foreign->fresh()?->status)->toBe(SupportTicketStatus::Open);
});
