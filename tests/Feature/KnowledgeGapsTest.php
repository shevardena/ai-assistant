<?php

use App\Enums\KnowledgeGapReason;
use App\Enums\KnowledgeGapStatus;
use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\KnowledgeGap;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiSearchResponse;
use App\Services\KnowledgeGaps\KnowledgeGapService;
use Inertia\Testing\AssertableInertia as Assert;

function knowledgeGapContext(string $teamName = 'Knowledge Team'): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => $teamName]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Knowledge Bot']);

    return [$user, $team, $bot];
}

function knowledgeGapTurn(Bot $bot, string $question): array
{
    $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => $question,
    ]);

    return [$conversation, $message];
}

function knowledgeGapResponse(array $outcomes): AiSearchResponse
{
    return new AiSearchResponse('I could not find a reliable answer.', 1, [], null, [], [], $outcomes);
}

function recordKnowledgeGap(Bot $bot, string $question, array $outcomes): KnowledgeGap
{
    [$conversation, $message] = knowledgeGapTurn($bot, $question);
    $gap = app(KnowledgeGapService::class)->recordFromResponse(
        $bot,
        $conversation,
        $message,
        knowledgeGapResponse($outcomes),
    );

    expect($gap)->toBeInstanceOf(KnowledgeGap::class);

    return $gap;
}

test('normalizes presentation differences and records one gap per user message', function () {
    [$user, $team, $bot] = knowledgeGapContext();
    $service = app(KnowledgeGapService::class);
    [$conversation, $message] = knowledgeGapTurn($bot, '  What   is the student discount?!  ');
    $response = knowledgeGapResponse([
        ['tool' => 'lookup_faq', 'outcome' => 'no_knowledge_match'],
    ]);

    $first = $service->recordFromResponse($bot, $conversation, $message, $response);
    $duplicate = $service->recordFromResponse($bot, $conversation, $message, $response);
    recordKnowledgeGap($bot, 'What is the student discount?', [
        ['tool' => 'lookup_faq', 'outcome' => 'no_knowledge_match'],
    ]);

    expect($first)->toBeInstanceOf(KnowledgeGap::class)
        ->and($duplicate)->toBeNull()
        ->and(KnowledgeGap::query()->where('team_id', $team->id)->count())->toBe(2)
        ->and($first->normalized_question)->toBe('what is the student discount')
        ->and($user->current_team_id)->toBe($team->id);
});

test('records only final conservative knowledge failure outcomes', function () {
    [, $team, $bot] = knowledgeGapContext();

    recordKnowledgeGap($bot, 'Where is the warranty?', [
        ['tool' => 'lookup_faq', 'outcome' => 'no_knowledge_match'],
    ]);
    recordKnowledgeGap($bot, 'Which laptop is cheapest?', [
        ['tool' => 'search_catalog', 'outcome' => 'no_results'],
    ]);
    [$faqConversation, $faqMessage] = knowledgeGapTurn($bot, 'What is your return policy?');
    [$conversation, $message] = knowledgeGapTurn($bot, 'How much is this laptop?');
    $service = app(KnowledgeGapService::class);
    $faqSuccess = $service->recordFromResponse($bot, $faqConversation, $faqMessage, knowledgeGapResponse([
        ['tool' => 'lookup_faq', 'outcome' => 'knowledge_success'],
    ]));
    $success = $service->recordFromResponse($bot, $conversation, $message, knowledgeGapResponse([
        ['tool' => 'search_catalog', 'outcome' => 'no_results'],
        ['tool' => 'search_catalog', 'outcome' => 'catalog_success'],
    ]));
    [$otherConversation, $otherMessage] = knowledgeGapTurn($bot, 'Can you ship this tomorrow?');
    $irrelevantFailure = $service->recordFromResponse(
        $bot,
        $otherConversation,
        $otherMessage,
        knowledgeGapResponse([
            ['tool' => 'rest_api_import', 'outcome' => 'non_knowledge_failure'],
        ]),
    );
    [$writeConversation, $writeMessage] = knowledgeGapTurn($bot, 'Please save my details.');
    $writeFailure = $service->recordFromResponse($bot, $writeConversation, $writeMessage, knowledgeGapResponse([
        ['tool' => 'capture_lead', 'outcome' => 'non_knowledge_failure'],
    ]));

    expect($faqSuccess)->toBeNull()
        ->and($success)->toBeNull()
        ->and($irrelevantFailure)->toBeNull()
        ->and($writeFailure)->toBeNull()
        ->and(KnowledgeGap::query()->where('team_id', $team->id)->count())->toBe(2)
        ->and(KnowledgeGap::query()->where('reason', KnowledgeGapReason::NoResults->value)->count())->toBe(1);
});

test('dashboard preview questions do not create customer knowledge gaps', function () {
    [, $team, $bot] = knowledgeGapContext();
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'metadata' => ['source' => 'dashboard_preview'],
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Which policy is missing?',
    ]);

    $gap = app(KnowledgeGapService::class)->recordFromResponse($bot, $conversation, $message, knowledgeGapResponse([
        ['tool' => 'lookup_faq', 'outcome' => 'no_knowledge_match'],
    ]));

    expect($gap)->toBeNull()
        ->and(KnowledgeGap::query()->where('team_id', $team->id)->count())->toBe(0);
});

