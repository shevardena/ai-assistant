<?php

use App\Enums\DatasetStatus;
use App\Enums\DataSourceStatus;
use App\Enums\TeamRole;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\DataSource;
use App\Models\SourceRun;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

function dataHealthContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Data Health Team']);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

function dataHealthDataset(Team $team, array $attributes = []): array
{
    $source = DataSource::factory()->ready()->create(['team_id' => $team->id]);
    $dataset = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'data_source_id' => $source->id,
        ...$attributes,
    ]);

    foreach ([
        ['key' => 'name', 'label' => 'Name', 'data_type' => 'string', 'is_displayable' => true, 'is_searchable' => true, 'is_filterable' => false, 'position' => 1],
        ['key' => 'price', 'label' => 'Price', 'data_type' => 'decimal', 'is_displayable' => true, 'is_searchable' => false, 'is_filterable' => true, 'position' => 2],
        ['key' => 'available', 'label' => 'Available', 'data_type' => 'boolean', 'is_displayable' => true, 'is_searchable' => false, 'is_filterable' => true, 'position' => 3],
        ['key' => 'description', 'label' => 'Description', 'data_type' => 'string', 'is_displayable' => true, 'is_searchable' => false, 'is_filterable' => false, 'position' => 4],
    ] as $field) {
        DatasetField::factory()->create(['dataset_id' => $dataset->id, ...$field]);
    }

    foreach ([
        ['external_id' => 'a', 'payload' => ['name' => 'A', 'price' => 20, 'available' => true, 'description' => 'First']],
        ['external_id' => 'b', 'payload' => ['name' => 'B', 'price' => 0, 'available' => false, 'description' => null]],
        ['external_id' => 'c', 'payload' => ['name' => 'C', 'price' => 5, 'available' => true, 'description' => '']],
        ['external_id' => 'd', 'payload' => ['name' => 'D', 'price' => 10, 'available' => false, 'description' => null]],
        ['external_id' => 'inactive', 'payload' => ['name' => 'Inactive', 'price' => 12, 'available' => true, 'description' => 'Old'], 'is_active' => false],
    ] as $record) {
        DatasetRecord::factory()->create(['dataset_id' => $dataset->id, ...$record]);
    }

    SourceRun::factory()->create([
        'data_source_id' => $source->id,
        'dataset_id' => $dataset->id,
        'status' => 'completed',
        'rows_read' => 5,
        'rows_written' => 5,
        'rows_failed' => 0,
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
    ]);

    return [$source, $dataset];
}

