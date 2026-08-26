<?php

use App\Enums\TeamRole;
use App\Enums\ToolRunStatus;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SearchRun;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function () {
    Carbon::setTestNow();
});

function analyticsContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Analytics Team']);

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

function analyticsToolRun(Bot $bot, string $toolName, string $status, ?string $createdAt = null): ToolRun
{
    return ToolRun::factory()->create([
        'team_id' => $bot->team_id,
        'bot_id' => $bot->id,
        'api_operation_id' => null,
        'tool_name' => $toolName,
        'status' => $status,
        'safe_arguments' => [
            'email' => 'private@example.com',
            'phone' => '+995555000000',
        ],
        'safe_result' => ['internal' => 'private result'],
        'created_at' => $createdAt ?? now(),
    ]);
}

test('analytics aggregates current team activity without returning private telemetry', function () {
    [$user, $team] = analyticsContext();
    $otherTeam = Team::factory()->create(['name' => 'Other Team']);
    $otherTeam->members()->attach($user, ['role' => TeamRole::Member->value]);
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Sales Bot']);
    $secondBot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Support Bot']);
    $foreignBot = Bot::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Foreign Bot']);
    $visitor = $bot->visitors()->create(['public_id' => fake()->uuid()]);
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $visitor->id,
        'created_at' => now()->subDay(),
    ]);
    Conversation::factory()->create([
        'bot_id' => $secondBot->id,
        'created_at' => now()->subDay(),
    ]);
    Message::factory()->create(['conversation_id' => $conversation->id, 'created_at' => now()->subDay()]);
    Message::factory()->create(['conversation_id' => $conversation->id, 'created_at' => now()->subDay()]);
    SearchRun::factory()->create([
        'bot_id' => $bot->id,
        'result_count' => 0,
        'created_at' => now()->subDay(),
    ]);
    SearchRun::factory()->create([
        'bot_id' => $secondBot->id,
        'result_count' => 4,
        'created_at' => now()->subDay(),
    ]);
    analyticsToolRun($bot, 'capture_lead', ToolRunStatus::Completed->value, now()->subDay());
    analyticsToolRun($bot, 'create_support_ticket', ToolRunStatus::Failed->value, now()->subDay());
    analyticsToolRun($bot, 'add_to_cart', ToolRunStatus::Cancelled->value, now()->subDay());
    analyticsToolRun($bot, 'book_appointment', ToolRunStatus::PendingConfirmation->value, now()->subDay());
    analyticsToolRun($foreignBot, 'capture_lead', ToolRunStatus::Completed->value, now()->subDay());

    $this->actingAs($user)
        ->get(route('analytics.index', ['current_team' => $team->slug, 'bot' => $foreignBot->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('analytics/index')
            ->where('filters.range', '30d')
            ->where('filters.bot', null)
            ->where('summary.conversations', 2)
            ->where('summary.visitors', 1)
            ->where('summary.messages', 2)
            ->where('summary.searches', 2)
            ->where('summary.zeroResultSearches', 1)
            ->where('summary.actionsProposed', 4)
            ->where('summary.completedActions', 1)
            ->where('summary.failedActions', 1)
            ->where('summary.cancelledActions', 1)
            ->where('summary.leadsCaptured', 1)
            ->where('summary.supportTickets', 0)
            ->where('summary.appointmentsBooked', 0)
            ->where('summary.addToCart', 0)
            ->has('timeseries.conversations', 30)
            ->where('bots.0.name', 'Sales Bot')
            ->where('bots.0.conversations', 1)
            ->where('bots.0.completedActions', 1)
            ->where('bots.1.name', 'Support Bot')
            ->missing('messageContent')
            ->missing('safeArguments')
            ->missing('safeResult')
            ->missing('private@example.com')
            ->missing('+995555000000')
            ->missing($foreignBot->name),
        );
});

test('analytics bot filter scopes all activity to a current team Bot', function () {
    [$user, $team] = analyticsContext();
    $firstBot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'First Bot']);
    $secondBot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Second Bot']);
    $firstConversation = Conversation::factory()->create(['bot_id' => $firstBot->id]);
    Conversation::factory()->create(['bot_id' => $secondBot->id]);
    Message::factory()->create(['conversation_id' => $firstConversation->id]);
    SearchRun::factory()->create(['bot_id' => $firstBot->id]);
    SearchRun::factory()->create(['bot_id' => $secondBot->id]);
    analyticsToolRun($firstBot, 'capture_lead', ToolRunStatus::Completed->value);
    analyticsToolRun($secondBot, 'capture_lead', ToolRunStatus::Completed->value);

    $this->actingAs($user)
        ->get(route('analytics.index', ['current_team' => $team->slug, 'bot' => $firstBot->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.bot', $firstBot->slug)
            ->where('summary.conversations', 1)
            ->where('summary.messages', 1)
            ->where('summary.searches', 1)
            ->where('summary.completedActions', 1)
            ->has('bots', 1)
            ->where('bots.0.name', 'First Bot'),
        );
});

test('analytics date presets include only activity in the selected period', function () {
    Carbon::setTestNow('2026-08-22 12:00:00');
    [$user, $team] = analyticsContext();
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    foreach ([
        'today' => '2026-08-22 10:00:00',
        'week' => '2026-08-16 10:00:00',
        'month' => '2026-07-24 10:00:00',
        'quarter' => '2026-05-25 10:00:00',
    ] as $createdAt) {
        Conversation::factory()->create([
            'bot_id' => $bot->id,
            'created_at' => $createdAt,
        ]);
    }

    $this->actingAs($user)
        ->get(route('analytics.index', ['current_team' => $team->slug, 'range' => 'today']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.conversations', 1)->has('timeseries.conversations', 1));

    $this->actingAs($user)
        ->get(route('analytics.index', ['current_team' => $team->slug, 'range' => '7d']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.conversations', 2)->has('timeseries.conversations', 7));

    $this->actingAs($user)
        ->get(route('analytics.index', ['current_team' => $team->slug, 'range' => '30d']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.conversations', 3)->has('timeseries.conversations', 30));

    $this->actingAs($user)
        ->get(route('analytics.index', ['current_team' => $team->slug, 'range' => '90d']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.conversations', 4)->has('timeseries.conversations', 90));
});

test('analytics follows the selected team for a member who belongs to multiple teams', function () {
    [$user, $firstTeam] = analyticsContext();
    $secondTeam = Team::factory()->create(['name' => 'Second Analytics Team']);
    $secondTeam->members()->attach($user, ['role' => TeamRole::Member->value]);
    $firstBot = Bot::factory()->create(['team_id' => $firstTeam->id]);
    $secondBot = Bot::factory()->create(['team_id' => $secondTeam->id]);
    Conversation::factory()->create(['bot_id' => $firstBot->id]);
    Conversation::factory()->create(['bot_id' => $secondBot->id]);

    $this->actingAs($user)
        ->get(route('analytics.index', ['current_team' => $secondTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.conversations', 1)
            ->where('bots.0.name', $secondBot->name),
        );

    expect($user->fresh()->current_team_id)->toBe($secondTeam->id);
});

test('analytics rejects invalid date ranges', function () {
    [$user, $team] = analyticsContext();

    $this->actingAs($user)
        ->get(route('analytics.index', ['current_team' => $team->slug, 'range' => 'all']))
        ->assertSessionHasErrors('range');
});
