<?php

use App\Enums\DatasetStatus;
use App\Enums\DataSourceStatus;
use App\Enums\KnowledgeGapReason;
use App\Enums\KnowledgeGapStatus;
use App\Enums\TeamRole;
use App\Enums\ToolRunStatus;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\KnowledgeGap;
use App\Models\Message;
use App\Models\SearchRun;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function () {
    Carbon::setTestNow();
});

function improvementCenterContext(string $teamName = 'Improvement Team'): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => $teamName]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create([
        'team_id' => $team->id,
        'name' => 'Store Assistant',
    ]);

    return [$user, $team, $bot];
}

function improvementCenterGap(Bot $bot, string $question, string $groupReference, array $attributes = []): KnowledgeGap
{
    $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => $question,
    ]);

    return KnowledgeGap::factory()->create([
        'team_id' => $bot->team_id,
        'bot_id' => $bot->id,
        'conversation_id' => $conversation->id,
        'message_id' => $message->id,
        'reason' => KnowledgeGapReason::NoResults->value,
        'normalized_question' => strtolower($question),
        'normalized_hash' => hash('sha256', strtolower($question)),
        'group_reference' => $groupReference,
        'status' => KnowledgeGapStatus::Open->value,
        ...$attributes,
    ]);
}

test('shows team-scoped grouped knowledge gaps and deduplicates backed zero results', function () {
    Carbon::setTestNow('2026-08-22 12:00:00');
    [$user, $team, $bot] = improvementCenterContext();
    $foreignTeam = Team::factory()->create(['name' => 'Foreign Team']);
    $foreignBot = Bot::factory()->create([
        'team_id' => $foreignTeam->id,
        'name' => 'Foreign Assistant',
    ]);
    $firstGap = improvementCenterGap($bot, 'What is the return policy?', 'return-policy');
    improvementCenterGap($bot, 'What is the return policy?', 'return-policy');
    improvementCenterGap($bot, 'Resolved private question', 'resolved-question', [
        'status' => KnowledgeGapStatus::Resolved->value,
    ]);
    improvementCenterGap($foreignBot, 'Foreign private question', 'foreign-question');

    SearchRun::factory()->create([
        'bot_id' => $bot->id,
        'message_id' => $firstGap->message_id,
        'result_count' => 0,
        'status' => 'completed',
        'created_at' => now()->subDay(),
    ]);
    SearchRun::factory()->create([
        'bot_id' => $bot->id,
        'message_id' => null,
        'result_count' => 0,
        'status' => 'completed',
        'created_at' => now()->subDay(),
    ]);
    SearchRun::factory()->create([
        'bot_id' => $foreignBot->id,
        'message_id' => null,
        'result_count' => 0,
        'status' => 'completed',
        'created_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)
        ->get(route('improvements.index', ['current_team' => $team->slug]));

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('improvements/index')
            ->where('filters.bot', null)
            ->where('filters.range', '30d')
            ->where('summary.open', 2)
            ->where('summary.customerQuestions', 1)
            ->where('opportunities.0.type', 'knowledge_gap')
            ->where('opportunities.0.evidence.0.value', '2 times')
            ->where('opportunities.0.destination.url', route('knowledge-gaps.index', [
                'current_team' => $team->slug,
                'bot' => $bot->slug,
                'status' => 'open',
                'search' => 'what is the return policy?',
            ]))
            ->where('opportunities.1.type', 'zero_result_search')
            ->missing('Foreign Assistant')
            ->missing('Foreign private question')
            ->missing('Resolved private question')
            ->missing('request_payload'));

    $foreignBotFilter = $this->actingAs($user)
        ->get(route('improvements.index', [
            'current_team' => $team->slug,
            'bot' => $foreignBot->slug,
        ]));

    $foreignBotFilter->assertInertia(fn (Assert $page) => $page
        ->where('filters.bot', null)
        ->where('summary.open', 2)
        ->missing('Foreign Assistant'));
});

test('maps existing data health issues to a safe dataset opportunity', function () {
    [$user, $team] = improvementCenterContext();
    $dataset = Dataset::factory()->create([
        'team_id' => $team->id,
        'name' => 'Products',
        'status' => DatasetStatus::Ready->value,
        'data_source_id' => null,
    ]);
    $foreignDataset = Dataset::factory()->create([
        'name' => 'Private Products',
    ]);

    $response = $this->actingAs($user)
        ->get(route('improvements.index', [
            'current_team' => $team->slug,
            'type' => 'data',
        ]));

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('improvements/index')
            ->where('filters.type', 'data')
            ->where('summary.open', 1)
            ->where('opportunities.0.type', 'dataset_quality')
            ->where('opportunities.0.title', 'Products dataset has no imported records')
            ->where('opportunities.0.destination.url', route('data-health.show', [
                'current_team' => $team->slug,
                'dataset' => $dataset->id,
            ]))
            ->missing('Private Products')
            ->missing('payload'));

    expect($foreignDataset->team_id)->not->toBe($team->id);
});

