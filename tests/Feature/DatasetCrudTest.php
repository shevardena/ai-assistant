<?php

use App\Enums\TeamRole;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DataSource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

function makeTeamDataSource(Team $team, array $attributes = []): DataSource
{
    return DataSource::factory()->create([
        'team_id' => $team->id,
        ...$attributes,
    ]);
}

function makeTeamDataset(Team $team, DataSource $dataSource, array $attributes = []): Dataset
{
    return Dataset::factory()->create([
        'team_id' => $team->id,
        'data_source_id' => $dataSource->id,
        ...$attributes,
    ]);
}

test('guests cannot access the dataset index', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->get(route('datasets.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});

test('dataset index only shows datasets from the current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    $currentDataset = makeTeamDataset($team, makeTeamDataSource($team));
    $otherDataset = makeTeamDataset($otherTeam, makeTeamDataSource($otherTeam));

    $this->actingAs($user)
        ->get(route('datasets.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('datasets/index')
            ->has('datasets.data', 1)
            ->where('datasets.data.0.id', $currentDataset->id)
            ->where('datasets.data', fn (Collection $datasets): bool => ! $datasets->pluck('id')->contains($otherDataset->id)),
        );
});

test('dataset index follows the selected current team', function () {
    $user = User::factory()->create();
    $firstTeam = Team::factory()->create();
    $secondTeam = Team::factory()->create();
    $firstTeam->members()->attach($user, ['role' => TeamRole::Member->value]);
    $secondTeam->members()->attach($user, ['role' => TeamRole::Member->value]);

    $firstDataset = makeTeamDataset($firstTeam, makeTeamDataSource($firstTeam));
    $secondDataset = makeTeamDataset($secondTeam, makeTeamDataSource($secondTeam));

    $user->switchTeam($firstTeam);

    $this->actingAs($user)
        ->get(route('datasets.index', ['current_team' => $firstTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('datasets.data', 1)
            ->where('datasets.data.0.id', $firstDataset->id),
        );

    $this->actingAs($user)
        ->get(route('datasets.index', ['current_team' => $secondTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('datasets.data', 1)
            ->where('datasets.data.0.id', $secondDataset->id),
        );
});

test('authenticated users can access the dataset create page with current-team sources', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $dataSource = makeTeamDataSource($team);

    $this->actingAs($user)
        ->get(route('datasets.create', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('datasets/create')
            ->where('dataSources.0.id', $dataSource->id),
        );
});

test('a dataset is created for the current team with a current-team data source', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataSource = makeTeamDataSource($team);

    $response = $this
        ->actingAs($user)
        ->post(route('datasets.store', ['current_team' => $team->slug]), [
            'name' => 'Product Catalog',
            'slug' => 'product-catalog',
            'data_source_id' => $dataSource->id,
            'entity_type' => 'product',
            'retrieval_mode' => 'indexed',
            'primary_key_path' => 'product_id',
            'settings' => '{"locale":"en"}',
            'team_id' => $otherTeam->id,
            'status' => 'ready',
            'schema_version' => 99,
            'last_indexed_at' => now()->toISOString(),
        ]);

    $dataset = Dataset::query()->where('slug', 'product-catalog')->firstOrFail();

    $response->assertRedirect(route('datasets.show', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]));

    expect($dataset->team_id)->toBe($team->id)
        ->and($dataset->data_source_id)->toBe($dataSource->id)
        ->and($dataset->status)->toBe('preparing')
        ->and($dataset->schema_version)->toBe(1)
        ->and($dataset->last_indexed_at)->toBeNull()
        ->and($dataset->settings)->toBe(['locale' => 'en']);
});

test('a dataset cannot use a data source from another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $otherDataSource = makeTeamDataSource($otherTeam);

    $this->actingAs($user)
        ->post(route('datasets.store', ['current_team' => $team->slug]), [
            'name' => 'Invalid Dataset',
            'slug' => 'invalid-dataset',
            'data_source_id' => $otherDataSource->id,
            'entity_type' => 'product',
            'retrieval_mode' => 'indexed',
        ])
        ->assertSessionHasErrors('data_source_id');

    expect(Dataset::query()->where('slug', 'invalid-dataset')->exists())->toBeFalse();
});

test('users can view and edit a dataset from their current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataset = makeTeamDataset($team, makeTeamDataSource($team));
    DatasetField::factory()->create(['dataset_id' => $dataset->id]);

    $this->actingAs($user)
        ->get(route('datasets.show', ['current_team' => $team->slug, 'dataset' => $dataset]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('datasets/show')
            ->where('dataset.id', $dataset->id)
            ->has('dataset.fields', 1),
        );

    $this->actingAs($user)
        ->get(route('datasets.edit', ['current_team' => $team->slug, 'dataset' => $dataset]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('datasets/edit')
            ->where('dataset.id', $dataset->id),
        );
});

test('users cannot view or edit a dataset from another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataset = makeTeamDataset($otherTeam, makeTeamDataSource($otherTeam));

    $this->actingAs($user)
        ->get(route('datasets.show', ['current_team' => $team->slug, 'dataset' => $dataset]))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('datasets.edit', ['current_team' => $team->slug, 'dataset' => $dataset]))
        ->assertForbidden();
});

test('a dataset can be updated without changing its team or server metadata', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataSource = makeTeamDataSource($team);
    $dataset = makeTeamDataset($team, $dataSource, ['status' => 'ready']);

    $response = $this
        ->actingAs($user)
        ->patch(route('datasets.update', ['current_team' => $team->slug, 'dataset' => $dataset]), [
            'name' => 'Updated Catalog',
            'slug' => $dataset->slug,
            'data_source_id' => $dataSource->id,
            'entity_type' => 'product',
            'retrieval_mode' => 'hybrid',
            'primary_key_path' => 'id',
            'settings' => '{}',
            'team_id' => $otherTeam->id,
            'status' => 'error',
            'schema_version' => 99,
        ]);

    $response->assertRedirect(route('datasets.show', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]));

    $updatedDataset = $dataset->fresh();

    expect($updatedDataset->name)->toBe('Updated Catalog')
        ->and($updatedDataset->retrieval_mode)->toBe('hybrid')
        ->and($updatedDataset->team_id)->toBe($team->id)
        ->and($updatedDataset->status)->toBe('ready')
        ->and($updatedDataset->schema_version)->toBe(1);
});

test('a dataset cannot be updated to use another teams data source', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataSource = makeTeamDataSource($team);
    $otherDataSource = makeTeamDataSource($otherTeam);
    $dataset = makeTeamDataset($team, $dataSource);

    $this->actingAs($user)
        ->patch(route('datasets.update', ['current_team' => $team->slug, 'dataset' => $dataset]), [
            'name' => 'Should Not Update',
            'slug' => $dataset->slug,
            'data_source_id' => $otherDataSource->id,
            'entity_type' => 'product',
            'retrieval_mode' => 'indexed',
        ])
        ->assertSessionHasErrors('data_source_id');

    expect($dataset->fresh()->name)->not->toBe('Should Not Update');
});

