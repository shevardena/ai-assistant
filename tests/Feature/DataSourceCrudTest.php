<?php

use App\Enums\TeamRole;
use App\Models\DataSource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access the data source index', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->get(route('data-sources.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});

test('authenticated users can access the data source index for their current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    $currentDataSource = DataSource::factory()->create(['team_id' => $team->id]);
    $otherDataSource = DataSource::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this
        ->actingAs($user)
        ->get(route('data-sources.index', ['current_team' => $team->slug]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-sources/index')
            ->has('dataSources.data', 1)
            ->where('dataSources.data.0.id', $currentDataSource->id)
            ->where('dataSources.data', fn (Collection $dataSources): bool => ! $dataSources->pluck('id')->contains($otherDataSource->id)),
        );
});

test('data source index follows the selected current team', function () {
    $user = User::factory()->create();
    $firstTeam = Team::factory()->create();
    $secondTeam = Team::factory()->create();

    $firstTeam->members()->attach($user, ['role' => TeamRole::Member->value]);
    $secondTeam->members()->attach($user, ['role' => TeamRole::Member->value]);

    $firstDataSource = DataSource::factory()->create(['team_id' => $firstTeam->id]);
    $secondDataSource = DataSource::factory()->create(['team_id' => $secondTeam->id]);

    $user->switchTeam($firstTeam);

    $this->actingAs($user)
        ->get(route('data-sources.index', ['current_team' => $firstTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('dataSources.data', 1)
            ->where('dataSources.data.0.id', $firstDataSource->id),
        );

    $this->actingAs($user)
        ->get(route('data-sources.index', ['current_team' => $secondTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('dataSources.data', 1)
            ->where('dataSources.data.0.id', $secondDataSource->id),
        );
});

test('authenticated users can access the data source create page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->get(route('data-sources.create', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('data-sources/create'));
});

test('a data source is created for the current team regardless of submitted ownership fields', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    $response = $this
        ->actingAs($user)
        ->post(route('data-sources.store', ['current_team' => $team->slug]), [
            'name' => 'Product API',
            'type' => 'rest_api',
            'config' => '{"base_url":"https://api.example.com"}',
            'team_id' => $otherTeam->id,
            'status' => 'ready',
            'last_synced_at' => now()->toISOString(),
        ]);

    $dataSource = DataSource::query()->where('name', 'Product API')->firstOrFail();

    $response->assertRedirect(route('data-sources.show', [
        'current_team' => $team->slug,
        'data_source' => $dataSource,
    ]));

    expect($dataSource->team_id)->toBe($team->id)
        ->and($dataSource->type)->toBe('rest_api')
        ->and($dataSource->status)->toBe('pending')
        ->and($dataSource->config)->toBe(['base_url' => 'https://api.example.com'])
        ->and($dataSource->last_synced_at)->toBeNull();
});

test('users can view and edit a data source from their current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataSource = DataSource::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->get(route('data-sources.show', ['current_team' => $team->slug, 'data_source' => $dataSource]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-sources/show')
            ->where('dataSource.id', $dataSource->id),
        );

    $this->actingAs($user)
        ->get(route('data-sources.edit', ['current_team' => $team->slug, 'data_source' => $dataSource]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-sources/edit')
            ->where('dataSource.id', $dataSource->id),
        );
});

test('users cannot view or edit a data source from another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataSource = DataSource::factory()->create(['team_id' => $otherTeam->id]);

    $this->actingAs($user)
        ->get(route('data-sources.show', ['current_team' => $team->slug, 'data_source' => $dataSource]))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('data-sources.edit', ['current_team' => $team->slug, 'data_source' => $dataSource]))
        ->assertForbidden();
});

test('a data source can be updated without changing its team or server state', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataSource = DataSource::factory()->create([
        'team_id' => $team->id,
        'status' => 'ready',
        'last_synced_at' => now()->subDay(),
    ]);

    $response = $this
        ->actingAs($user)
        ->patch(route('data-sources.update', ['current_team' => $team->slug, 'data_source' => $dataSource]), [
            'name' => 'Updated Source',
            'type' => 'file',
            'config' => '{"path":"/imports/products.json"}',
            'team_id' => $otherTeam->id,
            'status' => 'disabled',
            'last_synced_at' => null,
        ]);

    $response->assertRedirect(route('data-sources.show', [
        'current_team' => $team->slug,
        'data_source' => $dataSource,
    ]));

    $updatedDataSource = $dataSource->fresh();

    expect($updatedDataSource->name)->toBe('Updated Source')
        ->and($updatedDataSource->type)->toBe('file')
        ->and($updatedDataSource->team_id)->toBe($team->id)
        ->and($updatedDataSource->status)->toBe('ready')
        ->and($updatedDataSource->last_synced_at)->not->toBeNull()
        ->and($updatedDataSource->config)->toBe(['path' => '/imports/products.json']);
});

test('users cannot update a data source from another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataSource = DataSource::factory()->create(['team_id' => $otherTeam->id]);

    $this->actingAs($user)
        ->patch(route('data-sources.update', ['current_team' => $team->slug, 'data_source' => $dataSource]), [
            'name' => 'Should Not Update',
            'type' => 'file',
            'config' => '{}',
        ])
        ->assertForbidden();

    expect($dataSource->fresh()->name)->not->toBe('Should Not Update');
});

test('users can soft-delete a data source from their current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataSource = DataSource::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->delete(route('data-sources.destroy', ['current_team' => $team->slug, 'data_source' => $dataSource]))
        ->assertRedirect(route('data-sources.index', ['current_team' => $team->slug]));

    $this->assertSoftDeleted('data_sources', ['id' => $dataSource->id]);
});

test('users cannot delete a data source from another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataSource = DataSource::factory()->create(['team_id' => $otherTeam->id]);

    $this->actingAs($user)
        ->delete(route('data-sources.destroy', ['current_team' => $team->slug, 'data_source' => $dataSource]))
        ->assertForbidden();

    $this->assertDatabaseHas('data_sources', [
        'id' => $dataSource->id,
        'deleted_at' => null,
    ]);
});

test('data source validation requires a valid name and supported type', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->post(route('data-sources.store', ['current_team' => $team->slug]), [
            'name' => str_repeat('a', 256),
            'type' => 'database',
            'config' => '{invalid-json',
        ])
        ->assertSessionHasErrors(['name', 'type', 'config']);
});

test('data source configuration must be valid JSON', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->post(route('data-sources.store', ['current_team' => $team->slug]), [
            'name' => 'Invalid Config Source',
            'type' => 'file',
            'config' => 'not-json',
        ])
        ->assertSessionHasErrors('config');
});
