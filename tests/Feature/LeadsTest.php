<?php

use App\Enums\LeadStatus;
use App\Enums\TeamRole;
use App\Enums\ToolRunStatus;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DataSource;
use App\Models\Lead;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Leads\LeadService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function (): void {
    Carbon::setTestNow();
});

function leadDashboardContext(string $teamName = 'Leads Team'): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => $teamName]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Sales Assistant']);
    $dataSource = DataSource::factory()->create(['team_id' => $team->id]);
    $operation = ApiOperation::factory()->create(['data_source_id' => $dataSource->id]);

    BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'capture_lead',
        'settings' => [
            'input_mapping' => [
                'name' => ['source' => 'model_input', 'model_input' => 'name', 'operation_argument' => 'customer_name'],
                'email' => ['source' => 'model_input', 'model_input' => 'email', 'operation_argument' => 'customer_email'],
                'phone' => ['source' => 'model_input', 'model_input' => 'phone', 'operation_argument' => 'customer_phone'],
                'message' => ['source' => 'model_input', 'model_input' => 'message', 'operation_argument' => 'customer_message'],
                'product_reference' => ['source' => 'dataset_field', 'dataset_field' => 'sku', 'operation_argument' => 'product_reference'],
            ],
        ],
    ]);

    return [$user, $team, $bot, $operation];
}

function leadDashboardRun(Bot $bot, ApiOperation $operation, array $overrides = []): ToolRun
{
    return ToolRun::factory()->create([
        'team_id' => $bot->team_id,
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'capture_lead',
        'status' => ToolRunStatus::Completed->value,
        'safe_arguments' => [
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '+995 555 123 456',
            'customer_message' => 'Interested in a product demo.',
            'product_reference' => 'SKU-42',
        ],
        'safe_result' => ['lead_reference' => 'LEAD-42', 'status' => 'created'],
        'completed_at' => now(),
        ...$overrides,
    ]);
}

function leadDashboardStoredLead(Team $team, Bot $bot, array $overrides = []): Lead
{
    $run = ToolRun::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'api_operation_id' => null,
        'tool_name' => 'capture_lead',
        'status' => ToolRunStatus::Completed->value,
        'safe_result' => ['lead_reference' => 'LEAD-STORED'],
    ]);

    return Lead::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'tool_run_id' => $run->id,
        ...$overrides,
    ]);
}

test('completed capture actions create one privacy-safe lead and repeat completion is idempotent', function (): void {
    [, $team, $bot, $operation] = leadDashboardContext();
    $run = leadDashboardRun($bot, $operation);

    $leadService = app(LeadService::class);
    $lead = $leadService->createFromCompletedRun($run);
    $repeat = $leadService->createFromCompletedRun($run->fresh());

    expect($lead)->toBeInstanceOf(Lead::class)
        ->and($repeat?->id)->toBe($lead?->id)
        ->and(Lead::query()->where('team_id', $team->id)->count())->toBe(1)
        ->and($lead?->name)->toBe('Jane Doe')
        ->and($lead?->email)->toBe('jane@example.com')
        ->and($lead?->phone)->toBe('+995 555 123 456')
        ->and($lead?->interest_summary)->toContain('Interested in a product demo.')
        ->and($lead?->interest_summary)->toContain('SKU-42')
        ->and($lead?->provider_reference)->toBe('LEAD-42')
        ->and($lead?->customer_id)->not->toBeNull()
        ->and(Customer::query()->where('team_id', $team->id)->count())->toBe(1)
        ->and($lead?->status)->toBe(LeadStatus::New);
});

test('pending, failed, cancelled, and dashboard preview captures do not create leads', function (): void {
    [, , $bot, $operation] = leadDashboardContext();
    $leadService = app(LeadService::class);

    foreach ([ToolRunStatus::PendingConfirmation, ToolRunStatus::Failed, ToolRunStatus::Cancelled] as $status) {
        $run = leadDashboardRun($bot, $operation, ['status' => $status->value]);
        expect($leadService->createFromCompletedRun($run))->toBeNull();
    }

    $preview = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'metadata' => ['source' => 'dashboard_preview'],
    ]);
    $run = leadDashboardRun($bot, $operation, ['conversation_id' => $preview->id]);

    expect($leadService->createFromCompletedRun($run))->toBeNull()
        ->and(Lead::query()->count())->toBe(0);
});

