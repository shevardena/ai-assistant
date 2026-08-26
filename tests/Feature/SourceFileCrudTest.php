<?php

use App\Models\DataSource;
use App\Models\SourceFile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{0: User, 1: Team, 2: DataSource}
 */
function sourceFileContext(string $type = 'file'): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $dataSource = DataSource::factory()->create([
        'team_id' => $team->id,
        'type' => $type,
    ]);

    return [$user, $team, $dataSource];
}

function sourceFileUpload(string $name, string $mimeType): UploadedFile
{
    return UploadedFile::fake()->create($name, 10, $mimeType);
}

test('the data source show page lists source file metadata without exposing storage paths', function () {
    [$user, $team, $dataSource] = sourceFileContext();
    $sourceFile = SourceFile::factory()->create([
        'data_source_id' => $dataSource->id,
        'uploaded_by' => $user->id,
        'path' => 'source-files/'.$team->id.'/'.$dataSource->id.'/stored.csv',
        'original_name' => 'products.csv',
        'metadata' => ['extension' => 'csv'],
    ]);

    $this->actingAs($user)
        ->get(route('data-sources.show', [
            'current_team' => $team->slug,
            'data_source' => $dataSource,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-sources/show')
            ->where('dataSource.sourceFiles.0.id', $sourceFile->id)
            ->where('dataSource.sourceFiles.0.originalName', 'products.csv')
            ->missing('dataSource.sourceFiles.0.path')
            ->missing('dataSource.sourceFiles.0.disk'),
        );
});

test('a current team user can upload a CSV source file', function () {
    Storage::fake('local');
    [$user, $team, $dataSource] = sourceFileContext();

    $response = $this->actingAs($user)->post(
        route('data-sources.files.store', [
            'current_team' => $team->slug,
            'data_source' => $dataSource,
        ]),
        ['file' => sourceFileUpload('products.csv', 'text/csv')],
    );

    $sourceFile = SourceFile::query()->firstOrFail();

    $response->assertRedirect(route('data-sources.show', [
        'current_team' => $team->slug,
        'data_source' => $dataSource,
    ]));

    expect($sourceFile->data_source_id)->toBe($dataSource->id)
        ->and($sourceFile->uploaded_by)->toBe($user->id)
        ->and($sourceFile->disk)->toBe('local')
        ->and($sourceFile->original_name)->toBe('products.csv')
        ->and($sourceFile->mime_type)->toBe('text/csv')
        ->and($sourceFile->status)->toBe('uploaded')
        ->and($sourceFile->metadata)->toBe(['extension' => 'csv'])
        ->and($sourceFile->checksum)->toHaveLength(64)
        ->and($dataSource->fresh()->status)->toBe('ready')
        ->and($dataSource->fresh()->last_synced_at)->toBeNull();

    Storage::disk('local')->assertExists($sourceFile->path);
    expect($sourceFile->path)->toStartWith("source-files/{$team->id}/{$dataSource->id}/")
        ->and($sourceFile->path)->not->toContain('products.csv');
});

test('JSON and XLSX source files are accepted', function (string $name, string $mimeType): void {
    Storage::fake('local');
    [$user, $team, $dataSource] = sourceFileContext();

    $this->actingAs($user)->post(
        route('data-sources.files.store', [
            'current_team' => $team->slug,
            'data_source' => $dataSource,
        ]),
        ['file' => sourceFileUpload($name, $mimeType)],
    )->assertRedirect();

    expect(SourceFile::query()->count())->toBe(1);
    Storage::disk('local')->assertExists(SourceFile::query()->firstOrFail()->path);
})->with([
    ['catalog.json', 'application/json'],
    ['catalog.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
]);

test('submitted ownership fields cannot re-parent an uploaded source file', function () {
    Storage::fake('local');
    [$user, $team, $dataSource] = sourceFileContext();
    $otherTeam = Team::factory()->create();

    $this->actingAs($user)->post(
        route('data-sources.files.store', [
            'current_team' => $team->slug,
            'data_source' => $dataSource,
        ]),
        [
            'file' => sourceFileUpload('products.csv', 'text/csv'),
            'team_id' => $otherTeam->id,
            'data_source_id' => $otherTeam->id,
        ],
    )->assertRedirect();

    expect(SourceFile::query()->firstOrFail()->data_source_id)->toBe($dataSource->id);
});

test('REST API data sources reject source file uploads', function () {
    Storage::fake('local');
    [$user, $team, $dataSource] = sourceFileContext('rest_api');

    $this->actingAs($user)->post(
        route('data-sources.files.store', [
            'current_team' => $team->slug,
            'data_source' => $dataSource,
        ]),
        ['file' => sourceFileUpload('products.csv', 'text/csv')],
    )->assertSessionHasErrors('data_source');

    expect(SourceFile::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('a cross-team data source cannot receive an upload', function () {
    Storage::fake('local');
    [$user, $team] = sourceFileContext();
    $otherTeam = Team::factory()->create();
    $otherDataSource = DataSource::factory()->create(['team_id' => $otherTeam->id]);

    $this->actingAs($user)->post(
        route('data-sources.files.store', [
            'current_team' => $team->slug,
            'data_source' => $otherDataSource,
        ]),
        ['file' => sourceFileUpload('products.csv', 'text/csv')],
    )->assertForbidden();

    expect(SourceFile::query()->count())->toBe(0);
});

test('unsupported, missing, and oversized files are rejected without storage', function () {
    Storage::fake('local');
    [$user, $team, $dataSource] = sourceFileContext();

    $route = route('data-sources.files.store', [
        'current_team' => $team->slug,
        'data_source' => $dataSource,
    ]);

    $this->actingAs($user)->post($route)->assertSessionHasErrors('file');
    $this->actingAs($user)->post($route, [
        'file' => sourceFileUpload('malware.exe', 'application/octet-stream'),
    ])->assertSessionHasErrors('file');
    $this->actingAs($user)->post($route, [
        'file' => UploadedFile::fake()->create('large.csv', 25601, 'text/csv'),
    ])->assertSessionHasErrors('file');

    expect(SourceFile::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('a current team user can delete their source file and its physical file', function () {
    Storage::fake('local');
    [$user, $team, $dataSource] = sourceFileContext();
    $path = "source-files/{$team->id}/{$dataSource->id}/stored.csv";
    Storage::disk('local')->put($path, 'id,name');
    $sourceFile = SourceFile::factory()->create([
        'data_source_id' => $dataSource->id,
        'uploaded_by' => $user->id,
        'disk' => 'local',
        'path' => $path,
    ]);

    $this->actingAs($user)
        ->delete(route('data-sources.files.destroy', [
            'current_team' => $team->slug,
            'data_source' => $dataSource,
            'file' => $sourceFile,
        ]))
        ->assertRedirect(route('data-sources.show', [
            'current_team' => $team->slug,
            'data_source' => $dataSource,
        ]));

    $this->assertModelMissing($sourceFile);
    Storage::disk('local')->assertMissing($path);
});

test('a cross-team user cannot delete a source file', function () {
    Storage::fake('local');
    [$user, $team] = sourceFileContext();
    $otherTeam = Team::factory()->create();
    $otherDataSource = DataSource::factory()->create(['team_id' => $otherTeam->id]);
    $path = "source-files/{$otherTeam->id}/{$otherDataSource->id}/stored.csv";
    Storage::disk('local')->put($path, 'id,name');
    $sourceFile = SourceFile::factory()->create([
        'data_source_id' => $otherDataSource->id,
        'disk' => 'local',
        'path' => $path,
    ]);

    $this->actingAs($user)
        ->delete(route('data-sources.files.destroy', [
            'current_team' => $team->slug,
            'data_source' => $otherDataSource,
            'file' => $sourceFile,
        ]))
        ->assertForbidden();

    expect(SourceFile::query()->find($sourceFile->id))->not->toBeNull();
    Storage::disk('local')->assertExists($path);
});

test('a source file cannot be deleted through a different data source parent route', function () {
    Storage::fake('local');
    [$user, $team, $dataSource] = sourceFileContext();
    $otherDataSource = DataSource::factory()->create(['team_id' => $team->id]);
    $path = "source-files/{$team->id}/{$dataSource->id}/stored.csv";
    Storage::disk('local')->put($path, 'id,name');
    $sourceFile = SourceFile::factory()->create([
        'data_source_id' => $dataSource->id,
        'disk' => 'local',
        'path' => $path,
    ]);

    $this->actingAs($user)
        ->delete(route('data-sources.files.destroy', [
            'current_team' => $team->slug,
            'data_source' => $otherDataSource,
            'file' => $sourceFile,
        ]))
        ->assertNotFound();

    expect(SourceFile::query()->find($sourceFile->id))->not->toBeNull();
    Storage::disk('local')->assertExists($path);
});
