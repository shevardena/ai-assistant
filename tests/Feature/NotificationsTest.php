<?php

use App\Enums\ConversationHandoffStatus;
use App\Enums\RuntimeMode;
use App\Enums\TeamNotificationType;
use App\Enums\TeamRole;
use App\Models\Appointment;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\Lead;
use App\Models\SourceRun;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Notifications\TeamEventNotification;
use App\Services\Ai\Tools\RequestHumanHandoffTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Teams\TeamNotificationService;
use Inertia\Testing\AssertableInertia as Assert;

function notificationFor(User $user, Team $team, string $key, string $title = 'Activity'): void
{
    $user->notifyNow(new TeamEventNotification(
        notificationType: TeamNotificationType::LeadCaptured,
        teamId: (int) $team->id,
        eventKey: $key,
        title: $title,
        message: 'A safe notification summary.',
        data: [
            'target_type' => 'lead',
            'target_reference' => 'lead-reference',
        ],
    ));
}

test('notification center is limited to the current team and paginates newest notifications', function () {
    $user = User::factory()->create();
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    $teamA->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $teamB->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($teamA);

    for ($index = 1; $index <= 26; $index++) {
        notificationFor($user, $teamA, 'team-a-'.$index, 'Team A '.$index);
    }

    notificationFor($user, $teamB, 'team-b-1', 'Team B');
    $user->notifications()->where('team_id', $teamA->id)->latest('created_at')->firstOrFail()->markAsRead();

    $this->actingAs($user)
        ->get(route('notifications.index', $teamA->slug))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('notifications/index')
            ->where('totalCount', 26)
            ->where('unreadCount', 25)
            ->where('filter', 'all')
            ->has('notifications.data', 25));

    $this->actingAs($user)
        ->get(route('notifications.index', [$teamA->slug, 'filter' => 'unread']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('totalCount', 26)
            ->where('unreadCount', 25)
            ->where('filter', 'unread')
            ->has('notifications.data', 25));
});

test('users can mark their current team notifications read, unread, and all read', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);
    notificationFor($user, $team, 'read-test');
    $notification = $user->notifications()->where('event_key', 'read-test')->firstOrFail();
    $foreignTeam = Team::factory()->create();
    $foreignTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);
    notificationFor($user, $foreignTeam, 'foreign-test');
    $foreignNotification = $user->notifications()->where('event_key', 'foreign-test')->firstOrFail();

    $this->actingAs($user)
        ->patch(route('notifications.read', [$team->slug, $notification->id]))
        ->assertRedirect();
    expect($notification->fresh()->read_at)->not->toBeNull();

    $this->actingAs($user)
        ->patch(route('notifications.unread', [$team->slug, $notification->id]))
        ->assertRedirect();
    expect($notification->fresh()->read_at)->toBeNull();

    $this->actingAs($user)
        ->post(route('notifications.read-all', $team->slug))
        ->assertRedirect();
    expect($user->notifications()->where('team_id', $team->id)->whereNull('read_at')->count())->toBe(0);

    $this->actingAs($user)
        ->patch(route('notifications.read', [$team->slug, $foreignNotification->id]))
        ->assertNotFound();
});

test('handoff notifications use permissions, suppress duplicates, and exclude preview conversations', function () {
    $team = Team::factory()->create();
    $supportAgent = User::factory()->create();
    $analyst = User::factory()->create();
    $team->members()->attach($supportAgent, ['role' => TeamRole::SupportAgent->value]);
    $team->members()->attach($analyst, ['role' => TeamRole::Analyst->value]);
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Support Assistant']);
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'handoff_status' => ConversationHandoffStatus::Requested->value,
        'handoff_requested_at' => now(),
        'metadata' => ['source' => 'widget'],
    ]);

    $service = app(TeamNotificationService::class);
    $service->notifyHandoffRequested($conversation);
    $service->notifyHandoffRequested($conversation->fresh());

    expect($supportAgent->notifications()->where('team_id', $team->id)->count())->toBe(1)
        ->and($analyst->notifications()->where('team_id', $team->id)->count())->toBe(0)
        ->and($supportAgent->notifications()->firstOrFail()->data)->not->toHaveKey('raw_message');

    $previewConversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'handoff_status' => ConversationHandoffStatus::Requested->value,
        'handoff_requested_at' => now(),
        'metadata' => ['source' => 'dashboard_preview'],
    ]);
    $service->notifyHandoffRequested($previewConversation);

    expect($supportAgent->notifications()->where('team_id', $team->id)->count())->toBe(1);
});

test('operational notifications use safe summaries, known targets, and durable deduplication keys', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Sales Assistant']);
    $dataSource = DataSource::factory()->create(['team_id' => $team->id, 'name' => 'Shipping API']);
    $dataset = Dataset::factory()->create([
        'team_id' => $team->id,
        'data_source_id' => $dataSource->id,
    ]);
    $lead = Lead::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'email' => 'private@example.com',
        'phone' => '+995555000000',
    ]);
    $appointment = Appointment::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'starts_at' => now()->addDay(),
    ]);
    $ticket = SupportTicket::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'summary' => 'Private customer description.',
    ]);
    $sourceRun = SourceRun::factory()->create([
        'data_source_id' => $dataSource->id,
        'dataset_id' => $dataset->id,
        'status' => 'failed',
        'error' => 'Private raw provider exception.',
    ]);
    $operation = $dataSource->apiOperations()->create([
        'key' => 'capture-lead',
        'name' => 'Capture lead',
        'type' => 'mutation',
        'execution_mode' => 'write',
        'method' => 'POST',
        'path' => '/leads',
        'request_schema' => [],
        'request_mapping' => [],
        'response_mapping' => [],
        'headers' => [],
        'timeout_ms' => 10000,
        'is_enabled' => true,
    ]);
    $action = ToolRun::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'capture_lead',
        'action_reference' => (string) str()->uuid(),
        'error_code' => 'invalid_request',
    ]);
    $service = app(TeamNotificationService::class);

    $service->notifyLeadCaptured($lead);
    $service->notifyAppointmentBooked($appointment);
    $service->notifySupportTicketCreated($ticket);
    $service->notifyDataImportFailed($sourceRun);
    $service->notifyActionFailed($action);
    $service->notifyIntegrationFailure($dataSource, 'timeout');
    $service->notifyIntegrationFailure($dataSource, 'timeout');

    $notifications = $owner->notifications()->where('team_id', $team->id)->get();

    expect($notifications)->toHaveCount(6)
        ->and($notifications->pluck('data')->flatten()->implode('|'))
        ->not->toContain('private@example.com')
        ->not->toContain('+995555000000')
        ->not->toContain('Private raw provider exception.')
        ->not->toContain('Private customer description.')
        ->and($notifications->pluck('event_key')->unique())->toHaveCount(6);
});

test('test-mode handoffs do not mutate conversations or create production notifications', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
    $context = ToolExecutionContext::forBot(
        bot: $bot,
        conversation: $conversation,
        mode: RuntimeMode::Test,
    );

    $result = app(RequestHumanHandoffTool::class)->execute(
        $bot,
        ['reason' => 'customer_requested'],
        $context,
    );

    expect($result->data)->toMatchArray(['ok' => true, 'test_mode' => true])
        ->and($conversation->fresh()->handoff_status)->toBe(ConversationHandoffStatus::Ai)
        ->and($user->notifications()->count())->toBe(0);
});