test('resolved gaps reopen and ignored groups remain ignored', function () {
    [, $team, $bot] = knowledgeGapContext();
    $service = app(KnowledgeGapService::class);
    $first = recordKnowledgeGap($bot, 'Do you offer student pricing?', [
        ['tool' => 'lookup_faq', 'outcome' => 'no_knowledge_match'],
    ]);
    $user = User::query()->firstOrFail();

    $service->updateStatus($team, $first->group_reference, KnowledgeGapStatus::Resolved, $user);
    $reopened = recordKnowledgeGap($bot, 'Do you offer student pricing?', [
        ['tool' => 'lookup_faq', 'outcome' => 'no_knowledge_match'],
    ]);
    $service->updateStatus($team, $first->group_reference, KnowledgeGapStatus::Ignored, $user);
    $ignored = recordKnowledgeGap($bot, 'Do you offer student pricing?', [
        ['tool' => 'lookup_faq', 'outcome' => 'no_knowledge_match'],
    ]);

    expect($reopened->status)->toBe(KnowledgeGapStatus::Open)
        ->and($ignored->status)->toBe(KnowledgeGapStatus::Ignored)
        ->and(KnowledgeGap::query()->where('team_id', $team->id)->count())->toBe(3);
});

test('knowledge gaps are grouped, paginated, tenant-scoped, and privacy-safe', function () {
    [$user, $team, $bot] = knowledgeGapContext();
    $foreignTeam = Team::factory()->create(['name' => 'Foreign Team']);
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id, 'name' => 'Foreign Bot']);
    recordKnowledgeGap($bot, 'Repeated question', [
        ['tool' => 'search_catalog', 'outcome' => 'no_results'],
    ]);
    recordKnowledgeGap($bot, 'Repeated question!', [
        ['tool' => 'search_catalog', 'outcome' => 'no_results'],
    ]);
    recordKnowledgeGap($foreignBot, 'Foreign private question', [
        ['tool' => 'lookup_faq', 'outcome' => 'no_knowledge_match'],
    ]);

    foreach (range(1, 25) as $index) {
        recordKnowledgeGap($bot, "Unique question {$index}", [
            ['tool' => 'lookup_faq', 'outcome' => 'no_knowledge_match'],
        ]);
    }

    $response = $this->actingAs($user)
        ->get(route('knowledge-gaps.index', [
            'current_team' => $team->slug,
            'status' => 'all',
            'search' => 'repeated',
        ]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-gaps/index')
            ->where('filters.status', 'all')
            ->where('filters.search', 'repeated')
            ->where('gaps.total', 1)
            ->where('gaps.data.0.occurrenceCount', 2)
            ->where('gaps.data.0.question', 'repeated question')
            ->where('summary.repeatedQuestions', 1)
            ->missing($foreignBot->name)
            ->missing('Foreign private question')
            ->missing('toolOutcomes')
            ->missing('safeArguments'));

    $paginated = $this->actingAs($user)
        ->get(route('knowledge-gaps.index', [
            'current_team' => $team->slug,
            'status' => 'all',
        ]));

    $paginated->assertInertia(fn (Assert $page) => $page
        ->where('gaps.total', 26)
        ->where('gaps.per_page', 25)
        ->has('gaps.data', 25)
        ->where('botOptions.0.name', 'Knowledge Bot'));
});

test('knowledge gap status changes are scoped to the current team', function () {
    [$user, $team, $bot] = knowledgeGapContext();
    [, $foreignTeam, $foreignBot] = knowledgeGapContext('Other Team');
    $gap = recordKnowledgeGap($foreignBot, 'Private foreign question', [
        ['tool' => 'lookup_faq', 'outcome' => 'no_knowledge_match'],
    ]);
    $ownGap = recordKnowledgeGap($bot, 'Own question', [
        ['tool' => 'lookup_faq', 'outcome' => 'no_knowledge_match'],
    ]);

    $this->actingAs($user)
        ->patch(route('knowledge-gaps.update', [
            'current_team' => $team->slug,
            'groupReference' => $gap->group_reference,
        ]), ['status' => 'resolved'])
        ->assertNotFound();

    $this->actingAs($user)
        ->patch(route('knowledge-gaps.update', [
            'current_team' => $team->slug,
            'groupReference' => $ownGap->group_reference,
        ]), ['status' => 'resolved'])
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('knowledge-gaps.index', [
            'current_team' => $team->slug,
            'status' => 'resolved',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'resolved')
            ->where('gaps.total', 1));

    expect($gap->fresh()->status)->toBe(KnowledgeGapStatus::Open)
        ->and($ownGap->fresh()->status)->toBe(KnowledgeGapStatus::Resolved)
        ->and($foreignTeam->id)->not->toBe($team->id);
});
