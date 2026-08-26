<?php

use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\BotDataset;
use App\Models\Conversation;
use App\Models\Dataset;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Conversations\ConversationFormService;
use App\Services\Conversations\ConversationService;

function conversationRuntimeContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create([
        'team_id' => $team->id,
        'welcome_message' => 'Welcome to preview.',
    ]);
    $dataset = Dataset::factory()->ready()->create(['team_id' => $team->id]);

    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'is_enabled' => true,
    ]);

    return [$user, $team, $bot];
}

function conversationFakeClient(array $responses): AiClient
{
    return new class($responses) implements AiClient
    {
        public array $payloads = [];

        public function __construct(private array $responses) {}

        public function createResponse(array $payload): array
        {
            $this->payloads[] = $payload;

            return array_shift($this->responses) ?? [
                'output' => [],
                'output_text' => 'Fallback answer.',
                'usage' => null,
            ];
        }
    };
}

test('dashboard preview persists both sides and uses the same conversation on follow-up', function () {
    [$user, $team, $bot] = conversationRuntimeContext();
    $fake = conversationFakeClient([
        ['output' => [], 'output_text' => 'First answer.', 'usage' => null],
        ['output' => [], 'output_text' => 'Follow-up answer.', 'usage' => null],
    ]);
    app()->instance(AiClient::class, $fake);

    $first = $this->actingAs($user)
        ->postJson(route('bots.ai.test', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]), ['message' => 'Hello'])
        ->assertOk()
        ->assertJsonPath('answer', 'First answer.');

    $conversationId = $first->json('conversation_id');

    $this->actingAs($user)
        ->postJson(route('bots.ai.test', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]), [
            'message' => 'What about next?',
            'conversation_id' => $conversationId,
        ])
        ->assertOk()
        ->assertJsonPath('answer', 'Follow-up answer.');

    $conversation = Conversation::query()->where('public_id', $conversationId)->firstOrFail();

    expect($conversation->visitor_id)->toBeNull()
        ->and($conversation->metadata['source'])->toBe('dashboard_preview')
        ->and($conversation->messages()->whereIn('role', ['user', 'assistant'])->count())->toBe(4)
        ->and(collect($fake->payloads[1]['input'])->pluck('content'))->toContain('First answer.');
});

test('preview reset starts a new conversation without deleting the old one', function () {
    [$user, $team, $bot] = conversationRuntimeContext();

    $response = $this->actingAs($user)
        ->postJson(route('bots.ai.reset', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]))
        ->assertOk();

    expect(Conversation::query()->where('public_id', $response->json('conversation_id'))->exists())->toBeTrue();
});

test('dashboard form submission resumes the runtime and persists concise user context', function () {
    [$user, $team, $bot] = conversationRuntimeContext();
    $conversation = app(ConversationService::class)->createPreviewConversation($bot);
    $formResult = app(ConversationFormService::class)->request(
        ToolExecutionContext::forBot($bot, $conversation),
        'capture_lead',
        [
            'title' => 'Contact details',
            'fields' => [[
                'name' => 'email',
                'label' => 'Email',
                'type' => 'email',
                'required' => true,
            ]],
        ],
    );
    $formReference = (string) data_get($formResult->blocks, '0.data.form_reference');
    $formMessage = $conversation->messages()->create([
        'role' => 'assistant',
        'type' => 'text',
        'content' => 'Please provide the requested details.',
        'metadata' => ['blocks' => $formResult->blocks],
    ]);
    app()->instance(AiClient::class, conversationFakeClient([
        ['output' => [], 'output_text' => 'Thanks, we will follow up.', 'usage' => null],
    ]));

    $this->actingAs($user)
        ->postJson(route('bots.ai.forms.submit', [
            'current_team' => $team->slug,
            'bot' => $bot,
            'formReference' => $formReference,
        ]), [
            'conversation_id' => $conversation->public_id,
            'values' => ['email' => 'customer@example.test'],
        ])
        ->assertOk()
        ->assertJsonPath('form_block.data.status', 'submitted')
        ->assertJsonPath('message.content', 'Thanks, we will follow up.');

    $userMessage = $conversation->messages()->where('role', 'user')->firstOrFail();

    expect($userMessage->metadata['form_submission']['form_reference'])->toBe($formReference)
        ->and($userMessage->metadata['form_submission']['values'])->toBe([
            'email' => 'customer@example.test',
        ])
        ->and(app(ConversationService::class)->messageBlocks($formMessage->fresh())[0]['data']['status'])
        ->toBe('submitted');
});

test('preview cannot use a conversation belonging to another bot', function () {
    [$user, $team, $bot] = conversationRuntimeContext();
    $otherBot = Bot::factory()->create(['team_id' => $team->id]);
    $conversation = Conversation::factory()->create([
        'bot_id' => $otherBot->id,
        'visitor_id' => null,
        'metadata' => ['source' => 'dashboard_preview'],
    ]);

    $this->actingAs($user)
        ->postJson(route('bots.ai.test', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]), [
            'message' => 'No access',
            'conversation_id' => $conversation->public_id,
        ])
        ->assertNotFound();

    expect(Message::query()->where('conversation_id', $conversation->id)->count())->toBe(0);
});
