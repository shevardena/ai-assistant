<?php

use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\DataSource;
use App\Models\SourceFile;
use App\Models\SourceRun;
use App\Models\Team;
use App\Models\User;
use App\Services\Typesense\TypesenseDatasetIndexer;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{0: User, 1: Team, 2: DataSource, 3: Dataset}
 */
function importContext(string $sourceType = 'file'): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $dataSource = DataSource::factory()->create([
        'team_id' => $team->id,
        'type' => $sourceType,
    ]);
    $dataset = Dataset::factory()->create([
        'team_id' => $team->id,
        'data_source_id' => $dataSource->id,
        'primary_key_path' => 'id',
    ]);

    return [$user, $team, $dataSource, $dataset];
}

function importSourceFile(User $user, DataSource $dataSource, string $path, string $contents, string $originalName = 'products.csv'): SourceFile
{
    Storage::disk('local')->put($path, $contents);

    return SourceFile::factory()->create([
        'data_source_id' => $dataSource->id,
        'uploaded_by' => $user->id,
        'disk' => 'local',
        'path' => $path,
        'original_name' => $originalName,
        'metadata' => ['extension' => pathinfo($originalName, PATHINFO_EXTENSION)],
    ]);
}

function importFields(Dataset $dataset): void
{
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'source_path' => 'title',
        'key' => 'name',
        'data_type' => 'string',
        'position' => 1,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'source_path' => 'price',
        'key' => 'price',
        'data_type' => 'decimal',
        'position' => 2,
    ]);
}

test('imports mapped CSV rows into canonical dataset records', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    importFields($dataset);
    $sourceFile = importSourceFile(
        $user,
        $dataSource,
        "source-files/{$team->id}/{$dataSource->id}/products.csv",
        "id,title,price,secret\nsku-1,Phone,3499.00,hidden\nsku-2,Tablet,999.50,hidden\n",
    );

    $response = $this->actingAs($user)->post(route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]), ['source_file_id' => $sourceFile->id]);

    $response->assertRedirect(route('datasets.show', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]));

    $run = SourceRun::query()->firstOrFail();
    $records = DatasetRecord::query()->orderBy('external_id')->get();

    expect($run->type)->toBe('import')
        ->and($run->status)->toBe('completed')
        ->and($run->rows_read)->toBe(2)
        ->and($run->rows_written)->toBe(2)
        ->and($run->rows_failed)->toBe(0)
        ->and($records)->toHaveCount(2)
        ->and($records[0]->payload)->toBe(['name' => 'Phone', 'price' => 3499.0])
        ->and($records[0]->payload)->not->toHaveKey('secret')
        ->and($records[0]->dataset_id)->toBe($dataset->id)
        ->and($dataSource->fresh()->status)->toBe('ready')
        ->and($dataSource->fresh()->last_synced_at)->not->toBeNull()
        ->and($dataset->fresh()->status)->toBe('ready')
        ->and($sourceFile->fresh()->status)->toBe('ready');
});

test('continues after invalid rows and stores structured row errors', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    importFields($dataset);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'source_path' => 'quantity',
        'key' => 'quantity',
        'data_type' => 'integer',
        'position' => 3,
    ]);
    $sourceFile = importSourceFile(
        $user,
        $dataSource,
        "source-files/{$team->id}/{$dataSource->id}/products.csv",
        "id,title,price,quantity\nsku-1,Phone,3499.00,2\nsku-2,Tablet,999.50,nope\n",
    );

    $this->actingAs($user)->post(route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]), ['source_file_id' => $sourceFile->id])->assertRedirect();

    $run = SourceRun::query()->firstOrFail();

    expect($run->status)->toBe('completed')
        ->and($run->rows_read)->toBe(2)
        ->and($run->rows_written)->toBe(1)
        ->and($run->rows_failed)->toBe(1)
        ->and($run->metadata['row_errors'][0]['row'])->toBe(2)
        ->and(DatasetRecord::query()->count())->toBe(1);
});