test('users can soft-delete a dataset from their current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataset = makeTeamDataset($team, makeTeamDataSource($team));

    $this->actingAs($user)
        ->delete(route('datasets.destroy', ['current_team' => $team->slug, 'dataset' => $dataset]))
        ->assertRedirect(route('datasets.index', ['current_team' => $team->slug]));

    $this->assertSoftDeleted('datasets', ['id' => $dataset->id]);
});

test('users cannot delete a dataset from another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataset = makeTeamDataset($otherTeam, makeTeamDataSource($otherTeam));

    $this->actingAs($user)
        ->delete(route('datasets.destroy', ['current_team' => $team->slug, 'dataset' => $dataset]))
        ->assertForbidden();

    expect($dataset->fresh()->deleted_at)->toBeNull();
});

test('dataset validation enforces required fields, supported modes, and settings JSON', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $dataSource = makeTeamDataSource($team);

    $this->actingAs($user)
        ->post(route('datasets.store', ['current_team' => $team->slug]), [
            'name' => str_repeat('a', 256),
            'slug' => 'invalid dataset',
            'data_source_id' => $dataSource->id,
            'entity_type' => 'product',
            'retrieval_mode' => 'unsupported',
            'settings' => 'not-json',
        ])
        ->assertSessionHasErrors(['name', 'slug', 'retrieval_mode', 'settings']);
});
