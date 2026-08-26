<?php

use App\Enums\TeamRole;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\DataSource;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Imports\DatasetSnapshotReconciler;
use App\Services\Typesense\TypesenseDatasetSync;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\mock;

function recordManagementContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);
    $dataSource = DataSource::factory()->create(['team_id' => $team->id]);
    $dataset = Dataset::factory()->create([
        'team_id' => $team->id,
        'data_source_id' => $dataSource->id,
    ]);
    $name = DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'name',
        'source_path' => 'name',
        'label' => 'Name',
        'data_type' => 'string',
        'config' => ['required' => true],
        'position' => 0,
    ]);
    $price = DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'price',
        'source_path' => 'price',
        'label' => 'Price',
        'data_type' => 'decimal',
        'normalizer' => 'currency',
        'position' => 1,
    ]);

    return [$user, $team, $dataset, $name, $price];
}

test('team members can browse paginated records and search only the selected dataset', function () {
    [$user, $team, $dataset] = recordManagementContext();
    $otherDataset = Dataset::factory()->create(['team_id' => $team->id]);

    DatasetRecord::factory()->count(26)->create([
        'dataset_id' => $dataset->id,
        'origin' => 'manual',
        'payload' => ['name' => 'Catalog item'],
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'needle',
        'payload' => ['name' => 'Needle item'],
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $otherDataset->id,
        'external_id' => 'needle',
        'payload' => ['name' => 'Other dataset'],
    ]);

    $this->actingAs($user)
        ->get(route('datasets.records.index', [$team->slug, $dataset]).'?search=needle')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('datasets/records/index')
            ->where('records.total', 1)
            ->where('records.data.0.externalId', 'needle')
            ->where('counts.total', 27));
});

test('manual records use field normalization, required validation, and a stable namespace', function () {
    [$user, $team, $dataset] = recordManagementContext();
    $sync = mock(TypesenseDatasetSync::class);
    $sync->shouldReceive('syncRecord')->once();
    app()->instance(TypesenseDatasetSync::class, $sync);

    $this->actingAs($user)
        ->post(route('datasets.records.store', [$team->slug, $dataset]), [
            'values' => ['price' => '$20.00'],
        ])
        ->assertSessionHasErrors('values.name');

    $this->actingAs($user)
        ->post(route('datasets.records.store', [$team->slug, $dataset]), [
            'values' => ['name' => 'Manual item', 'price' => '$20.00'],
        ])
        ->assertRedirect();

    $record = DatasetRecord::query()->latest('id')->firstOrFail();

    expect($record->origin)->toBe('manual')
        ->and(Str::startsWith($record->external_id, 'manual_'))->toBeTrue()
        ->and((float) $record->payload['price'])->toBe(20.0)
        ->and($record->payload['name'])->toBe('Manual item');
});

test('records can be edited and reversibly deactivated without creating ToolRuns', function () {
    [$user, $team, $dataset] = recordManagementContext();
    $record = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'origin' => 'manual',
        'external_id' => 'manual_existing',
        'payload' => ['name' => 'Before', 'price' => 10.0],
    ]);
    $sync = mock(TypesenseDatasetSync::class);
    $sync->shouldReceive('syncRecord')->twice();
    $sync->shouldReceive('removeRecord')->once();
    app()->instance(TypesenseDatasetSync::class, $sync);

    $this->actingAs($user)
        ->patch(route('datasets.records.update', [$team->slug, $dataset, $record]), [
            'values' => ['name' => 'After', 'price' => '12.50'],
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->patch(route('datasets.records.deactivate', [$team->slug, $dataset, $record]))
        ->assertRedirect();

    expect($record->fresh()->external_id)->toBe('manual_existing')
        ->and($record->fresh()->payload['name'])->toBe('After')
        ->and($record->fresh()->is_active)->toBeFalse();

    $this->actingAs($user)
        ->patch(route('datasets.records.activate', [$team->slug, $dataset, $record]))
        ->assertRedirect();

    expect($record->fresh()->is_active)->toBeTrue()
        ->and(
            ToolRun::query()->count(),
        )->toBe(0);
});

test('cross-team dataset records cannot be browsed or mutated', function () {
    [$user, $team] = recordManagementContext();
    $foreignTeam = Team::factory()->create();
    $foreignDataset = Dataset::factory()->create(['team_id' => $foreignTeam->id]);
    $record = DatasetRecord::factory()->create(['dataset_id' => $foreignDataset->id]);

    $this->actingAs($user)
        ->get(route('datasets.records.index', [$team->slug, $foreignDataset]))
        ->assertNotFound();

    $this->actingAs($user)
        ->patch(route('datasets.records.deactivate', [$team->slug, $foreignDataset, $record]))
        ->assertNotFound();
});

test('manual records survive source snapshot reconciliation while source records still reconcile', function () {
    [$user, $team, $dataset] = recordManagementContext();
    $dataset->dataSource()->update(['type' => 'rest_api']);
    $dataset->load('dataSource');

    $sourceRecord = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'source-a',
        'origin' => 'rest_api',
        'is_active' => true,
    ]);
    $manualRecord = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'manual_x',
        'origin' => 'manual',
        'is_active' => true,
    ]);

    app(DatasetSnapshotReconciler::class)->reconcile($dataset, []);

    expect($sourceRecord->fresh()->is_active)->toBeFalse()
        ->and($manualRecord->fresh()->is_active)->toBeTrue();
});

test('manual records remain untouched by incremental source writes', function () {
    [, , $dataset] = recordManagementContext();
    $dataset->dataSource()->update(['type' => 'rest_api']);
    $dataset->load('dataSource');
    $manualRecord = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'manual_incremental',
        'origin' => 'manual',
        'is_active' => true,
    ]);

    app(DatasetSnapshotReconciler::class)->writeIncremental($dataset, [[
        'dataset_id' => $dataset->id,
        'external_id' => 'source-incremental',
        'payload' => ['name' => 'Source update'],
        'searchable_text' => 'Source update',
        'checksum' => hash('sha256', 'source-incremental'),
        'is_active' => true,
        'source_updated_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]]);

    expect($manualRecord->fresh()->is_active)->toBeTrue()
        ->and(DatasetRecord::query()->where('external_id', 'source-incremental')->value('origin'))->toBe('rest_api');
});