test('a Typesense sync failure does not change authoritative ready status', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    importFields($dataset);
    $sourceFile = importSourceFile(
        $user,
        $dataSource,
        "source-files/{$team->id}/{$dataSource->id}/products.csv",
        "id,title,price\nsku-1,Phone,3499.00\n",
    );
    config(['search.typesense.sync_after_import' => true]);

    $indexer = Mockery::mock(TypesenseDatasetIndexer::class);
    $indexer->shouldReceive('reindex')
        ->once()
        ->andThrow(new RuntimeException('Typesense unavailable.'));
    $this->app->instance(TypesenseDatasetIndexer::class, $indexer);

    $this->actingAs($user)->post(route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]), ['source_file_id' => $sourceFile->id])->assertRedirect();

    expect(SourceRun::query()->firstOrFail()->status)->toBe('completed')
        ->and($dataSource->fresh()->status)->toBe('ready')
        ->and($dataset->fresh()->status)->toBe('ready');
});

test('re-imports upsert by the configured primary key instead of duplicating records', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    importFields($dataset);
    $path = "source-files/{$team->id}/{$dataSource->id}/products.csv";
    $sourceFile = importSourceFile($user, $dataSource, $path, "id,title,price\nsku-1,Phone,100\n");
    $route = route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]);

    $this->actingAs($user)->post($route, ['source_file_id' => $sourceFile->id]);
    Storage::disk('local')->put($path, "id,title,price\nsku-1,Updated Phone,125\n");
    $sourceFile->update(['status' => 'uploaded']);
    $dataSource->update(['status' => 'error']);
    $dataset->update(['status' => 'error']);
    $this->actingAs($user)->post($route, ['source_file_id' => $sourceFile->id]);

    expect(DatasetRecord::query()->count())->toBe(1)
        ->and(DatasetRecord::query()->firstOrFail()->payload)->toBe([
            'name' => 'Updated Phone',
            'price' => 125.0,
        ])
        ->and(SourceRun::query()->count())->toBe(2)
        ->and($dataSource->fresh()->status)->toBe('ready')
        ->and($dataset->fresh()->status)->toBe('ready');
});

test('imports top-level JSON arrays', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    importFields($dataset);
    $sourceFile = importSourceFile(
        $user,
        $dataSource,
        "source-files/{$team->id}/{$dataSource->id}/products.json",
        json_encode([
            ['id' => 'sku-1', 'title' => 'Phone', 'price' => '10.50'],
        ], JSON_THROW_ON_ERROR),
        'products.json',
    );

    $this->actingAs($user)->post(route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]), ['source_file_id' => $sourceFile->id])->assertRedirect();

    expect(DatasetRecord::query()->firstOrFail()->payload)->toBe([
        'name' => 'Phone',
        'price' => 10.5,
    ]);
});

test('a dataset without field mappings cannot import', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    $sourceFile = importSourceFile(
        $user,
        $dataSource,
        "source-files/{$team->id}/{$dataSource->id}/products.csv",
        "id,title\nsku-1,Phone\n",
    );

    $this->actingAs($user)->post(route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]), ['source_file_id' => $sourceFile->id])
        ->assertSessionHasErrors('source_file_id');

    expect(SourceRun::query()->count())->toBe(0)
        ->and(DatasetRecord::query()->count())->toBe(0);
});

test('a source file from another data source is rejected', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    importFields($dataset);
    $otherDataSource = DataSource::factory()->create(['team_id' => $team->id, 'type' => 'file']);
    $sourceFile = importSourceFile(
        $user,
        $otherDataSource,
        "source-files/{$team->id}/{$otherDataSource->id}/other.csv",
        "id,title,price\nsku-1,Phone,10\n",
    );

    $this->actingAs($user)->post(route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]), ['source_file_id' => $sourceFile->id])
        ->assertSessionHasErrors('source_file_id');

    expect(SourceRun::query()->count())->toBe(0);
});

test('cross-team users cannot import into a dataset', function () {
    Storage::fake('local');
    [$user, $team] = importContext();
    $otherTeam = Team::factory()->create();
    $otherDataSource = DataSource::factory()->create(['team_id' => $otherTeam->id, 'type' => 'file']);
    $otherDataset = Dataset::factory()->create([
        'team_id' => $otherTeam->id,
        'data_source_id' => $otherDataSource->id,
        'primary_key_path' => 'id',
    ]);

    $this->actingAs($user)->post(route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $otherDataset,
    ]), ['source_file_id' => 1])->assertForbidden();
});

