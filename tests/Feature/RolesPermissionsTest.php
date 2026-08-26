<?php

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAuthorizationService;
use Illuminate\Support\Facades\Gate;

function roleContext(TeamRole $role): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

test('permissions resolve against the current team after switching', function () {
    $user = User::factory()->create();
    $adminTeam = Team::factory()->create();
    $analystTeam = Team::factory()->create();
    $adminTeam->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $analystTeam->members()->attach($user, ['role' => TeamRole::Analyst->value]);
    $authorization = app(TeamAuthorizationService::class);

    $user->switchTeam($adminTeam);
    expect($authorization->can($user, $adminTeam, TeamPermission::BotsUpdate))->toBeTrue()
        ->and($authorization->can($user, $adminTeam, TeamPermission::LeadsUpdate))->toBeTrue();

    $user->switchTeam($analystTeam);
    expect($authorization->can($user, $analystTeam, TeamPermission::AnalyticsView))->toBeTrue()
        ->and($authorization->can($user, $analystTeam, TeamPermission::BotsUpdate))->toBeFalse()
        ->and($authorization->can($user, $analystTeam, TeamPermission::LeadsUpdate))->toBeFalse();
});

test('support agents can operate handoffs but cannot edit tenant configuration', function () {
    [$user, $team] = roleContext(TeamRole::SupportAgent);
    $authorization = app(TeamAuthorizationService::class);

    expect($authorization->can($user, $team, TeamPermission::ConversationsView))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::ConversationsReply))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::ConversationsHandoff))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::BotsUpdate))->toBeFalse()
        ->and($authorization->can($user, $team, TeamPermission::DatasetsManage))->toBeFalse()
        ->and($authorization->can($user, $team, TeamPermission::CredentialsManage))->toBeFalse()
        ->and($authorization->can($user, $team, TeamPermission::TeamMembersManage))->toBeFalse();
});

test('analysts are read only for reporting and operational records', function () {
    [$user, $team] = roleContext(TeamRole::Analyst);
    $authorization = app(TeamAuthorizationService::class);

    expect($authorization->can($user, $team, TeamPermission::AnalyticsView))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::ConversationsView))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::LeadsView))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::AppointmentsView))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::TicketsView))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::LeadsUpdate))->toBeFalse()
        ->and($authorization->can($user, $team, TeamPermission::AppointmentsUpdate))->toBeFalse()
        ->and($authorization->can($user, $team, TeamPermission::ConversationsReply))->toBeFalse();
});

test('content managers can manage data content but not credentials or support actions', function () {
    [$user, $team] = roleContext(TeamRole::ContentManager);
    $authorization = app(TeamAuthorizationService::class);

    expect($authorization->can($user, $team, TeamPermission::DatasetsManage))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::DatasetFieldsManage))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::DataSourcesManage))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::CredentialsManage))->toBeFalse()
        ->and($authorization->can($user, $team, TeamPermission::ApiOperationsManage))->toBeFalse()
        ->and($authorization->can($user, $team, TeamPermission::ConversationsReply))->toBeFalse();
});

test('developers can manage integrations and bot tests without customer contact access', function () {
    [$user, $team] = roleContext(TeamRole::Developer);
    $authorization = app(TeamAuthorizationService::class);

    expect($authorization->can($user, $team, TeamPermission::BotsUpdate))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::CredentialsManage))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::ApiOperationsManage))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::IntegrationsManage))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::BotTestsManage))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::LeadsView))->toBeFalse()
        ->and($authorization->can($user, $team, TeamPermission::TeamMembersManage))->toBeFalse();
});

test('admin has broad operational access but cannot perform owner-only deletion', function () {
    [$user, $team] = roleContext(TeamRole::Admin);
    $authorization = app(TeamAuthorizationService::class);

    expect($authorization->can($user, $team, TeamPermission::BotsUpdate))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::TeamMembersManage))->toBeTrue()
        ->and($authorization->can($user, $team, TeamPermission::DeleteTeam))->toBeFalse();
});

test('foreign team membership never overrides current-team resource isolation', function () {
    [$user, $currentTeam] = roleContext(TeamRole::Admin);
    $foreignTeam = Team::factory()->create();
    $foreignTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $authorization = app(TeamAuthorizationService::class);
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id]);

    expect($authorization->can($user, $foreignTeam, TeamPermission::BotsUpdate))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $foreignBot))->toBeFalse()
        ->and($user->isCurrentTeam($foreignTeam))->toBeFalse();
});
