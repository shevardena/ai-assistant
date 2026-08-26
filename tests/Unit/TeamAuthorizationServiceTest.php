<?php

use App\Enums\TeamPermission;
use App\Enums\TeamRole;

test('predefined roles expose the intended centralized permissions', function () {
    expect(TeamRole::Owner->hasPermission(TeamPermission::DeleteTeam))->toBeTrue()
        ->and(TeamRole::Admin->hasPermission(TeamPermission::TeamMembersManage))->toBeTrue()
        ->and(TeamRole::Admin->hasPermission(TeamPermission::DeleteTeam))->toBeFalse()
        ->and(TeamRole::SupportAgent->hasPermission(TeamPermission::ConversationsReply))->toBeTrue()
        ->and(TeamRole::SupportAgent->hasPermission(TeamPermission::BotsUpdate))->toBeFalse()
        ->and(TeamRole::ContentManager->hasPermission(TeamPermission::DatasetsManage))->toBeTrue()
        ->and(TeamRole::ContentManager->hasPermission(TeamPermission::CredentialsManage))->toBeFalse()
        ->and(TeamRole::Analyst->hasPermission(TeamPermission::AnalyticsView))->toBeTrue()
        ->and(TeamRole::Analyst->hasPermission(TeamPermission::LeadsUpdate))->toBeFalse()
        ->and(TeamRole::Developer->hasPermission(TeamPermission::ApiOperationsManage))->toBeTrue()
        ->and(TeamRole::Developer->hasPermission(TeamPermission::LeadsView))->toBeFalse();
});

test('assignable roles never include owner and include customer-facing roles', function () {
    $values = array_column(TeamRole::assignable(), 'value');

    expect($values)->not->toContain(TeamRole::Owner->value)
        ->and($values)->toContain(
            TeamRole::Admin->value,
            TeamRole::SupportAgent->value,
            TeamRole::ContentManager->value,
            TeamRole::Analyst->value,
            TeamRole::Developer->value,
        );
});