test('malformed files create a failed source run without exposing parser details', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    importFields($dataset);
    $existingRecords = DatasetRecord::factory()->count(2)->create([
        'dataset_id' => $dataset->id,
        'is_active' => true,
    ]);
    $previousSync = now()->subDay();
    $dataSource->update(['status' => 'ready', 'last_synced_at' => $previousSync]);
    $dataset->update(['status' => 'ready']);
    $sourceFile = importSourceFile(
        $user,
        $dataSource,
        "source-files/{$team->id}/{$dataSource->id}/products.json",
        '{not-json',
        'products.json',
    );

    $this->actingAs($user)->post(route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]), ['source_file_id' => $sourceFile->id])
        ->assertSessionHasErrors('source_file_id');

    expect(SourceRun::query()->firstOrFail()->status)->toBe('failed')
        ->and(SourceRun::query()->firstOrFail()->error)->toBe('The JSON file is malformed.')
        ->and($sourceFile->fresh()->status)->toBe('failed')
        ->and($dataSource->fresh()->status)->toBe('error')
        ->and($dataset->fresh()->status)->toBe('error')
        ->and($dataSource->fresh()->last_synced_at?->timestamp)->toBe($previousSync->timestamp)
        ->and($existingRecords->every(fn (DatasetRecord $record): bool => $record->fresh()->is_active))->toBeTrue();
});

test('full snapshot imports deactivate missing records without deleting them', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    importFields($dataset);
    $path = "source-files/{$team->id}/{$dataSource->id}/products.csv";
    $sourceFile = importSourceFile(
        $user,
        $dataSource,
        $path,
        "id,title,price\nA,Alpha,10\nB,Beta,20\nC,Gamma,30\n",
    );
    $route = route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]);

    $this->actingAs($user)->post($route, ['source_file_id' => $sourceFile->id]);
    Storage::disk('local')->put($path, "id,title,price\nA,Alpha updated,11\nC,Gamma,30\nD,Delta,40\n");
    $sourceFile->update(['status' => 'uploaded']);
    $this->actingAs($user)->post($route, ['source_file_id' => $sourceFile->id]);

    $records = DatasetRecord::query()
        ->where('dataset_id', $dataset->id)
        ->get()
        ->keyBy('external_id');
    $run = SourceRun::query()->latest('id')->firstOrFail();

    expect($records)->toHaveCount(4)
        ->and($records->get('A')->is_active)->toBeTrue()
        ->and($records->get('B')->is_active)->toBeFalse()
        ->and($records->get('C')->is_active)->toBeTrue()
        ->and($records->get('D')->is_active)->toBeTrue()
        ->and($records->get('B')->exists)->toBeTrue()
        ->and($run->status)->toBe('completed')
        ->and($run->metadata['records_deactivated'])->toBe(1);
});

test('a later full snapshot reactivates a previously inactive record', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    importFields($dataset);
    $path = "source-files/{$team->id}/{$dataSource->id}/products.csv";
    $sourceFile = importSourceFile($user, $dataSource, $path, "id,title,price\nA,Alpha,10\n");
    $route = route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]);

    $this->actingAs($user)->post($route, ['source_file_id' => $sourceFile->id]);
    DatasetRecord::query()->where('dataset_id', $dataset->id)->update(['is_active' => false]);
    $sourceFile->update(['status' => 'uploaded']);
    $this->actingAs($user)->post($route, ['source_file_id' => $sourceFile->id]);

    $record = DatasetRecord::query()->where('dataset_id', $dataset->id)->firstOrFail();
    $run = SourceRun::query()->latest('id')->firstOrFail();

    expect($record->is_active)->toBeTrue()
        ->and($run->metadata['records_reactivated'])->toBe(1);
});

