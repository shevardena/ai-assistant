<?php

use App\Enums\RuntimeMode;
use App\Enums\TeamRole;
use App\Enums\ToolRunStatus;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\ToolRun;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Laravel Team']);

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.team.name', 'Laravel Team')
        ->where('pendingInvitations.0.team.slug', $team->slug)
        ->missing('pendingInvitations.0.teamName'),
    );
});

test('dashboard does not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard reports only current team production activity', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Operations Team']);
    $user->teams()->attach($team, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Operations Bot']);
    $foreignBot = Bot::factory()->create(['name' => 'Foreign Bot']);

    Conversation::factory()->create([
        'bot_id' => $bot->id,
        'metadata' => ['source' => 'widget'],
        'last_message_at' => now(),
    ]);
    Conversation::factory()->create([
        'bot_id' => $bot->id,
        'metadata' => ['source' => 'dashboard_preview'],
    ]);
    Conversation::factory()->create([
        'bot_id' => $foreignBot->id,
        'metadata' => ['source' => 'widget'],
    ]);
    ToolRun::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'api_operation_id' => null,
        'runtime_mode' => RuntimeMode::Normal->value,
        'status' => ToolRunStatus::Completed->value,
    ]);
    ToolRun::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'api_operation_id' => null,
        'runtime_mode' => RuntimeMode::Test->value,
        'status' => ToolRunStatus::Completed->value,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug, 'range' => '7d']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('range', '7d')
            ->where('team.name', 'Operations Team')
            ->where('metrics.conversations.value', 1)
            ->where('metrics.successfulActions.value', 1)
            ->where('setup.isSetup', false)
            ->has('recentConversations', 1)
            ->missing('recentConversations.0.externalUserReference')
            ->missing('recentConversations.0.safeArguments'),
        );
});

test('empty dashboard exposes setup state and does not expose billing to non-billing roles', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'New Team']);
    $team->members()->attach($user, ['role' => TeamRole::Analyst->value]);
    $user->switchTeam($team);

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('setup.isSetup', true)
            ->where('setup.productionStarted', false)
            ->where('billing', null)
            ->has('setup.steps')
            ->has('quickActions'),
        );
});

test('dashboard rejects a team the authenticated user does not belong to', function () {
    $user = User::factory()->create();
    $foreignTeam = Team::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $foreignTeam->slug]))
        ->assertForbidden();
});
