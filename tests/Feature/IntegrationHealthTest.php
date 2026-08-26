<?php

use App\Enums\ApiOperationMode;
use App\Enums\DataSourceStatus;
use App\Enums\TeamRole;
use App\Enums\ToolRunStatus;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\DataSource;
use App\Models\SourceRun;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function (): void {
    Carbon::setTestNow();
});

function integrationHealthContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Integration Health Team']);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

function integrationHealthRun(DataSource $dataSource, array $attributes = []): SourceRun
{
    return SourceRun::factory()->create([
        'data_source_id' => $dataSource->id,
        'status' => 'completed',
        'rows_read' => 12,
        'rows_written' => 10,
        'rows_failed' => 2,
        'started_at' => now()->subSeconds(4),
        'finished_at' => now()->subSeconds(2),
        ...$attributes,
    ]);
}

test('integration health is isolated to the selected team and classifies deterministic states', function () {
    [$user, $team] = integrationHealthContext();
    $healthy = DataSource::factory()->ready()->create(['team_id' => $team->id, 'name' => 'Healthy source']);
    integrationHealthRun($healthy);
    $error = DataSource::factory()->create(['team_id' => $team->id, 'name' => 'Error source', 'status' => DataSourceStatus::Error->value]);
    integrationHealthRun($error, ['status' => 'failed', 'error' => 'Provider password top-secret failed', 'finished_at' => now()->subHour()]);
    $inactive = DataSource::factory()->create(['team_id' => $team->id, 'name' => 'Inactive source', 'status' => DataSourceStatus::Disabled->value]);
    $foreignTeam = Team::factory()->create();
    $foreign = DataSource::factory()->ready()->create(['team_id' => $foreignTeam->id, 'name' => 'Foreign source']);
    integrationHealthRun($foreign);

    $this->actingAs($user)
        ->get(route('integration-health.index', ['current_team' => $team->slug]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('integrations/health/index')
            ->where('filters.range', '7d')
            ->where('summary.integrations', 3)
            ->where('summary.healthy', 1)
            ->where('summary.errors', 1)
            ->where('summary.inactive', 1)
            ->where('items.0.name', 'Error source')
            ->where('items.0.health', 'error')
            ->where('items.1.name', 'Healthy source')
            ->where('items.1.health', 'healthy')
            ->where('items.2.health', 'inactive')
            ->missing('Foreign source')
            ->missing('top-secret')
            ->missing('password'));
});

test('integration health exposes source run metrics and safe failure labels only', function () {
    [$user, $team] = integrationHealthContext();
    $source = DataSource::factory()->create(['team_id' => $team->id, 'status' => DataSourceStatus::Ready->value]);
    integrationHealthRun($source, [
        'rows_read' => 20,
        'rows_written' => 18,
        'rows_failed' => 2,
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
    ]);
    integrationHealthRun($source, [
        'status' => 'failed',
        'error' => 'The provider response contained an invalid JSON payload and secret-token-123',
        'finished_at' => now()->subSeconds(20),
    ]);

    $this->actingAs($user)
        ->get(route('integration-health.index', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.recentFailureCount', 1)
            ->where('items.0.rowsRead', 20)
            ->where('items.0.rowsWritten', 18)
            ->where('items.0.rowsFailed', 2)
            ->where('items.0.lastFailureLabel', 'Invalid Response')
            ->where('recentFailures.0.errorCode', 'invalid_response')
            ->where('recentFailures.0.errorLabel', 'Invalid Response')
            ->missing('invalid JSON payload')
            ->missing('secret-token-123'));
});

test('integration health reports write telemetry and does not fake read operation calls', function () {
    [$user, $team] = integrationHealthContext();
    $source = DataSource::factory()->ready()->create(['team_id' => $team->id, 'type' => 'rest_api']);
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Support Bot']);
    $read = ApiOperation::factory()->create([
        'data_source_id' => $source->id,
        'name' => 'Search catalog',
        'execution_mode' => ApiOperationMode::Read->value,
    ]);
    $write = ApiOperation::factory()->create([
        'data_source_id' => $source->id,
        'name' => 'Create ticket',
        'execution_mode' => ApiOperationMode::Write->value,
    ]);
    BotApiOperation::factory()->create(['bot_id' => $bot->id, 'api_operation_id' => $read->id, 'tool_name' => 'search_catalog']);
    BotApiOperation::factory()->create(['bot_id' => $bot->id, 'api_operation_id' => $write->id, 'tool_name' => 'create_support_ticket']);
    ToolRun::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'api_operation_id' => $write->id,
        'execution_mode' => ApiOperationMode::Write->value,
        'status' => ToolRunStatus::Completed->value,
        'duration_ms' => 180,
        'created_at' => now()->subHour(),
        'completed_at' => now()->subHour(),
    ]);
    ToolRun::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'api_operation_id' => $write->id,
        'execution_mode' => ApiOperationMode::Write->value,
        'status' => ToolRunStatus::Failed->value,
        'error_code' => 'timeout',
        'duration_ms' => 300,
        'created_at' => now()->subMinutes(20),
        'failed_at' => now()->subMinutes(20),
    ]);

    $this->actingAs($user)
        ->get(route('integration-health.index', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('operations.0.name', 'Create ticket')
            ->where('operations.0.calls', 2)
            ->where('operations.0.successes', 1)
            ->where('operations.0.failures', 1)
            ->where('operations.0.failureRate', 50)
            ->where('operations.0.averageDurationMs', 240)
            ->where('operations.1.name', 'Search catalog')
            ->where('operations.1.telemetryAvailable', false)
            ->where('operations.1.calls', null)
            ->where('items.0.bots.0.name', 'Support Bot'));
});

test('integration health filters by source and scopes detail pages to the team', function () {
    [$user, $team] = integrationHealthContext();
    $source = DataSource::factory()->ready()->create(['team_id' => $team->id]);
    $other = DataSource::factory()->ready()->create(['team_id' => $team->id]);
    $foreign = DataSource::factory()->ready()->create(['team_id' => Team::factory()->create()->id]);

    $this->actingAs($user)
        ->get(route('integration-health.index', [
            'current_team' => $team->slug,
            'data_source' => $source->id,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.dataSource', $source->id)
            ->where('summary.integrations', 1)
            ->where('items.0.id', $source->id)
            ->missing($other->name)
            ->missing($foreign->name));

    $this->actingAs($user)
        ->get(route('integration-health.show', ['current_team' => $team->slug, 'dataSource' => $foreign->id]))
        ->assertNotFound();
});