test('records from another dataset are not affected by reconciliation', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    importFields($dataset);
    $otherDataSource = DataSource::factory()->create(['team_id' => $team->id, 'type' => 'file']);
    $otherDataset = Dataset::factory()->create([
        'team_id' => $team->id,
        'data_source_id' => $otherDataSource->id,
        'primary_key_path' => 'id',
    ]);
    DatasetRecord::factory()->create(['dataset_id' => $dataset->id, 'external_id' => 'B', 'is_active' => true]);
    DatasetRecord::factory()->create(['dataset_id' => $otherDataset->id, 'external_id' => 'B', 'is_active' => true]);
    DatasetRecord::factory()->create(['dataset_id' => $otherDataset->id, 'external_id' => 'C', 'is_active' => true]);

    $sourceFile = importSourceFile(
        $user,
        $dataSource,
        "source-files/{$team->id}/{$dataSource->id}/products.csv",
        "id,title,price\nA,Alpha,10\n",
    );

    $this->actingAs($user)->post(route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]), ['source_file_id' => $sourceFile->id]);

    expect(DatasetRecord::query()->where('dataset_id', $dataset->id)->where('external_id', 'B')->value('is_active'))->toBeFalse()
        ->and(DatasetRecord::query()->where('dataset_id', $otherDataset->id)->where('external_id', 'B')->value('is_active'))->toBeTrue()
        ->and(DatasetRecord::query()->where('dataset_id', $otherDataset->id)->where('external_id', 'C')->value('is_active'))->toBeTrue();
});

test('a row normalization failure does not deactivate its existing external id', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    importFields($dataset);
    $existingB = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'B',
        'payload' => ['name' => 'Previous beta', 'price' => 20.0],
        'is_active' => true,
    ]);
    $sourceFile = importSourceFile(
        $user,
        $dataSource,
        "source-files/{$team->id}/{$dataSource->id}/products.csv",
        "id,title,price\nA,Alpha,10\nB,Beta,not-a-number\n",
    );

    $this->actingAs($user)->post(route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]), ['source_file_id' => $sourceFile->id]);

    $run = SourceRun::query()->firstOrFail();
    $existingB->refresh();

    expect($run->status)->toBe('completed')
        ->and($run->rows_failed)->toBe(1)
        ->and($run->metadata['seen_external_id_count'])->toBe(2)
        ->and($existingB->is_active)->toBeTrue()
        ->and($existingB->payload['name'])->toBe('Previous beta')
        ->and((float) $existingB->payload['price'])->toBe(20.0);
});

test('an empty valid JSON snapshot deactivates all existing records', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    importFields($dataset);
    $records = DatasetRecord::factory()->count(2)->create([
        'dataset_id' => $dataset->id,
        'is_active' => true,
    ]);
    $sourceFile = importSourceFile(
        $user,
        $dataSource,
        "source-files/{$team->id}/{$dataSource->id}/products.json",
        '[]',
        'products.json',
    );

    $this->actingAs($user)->post(route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]), ['source_file_id' => $sourceFile->id]);

    $run = SourceRun::query()->firstOrFail();

    expect($run->status)->toBe('completed')
        ->and($run->rows_read)->toBe(0)
        ->and($run->rows_written)->toBe(0)
        ->and($run->metadata['records_deactivated'])->toBe(2)
        ->and($records->every(fn (DatasetRecord $record): bool => ! $record->fresh()->is_active))->toBeTrue()
        ->and($sourceFile->fresh()->status)->toBe('ready');
});

test('duplicate external ids fail the import before writing records', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = importContext();
    importFields($dataset);
    $existingRecord = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'A',
        'payload' => ['name' => 'Previous value', 'price' => 5.0],
        'is_active' => true,
    ]);
    $sourceFile = importSourceFile(
        $user,
        $dataSource,
        "source-files/{$team->id}/{$dataSource->id}/products.csv",
        "id,title,price\nA,First,10\nA,Last,20\n",
    );

    $this->actingAs($user)->post(route('datasets.imports.store', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]), ['source_file_id' => $sourceFile->id]);

    $run = SourceRun::query()->firstOrFail();
    $existingRecord->refresh();

    expect(DatasetRecord::query()->count())->toBe(1)
        ->and($existingRecord->payload['name'])->toBe('Previous value')
        ->and((float) $existingRecord->payload['price'])->toBe(5.0)
        ->and($existingRecord->is_active)->toBeTrue()
        ->and($run->status)->toBe('failed')
        ->and($run->error)->toBe('The source file contains duplicate external IDs.')
        ->and($sourceFile->fresh()->status)->toBe('failed');
});