test('data health is isolated and reports counts and zero false coverage as present', function () {
    [$user, $team] = dataHealthContext();
    [$source, $dataset] = dataHealthDataset($team, ['name' => 'Products']);
    $foreignTeam = Team::factory()->create();
    $foreignSource = DataSource::factory()->ready()->create(['team_id' => $foreignTeam->id]);
    $foreignDataset = Dataset::factory()->ready()->create(['team_id' => $foreignTeam->id, 'data_source_id' => $foreignSource->id]);

    $this->actingAs($user)
        ->get(route('data-health.index', ['current_team' => $team->slug]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-health/index')
            ->where('summary.datasets', 1)
            ->where('summary.records', 4)
            ->where('datasets.data.0.name', $dataset->name)
            ->where('datasets.data.0.activeRecords', 4)
            ->where('datasets.data.0.inactiveRecords', 1)
            ->where('datasets.data.0.health', 'healthy')
            ->missing('datasets.data.0.fieldCoverage')
            ->missing($foreignDataset->name));

    $this->actingAs($user)
        ->get(route('data-health.show', ['current_team' => $team->slug, 'dataset' => $dataset->id]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-health/show')
            ->where('dataset.id', $dataset->id)
            ->where('fieldCoverage.0.key', 'name')
            ->where('fieldCoverage.0.presentCount', 4)
            ->where('fieldCoverage.1.presentCount', 4)
            ->where('fieldCoverage.2.presentCount', 4)
            ->where('fieldCoverage.3.presentCount', 1)
            ->missing('fieldCoverage.0.payload')
            ->missing('dataset.dataSource.config'));
});

test('data health classifies warnings, errors, and inactive datasets deterministically', function () {
    [$user, $team] = dataHealthContext();
    [, $healthy] = dataHealthDataset($team, ['name' => 'Healthy']);
    [, $warning] = dataHealthDataset($team, ['name' => 'Preparing', 'status' => DatasetStatus::Preparing->value]);
    [, $error] = dataHealthDataset($team, ['name' => 'Broken', 'status' => DatasetStatus::Error->value]);
    $disabledSource = DataSource::factory()->create(['team_id' => $team->id, 'status' => DataSourceStatus::Disabled->value]);
    $inactive = Dataset::factory()->create(['team_id' => $team->id, 'data_source_id' => $disabledSource->id, 'name' => 'Disabled']);
    $foreignDataset = Dataset::factory()->ready()->create(['team_id' => Team::factory()->create()->id, 'name' => 'Foreign']);

    expect($healthy->exists)->toBeTrue();

    $this->actingAs($user)
        ->get(route('data-health.index', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.datasets', 4)
            ->where('datasets.data', function (Collection $items): bool {
                return $items->contains(fn (array $item): bool => $item['name'] === 'Healthy' && $item['health'] === 'healthy')
                    && $items->contains(fn (array $item): bool => $item['name'] === 'Preparing' && $item['health'] === 'warning')
                    && $items->contains(fn (array $item): bool => $item['name'] === 'Broken' && $item['health'] === 'error')
                    && $items->contains(fn (array $item): bool => $item['name'] === 'Disabled' && $item['health'] === 'inactive');
            })
            ->missing('datasets.data.0.records.0.payload'));

    $this->actingAs($user)
        ->get(route('data-health.show', ['current_team' => $team->slug, 'dataset' => $foreignDataset->id]))
        ->assertNotFound();
});

test('data health filters by source and search and limits import history to twenty safe rows', function () {
    [$user, $team] = dataHealthContext();
    [$source, $dataset] = dataHealthDataset($team, ['name' => 'Catalog Products']);
    $otherSource = DataSource::factory()->ready()->create(['team_id' => $team->id]);
    Dataset::factory()->ready()->create(['team_id' => $team->id, 'data_source_id' => $otherSource->id, 'name' => 'Other dataset']);

    for ($index = 0; $index < 21; $index++) {
        SourceRun::factory()->create([
            'data_source_id' => $source->id,
            'dataset_id' => $dataset->id,
            'status' => 'completed',
            'rows_read' => $index,
            'rows_written' => $index,
            'started_at' => now()->subMinutes($index + 5),
            'finished_at' => now()->subMinutes($index + 4),
            'error' => 'secret-token-'.$index,
            'created_at' => now()->subMinutes($index + 5),
        ]);
    }

    $this->actingAs($user)
        ->get(route('data-health.index', [
            'current_team' => $team->slug,
            'data_source' => $source->id,
            'search' => 'Catalog',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.dataSource', $source->id)
            ->where('filters.search', 'Catalog')
            ->where('datasets.total', 1)
            ->where('datasets.data.0.name', 'Catalog Products'));

    $this->actingAs($user)
        ->get(route('data-health.show', ['current_team' => $team->slug, 'dataset' => $dataset->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('importHistory', fn ($history): bool => $history->count() === 20)
            ->where('importHistory.0.rowsRead', 5)
            ->missing('importHistory.0.error')
            ->missing('importHistory.0.metadata')
            ->missing('importHistory.0.secret-token-0'));
});

test('data health rejects a foreign source filter and foreign dataset detail', function () {
    [$user, $team] = dataHealthContext();
    dataHealthDataset($team);
    $foreignTeam = Team::factory()->create();
    $foreignSource = DataSource::factory()->ready()->create(['team_id' => $foreignTeam->id]);
    $foreignDataset = Dataset::factory()->ready()->create(['team_id' => $foreignTeam->id, 'data_source_id' => $foreignSource->id]);

    $this->actingAs($user)
        ->get(route('data-health.index', ['current_team' => $team->slug, 'data_source' => $foreignSource->id]))
        ->assertInertia(fn (Assert $page) => $page->where('summary.datasets', 0)->where('datasets.total', 0));

    $this->actingAs($user)
        ->get(route('data-health.show', ['current_team' => $team->slug, 'dataset' => $foreignDataset->id]))
        ->assertNotFound();
});