test('leads index is current-team scoped, supports filters, and paginates newest first', function (): void {
    Carbon::setTestNow('2026-08-22 12:00:00');
    [$user, $team, $bot] = leadDashboardContext();
    $otherTeam = Team::factory()->create();
    $otherBot = Bot::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Other Assistant']);

    leadDashboardStoredLead($team, $bot, ['name' => 'Newest Jane', 'email' => 'jane@example.com', 'status' => LeadStatus::Qualified->value, 'created_at' => now()]);
    leadDashboardStoredLead($team, $bot, ['name' => 'Older John', 'status' => LeadStatus::New->value, 'created_at' => now()->subDays(31)]);
    leadDashboardStoredLead($otherTeam, $otherBot, ['name' => 'Foreign Lead']);

    for ($index = 0; $index < 25; $index++) {
        leadDashboardStoredLead($team, $bot, ['name' => 'Pagination Lead '.$index, 'created_at' => now()->subHours($index + 1)]);
    }

    $response = $this->actingAs($user)->get(route('leads.index', ['current_team' => $team->slug, 'range' => '30d']));

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('leads/index')
            ->where('leads.total', 26)
            ->where('leads.per_page', 25)
            ->where('leads.data.0.name', 'Newest Jane')
            ->where('summary.total', 26)
            ->where('summary.qualified', 1)
            ->missing('Foreign Lead'));

    $filtered = $this->actingAs($user)->get(route('leads.index', [
        'current_team' => $team->slug,
        'range' => 'all',
        'status' => 'qualified',
        'search' => 'jane@example.com',
    ]));

    $filtered->assertInertia(fn (Assert $page) => $page
        ->where('leads.total', 1)
        ->where('leads.data.0.email', 'jane@example.com'));

    $foreignBotFilter = $this->actingAs($user)->get(route('leads.index', ['current_team' => $team->slug, 'bot' => $otherBot->slug]));

    $foreignBotFilter->assertInertia(fn (Assert $page) => $page
        ->where('leads.total', 0)
        ->where('summary.total', 0));
});

test('lead detail and status updates remain team scoped and omit raw action payloads', function (): void {
    [$user, $team, $bot] = leadDashboardContext();
    $lead = leadDashboardStoredLead($team, $bot, ['name' => 'Private Jane', 'interest_summary' => 'Needs a quote.', 'provider_reference' => 'PROVIDER-1']);
    $foreignTeam = Team::factory()->create();
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id]);
    $foreignLead = leadDashboardStoredLead($foreignTeam, $foreignBot);

    $detail = $this->actingAs($user)->get(route('leads.show', ['current_team' => $team->slug, 'lead' => $lead]));

    $detail->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('leads/show')
            ->where('lead.name', 'Private Jane')
            ->where('lead.interestSummary', 'Needs a quote.')
            ->where('lead.providerReference', 'PROVIDER-1')
            ->missing('safe_arguments')
            ->missing('safe_result')
            ->missing('idempotency_key'));

    $this->actingAs($user)
        ->patch(route('leads.update', ['current_team' => $team->slug, 'lead' => $lead]), ['status' => LeadStatus::Contacted->value])
        ->assertRedirect();

    expect($lead->fresh()?->status)->toBe(LeadStatus::Contacted);

    $this->actingAs($user)
        ->get(route('leads.show', ['current_team' => $team->slug, 'lead' => $foreignLead]))
        ->assertNotFound();

    $this->actingAs($user)
        ->patch(route('leads.update', ['current_team' => $team->slug, 'lead' => $foreignLead]), ['status' => LeadStatus::Won->value])
        ->assertNotFound();

    expect($foreignLead->fresh()?->status)->toBe(LeadStatus::New);
});
