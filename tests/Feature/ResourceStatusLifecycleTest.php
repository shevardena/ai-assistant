<?php

use App\Models\Bot;
use App\Models\BotDataset;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Services\ResourceStatusService;

test('data source transitions preserve the last successful synchronization timestamp', function () {
    $dataSource = DataSource::factory()->create([
        'status' => 'pending',
        'last_synced_at' => null,
    ]);
    $service = app(ResourceStatusService::class);

    $service->markDataSourceReady($dataSource);

    expect($dataSource->fresh()->status)->toBe('ready')
        ->and($dataSource->fresh()->last_synced_at)->toBeNull();

    $previousSync = now()->subDay();
    $dataSource->update([
        'status' => 'ready',
        'last_synced_at' => $previousSync,
    ]);
    $service->markDataSourceSyncing($dataSource);
    $service->markDataSourceError($dataSource);

    expect($dataSource->fresh()->status)->toBe('error')
        ->and($dataSource->fresh()->last_synced_at?->timestamp)->toBe($previousSync->timestamp);

    $service->markDataSourceReady($dataSource, now());

    expect($dataSource->fresh()->status)->toBe('ready')
        ->and($dataSource->fresh()->last_synced_at)->not->toBeNull();
});

test('dataset readiness refreshes attached bot status and recovers from errors', function () {
    $team = DataSource::factory()->create()->team;
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $dataset = Dataset::factory()->create([
        'team_id' => $team->id,
        'status' => 'preparing',
    ]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'is_enabled' => true,
    ]);
    $service = app(ResourceStatusService::class);

    $service->markDatasetProcessing($dataset);
    expect($bot->fresh()->status)->toBe('draft');

    $service->markDatasetReady($dataset);
    expect($dataset->fresh()->status)->toBe('ready')
        ->and($bot->fresh()->status)->toBe('ready');

    $service->markDatasetError($dataset);
    expect($dataset->fresh()->status)->toBe('error')
        ->and($bot->fresh()->status)->toBe('draft');

    $service->markDatasetReady($dataset);
    expect($bot->fresh()->status)->toBe('ready');
});

test('bot readiness requires an enabled ready dataset in its own team', function () {
    $team = DataSource::factory()->create()->team;
    $otherTeam = DataSource::factory()->create()->team;
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $disabledReadyDataset = Dataset::factory()->ready()->create(['team_id' => $team->id]);
    $errorDataset = Dataset::factory()->create([
        'team_id' => $team->id,
        'status' => 'error',
    ]);
    $otherTeamDataset = Dataset::factory()->ready()->create(['team_id' => $otherTeam->id]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $disabledReadyDataset->id,
        'is_enabled' => false,
    ]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $errorDataset->id,
        'is_enabled' => true,
    ]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $otherTeamDataset->id,
        'is_enabled' => true,
    ]);

    app(ResourceStatusService::class)->refreshBotStatus($bot);

    expect($bot->fresh()->status)->toBe('draft');

    $enabledReadyDataset = Dataset::factory()->ready()->create(['team_id' => $team->id]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $enabledReadyDataset->id,
        'is_enabled' => true,
    ]);

    app(ResourceStatusService::class)->refreshBotStatus($bot);

    expect($bot->fresh()->status)->toBe('ready');
});