test('maps integration errors and action failures with root-cause precedence', function () {
    [$user, $team, $bot] = improvementCenterContext();
    $errorSource = DataSource::factory()->create([
        'team_id' => $team->id,
        'name' => 'Shipping API',
        'status' => DataSourceStatus::Error->value,
    ]);
    $healthySource = DataSource::factory()->ready()->create(['team_id' => $team->id]);
    $errorOperation = $errorSource->apiOperations()->create([
        'key' => 'shipping',
        'name' => 'Shipping lookup',
        'type' => 'rest',
        'method' => 'GET',
        'path' => '/shipping',
        'execution_mode' => 'write',
        'request_schema' => [],
        'request_mapping' => [],
        'response_mapping' => [],
        'headers' => [],
        'is_enabled' => true,
    ]);
    $healthyOperation = $healthySource->apiOperations()->create([
        'key' => 'support',
        'name' => 'Support ticket',
        'type' => 'rest',
        'method' => 'POST',
        'path' => '/support',
        'execution_mode' => 'write',
        'request_schema' => [],
        'request_mapping' => [],
        'response_mapping' => [],
        'headers' => [],
        'is_enabled' => true,
    ]);

    ToolRun::factory()->count(2)->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'api_operation_id' => $errorOperation->id,
        'tool_name' => 'get_shipping_info',
        'status' => ToolRunStatus::Failed->value,
        'safe_arguments' => ['secret' => 'do-not-show'],
        'safe_result' => ['raw' => 'do-not-show'],
        'error_code' => 'provider-secret-error',
        'failed_at' => now()->subHour(),
    ]);
    ToolRun::factory()->count(2)->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'api_operation_id' => $healthyOperation->id,
        'tool_name' => 'create_support_ticket',
        'status' => ToolRunStatus::Failed->value,
        'failed_at' => now()->subMinutes(30),
    ]);
    ToolRun::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'api_operation_id' => $healthyOperation->id,
        'tool_name' => 'create_support_ticket',
        'status' => ToolRunStatus::Completed->value,
        'completed_at' => now()->subMinutes(20),
    ]);

    $response = $this->actingAs($user)
        ->get(route('improvements.index', ['current_team' => $team->slug]));

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('improvements/index')
            ->where('summary.dataIntegrationIssues', 1)
            ->where('opportunities.0.type', 'integration_failure')
            ->where('opportunities.0.priority', 'high')
            ->where('opportunities.0.destination.url', route('integration-health.show', [
                'current_team' => $team->slug,
                'dataSource' => $errorSource->id,
            ]))
            ->where('opportunities.1.type', 'action_failure')
            ->where('opportunities.1.evidence.0.value', '2')
            ->where('opportunities.1.evidence.1.value', '3')
            ->where('opportunities.1.evidence.2.value', '66.7%')
            ->missing('get_shipping_info')
            ->missing('provider-secret-error')
            ->missing('do-not-show')
            ->missing('safe_arguments')
            ->missing('safe_result'));
});

test('supports deterministic range and priority filters without unsupported configuration claims', function () {
    Carbon::setTestNow('2026-08-22 12:00:00');
    [$user, $team, $bot] = improvementCenterContext();
    improvementCenterGap($bot, 'What is the warranty?', 'warranty', [
        'created_at' => now()->subDays(10),
    ]);

    $response = $this->actingAs($user)
        ->get(route('improvements.index', [
            'current_team' => $team->slug,
            'range' => '7d',
            'priority' => 'high',
            'type' => 'configuration',
        ]));

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('improvements/index')
            ->where('filters.range', '7d')
            ->where('filters.priority', 'high')
            ->where('filters.type', 'configuration')
            ->where('summary.open', 0)
            ->where('opportunities', []));
});
