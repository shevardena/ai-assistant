<?php

use App\Enums\ApiOperationMode;
use App\Enums\ApiOperationSyncFrequency;
use App\Enums\ApiOperationSyncStrategy;
use App\Jobs\RunApiOperationSync;
use App\Models\ApiOperation;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\DataSource;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Sync\ApiOperationSyncScheduleService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** @return array{0: User, 1: DataSource, 2: Dataset, 3: ApiOperation} */
function syncOperationContext(array $operationOverrides = []): array
{
    $user = User::factory()->create();
    $source = DataSource::factory()->create([
        'team_id' => $user->currentTeam->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
    ]);
    $dataset = Dataset::factory()->create([
        'team_id' => $user->currentTeam->id,
        'data_source_id' => $source->id,
        'primary_key_path' => 'id',
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'source_path' => 'id',
        'key' => 'id',
        'data_type' => 'string',
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'source_path' => 'name',
        'key' => 'name',
        'data_type' => 'string',
    ]);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $source->id,
        'type' => 'query',
        'execution_mode' => ApiOperationMode::Read->value,
        'method' => 'GET',
        'path' => '/products',
        'response_mapping' => [
            'records_path' => 'products',
            'sync_mode' => 'full_snapshot',
        ],
        ...$operationOverrides,
    ]);

    return [$user, $source, $dataset, $operation];
}

test('synced operations default to a disabled manual schedule', function () {
    [, , , $operation] = syncOperationContext();

    $schedule = $operation->syncSchedule()->firstOrFail();

    expect($schedule->frequency)->toBe(ApiOperationSyncFrequency::Manual)
        ->and($schedule->strategy)->toBe(ApiOperationSyncStrategy::FullSnapshot)
        ->and($schedule->is_enabled)->toBeFalse()
        ->and($schedule->next_run_at)->toBeNull();
});

test('incremental sync upserts changes without deactivating absent records and advances checkpoint after success', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response([
            'products' => [['id' => 'B', 'name' => 'Updated B']],
            'meta' => ['next_cursor' => 'cursor-2'],
        ]),
    ]);
    [$user, , $dataset, $operation] = syncOperationContext();
    DatasetRecord::factory()->createMany([
        ['dataset_id' => $dataset->id, 'external_id' => 'A', 'payload' => ['name' => 'A']],
        ['dataset_id' => $dataset->id, 'external_id' => 'B', 'payload' => ['name' => 'B']],
    ]);
    $schedule = $operation->syncSchedule()->firstOrFail();
    $schedule->forceFill([
        'dataset_id' => $dataset->id,
        'strategy' => ApiOperationSyncStrategy::Cursor,
        'configuration' => ['cursor' => ['target' => 'query', 'name' => 'cursor', 'response_path' => 'meta.next_cursor']],
        'checkpoint' => 'cursor-1',
    ])->save();

    $claimed = app(ApiOperationSyncScheduleService::class)->claimManual($operation, $dataset);
    app(ApiOperationSyncScheduleService::class)->run($claimed);

    $records = DatasetRecord::query()->where('dataset_id', $dataset->id)->get()->keyBy('external_id');
    $freshSchedule = $schedule->fresh();

    expect($records)->toHaveCount(2)
        ->and($records->get('A')->is_active)->toBeTrue()
        ->and($records->get('B')->payload['name'])->toBe('Updated B')
        ->and($freshSchedule->checkpoint)->toBe('cursor-2')
        ->and($freshSchedule->consecutive_failures)->toBe(0)
        ->and(ToolRun::query()->count())->toBe(0)
        ->and($user->currentTeam->id)->toBe($dataset->team_id);
});

test('checkpoint remains unchanged when incremental synchronization fails', function () {
    Http::fake(['https://api.example.test/*' => Http::response([], 500)]);
    [, , $dataset, $operation] = syncOperationContext();
    $schedule = $operation->syncSchedule()->firstOrFail();
    $schedule->forceFill([
        'dataset_id' => $dataset->id,
        'strategy' => ApiOperationSyncStrategy::UpdatedSince,
        'configuration' => ['updated_since' => ['target' => 'query', 'name' => 'updated_after', 'response_path' => 'meta.next']],
        'checkpoint' => '2026-08-23T00:00:00+00:00',
    ])->save();
    $claimed = app(ApiOperationSyncScheduleService::class)->claimManual($operation, $dataset);

    expect(fn () => app(ApiOperationSyncScheduleService::class)->run($claimed))
        ->toThrow(ImportException::class);

    expect($schedule->fresh()->checkpoint)->toBe('2026-08-23T00:00:00+00:00')
        ->and($schedule->fresh()->consecutive_failures)->toBe(1)
        ->and($schedule->fresh()->last_error)->not->toContain('top-secret');
});

test('live operations cannot be scheduled or run', function () {
    [, , $dataset, $operation] = syncOperationContext([
        'type' => 'read',
        'response_mapping' => ['output' => ['name' => 'name']],
    ]);
    $service = app(ApiOperationSyncScheduleService::class);

    expect(fn () => $service->ensure($operation, $dataset))
        ->toThrow(ImportException::class);
});

test('due work is dispatched once and paused work is ignored', function () {
    Queue::fake();
    [, , $dataset, $operation] = syncOperationContext();
    $schedule = $operation->syncSchedule()->firstOrFail();
    $schedule->forceFill([
        'dataset_id' => $dataset->id,
        'frequency' => ApiOperationSyncFrequency::Hourly,
        'is_enabled' => true,
        'next_run_at' => now()->subHour(),
    ])->save();

    $this->artisan('api-operations:dispatch-due-syncs')->assertSuccessful();
    Queue::assertPushed(RunApiOperationSync::class, 1);

    $this->artisan('api-operations:dispatch-due-syncs')->assertSuccessful();
    Queue::assertPushed(RunApiOperationSync::class, 1);

    $schedule->forceFill(['is_enabled' => false, 'locked_until' => null, 'next_run_at' => now()->subHour()])->save();

    $this->artisan('api-operations:dispatch-due-syncs')->assertSuccessful();
    Queue::assertPushed(RunApiOperationSync::class, 1);
});
