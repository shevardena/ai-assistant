<?php

use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('users can access bots in their current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    $bot = Bot::factory()->create(['team_id' => $team->id]);

    foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, $bot))->toBeTrue();
    }
});

test('users cannot access bots outside their current team', function () {
    $user = User::factory()->create();
    $currentTeam = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $currentTeam->members()->attach($user, ['role' => TeamRole::Member->value]);
    $otherTeam->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($currentTeam);

    $bot = Bot::factory()->create(['team_id' => $otherTeam->id]);

    foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, $bot))->toBeFalse();
    }
});

test('users can access data sources in their current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    $dataSource = DataSource::factory()->create(['team_id' => $team->id]);

    foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, $dataSource))->toBeTrue();
    }
});

test('users cannot access data sources outside their current team', function () {
    $user = User::factory()->create();
    $currentTeam = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $currentTeam->members()->attach($user, ['role' => TeamRole::Member->value]);
    $otherTeam->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($currentTeam);

    $dataSource = DataSource::factory()->create(['team_id' => $otherTeam->id]);

    foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, $dataSource))->toBeFalse();
    }
});

test('users can access datasets in their current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    $dataset = Dataset::factory()->create(['team_id' => $team->id]);

    foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, $dataset))->toBeTrue();
    }
});

test('users cannot access datasets outside their current team', function () {
    $user = User::factory()->create();
    $currentTeam = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $currentTeam->members()->attach($user, ['role' => TeamRole::Member->value]);
    $otherTeam->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($currentTeam);

    $dataset = Dataset::factory()->create(['team_id' => $otherTeam->id]);

    foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, $dataset))->toBeFalse();
    }
});

test('users can view and create current-team resources', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    foreach ([Bot::class, DataSource::class, Dataset::class] as $resource) {
        expect(Gate::forUser($user)->allows('viewAny', $resource))->toBeTrue();
        expect(Gate::forUser($user)->allows('create', $resource))->toBeTrue();
    }
});

test('users without a current team cannot view or create tenant resources', function () {
    $user = User::factory()->create();
    $user->update(['current_team_id' => null]);
    $user->unsetRelation('currentTeam');

    foreach ([Bot::class, DataSource::class, Dataset::class] as $resource) {
        expect(Gate::forUser($user)->allows('viewAny', $resource))->toBeFalse();
        expect(Gate::forUser($user)->allows('create', $resource))->toBeFalse();
    }
});

test('users cannot use a current team they do not belong to', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->update(['current_team_id' => $team->id]);
    $user->unsetRelation('currentTeam');

    foreach ([Bot::class, DataSource::class, Dataset::class] as $resource) {
        expect(Gate::forUser($user)->allows('viewAny', $resource))->toBeFalse();
        expect(Gate::forUser($user)->allows('create', $resource))->toBeFalse();
    }
});
