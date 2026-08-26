<?php

use App\Enums\ConversationHandoffStatus;
use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\User;
use App\Models\WidgetVisitor;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Conversations\ConversationHandoffService;
use App\Services\Conversations\ConversationService;
use Inertia\Testing\AssertableInertia as Assert;

function humanHandoffContext(string $teamName = 'Handoff Team'): array
{
    $user = User::factory()->create(['name' => 'Handoff Agent']);
    $team = Team::factory()->create(['name' => $teamName]);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->published()->create([
        'team_id' => $team->id,
        'name' => 'Support Assistant',
    ]);
    $bot->domains()->create(['domain' => 'example.com']);
    $visitor = WidgetVisitor::factory()->create(['bot_id' => $bot->id]);
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $visitor->id,
        'metadata' => ['source' => 'widget'],
    ]);

    return [$user, $team, $bot, $visitor, $conversation];
}

function humanHandoffAiClient(array $responses): AiClient
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
                'output_text' => 'AI response.',
                'usage' => null,
            ];
        }
    };
}

function humanHandoffToolCall(): array
{
    return [
        'output' => [[
            'type' => 'function_call',
            'name' => 'request_human_handoff',
            'call_id' => 'handoff-call-1',
            'arguments' => json_encode(['reason' => 'customer_requested']),
        ]],
        'output_text' => null,
        'usage' => null,
    ];
}

test('customer-requested handoff persists a trusted event and bypasses the final AI answer', function () {
    [, , $bot, $visitor, $conversation] = humanHandoffContext();
    $fake = humanHandoffAiClient([humanHandoffToolCall()]);
    app()->instance(AiClient::class, $fake);

    $response = $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.messages', ['botPublicId' => $bot->public_id]), [
            'visitor_id' => $visitor->public_id,
            'conversation_id' => $conversation->public_id,
            'message' => 'I want to speak to a person.',
        ]);

    $response->assertOk()
        ->assertJsonPath('handoff_status', 'requested')
        ->assertJsonPath('message.role', 'system')
        ->assertJsonPath('message.content', ConversationHandoffService::REQUESTED_MESSAGE);

    expect($fake->payloads)->toHaveCount(1)
        ->and($conversation->fresh()->handoff_status)->toBe(ConversationHandoffStatus::Requested)
        ->and($conversation->messages()->where('role', 'system')->whereJsonContains('metadata->source', 'handoff')->count())->toBe(1);
});

test('customer messages during requested handoff persist without invoking AI', function () {
    [, , $bot, $visitor, $conversation] = humanHandoffContext();
    app(ConversationHandoffService::class)->request(
        $bot->team,
        $conversation,
        'customer_requested',
    );
    $fake = humanHandoffAiClient([]);
    app()->instance(AiClient::class, $fake);

    $response = $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.messages', ['botPublicId' => $bot->public_id]), [
            'visitor_id' => $visitor->public_id,
            'conversation_id' => $conversation->public_id,
            'message' => 'I have one more detail to add.',
        ]);

    $response->assertOk()
        ->assertJsonPath('handoff_status', 'requested')
        ->assertJsonPath('message.content', ConversationHandoffService::WAITING_MESSAGE);

    expect($fake->payloads)->toBeEmpty()
        ->and($conversation->messages()->where('role', 'user')->count())->toBe(1);
});

test('team members can take over, reply, poll the reply, and return control to AI', function () {
    [$user, $team, $bot, $visitor, $conversation] = humanHandoffContext();
    app(ConversationHandoffService::class)->request($team, $conversation, 'customer_requested');

    $this->actingAs($user)
        ->post(route('conversations.handoff.take-over', [
            'current_team' => $team->slug,
            'conversation' => $conversation->public_id,
        ]))
        ->assertRedirect();

    expect($conversation->fresh()->handoff_status)->toBe(ConversationHandoffStatus::Human);

    $this->actingAs($user)
        ->post(route('conversations.reply', [
            'current_team' => $team->slug,
            'conversation' => $conversation->public_id,
        ]), ['message' => 'Hi, I can help with that.'])
        ->assertRedirect();

    $reply = $conversation->messages()->where('metadata->source', 'human_agent')->firstOrFail();
    expect($reply->role)->toBe('assistant')
        ->and($reply->metadata['source'])->toBe('human_agent');

    $fake = humanHandoffAiClient([
        [
            'output' => [],
            'output_text' => 'The AI assistant is back to help.',
            'usage' => null,
        ],
    ]);
    app()->instance(AiClient::class, $fake);

    $customerDuringHuman = $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.messages', ['botPublicId' => $bot->public_id]), [
            'visitor_id' => $visitor->public_id,
            'conversation_id' => $conversation->public_id,
            'message' => 'I have another question for the team.',
        ]);

    $customerDuringHuman->assertOk()
        ->assertJsonPath('handoff_status', 'human')
        ->assertJsonPath('message.role', 'system')
        ->assertJsonPath('message.content', ConversationHandoffService::HUMAN_REPLY_ACKNOWLEDGEMENT);

    expect($fake->payloads)->toBeEmpty();

    $poll = $this->withHeader('Origin', 'https://example.com')
        ->getJson(route('widget.messages.poll', [
            'botPublicId' => $bot->public_id,
            'visitor_id' => $visitor->public_id,
            'conversation_id' => $conversation->public_id,
            'after_message_id' => 0,
        ]));

    $poll->assertOk()
        ->assertJsonPath('handoff_status', 'human')
        ->assertJsonPath('messages.2.source', 'human')
        ->assertJsonPath('messages.2.sender', 'Support Team')
        ->assertJsonPath('messages.2.content', 'Hi, I can help with that.');

    $this->actingAs($user)
        ->post(route('conversations.handoff.return-to-ai', [
            'current_team' => $team->slug,
            'conversation' => $conversation->public_id,
        ]))
        ->assertRedirect();

    $resumed = $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.messages', ['botPublicId' => $bot->public_id]), [
            'visitor_id' => $visitor->public_id,
            'conversation_id' => $conversation->public_id,
            'message' => 'Can the AI assistant help me now?',
        ]);

    $resumed->assertOk()
        ->assertJsonPath('handoff_status', 'ai')
        ->assertJsonPath('message.role', 'assistant')
        ->assertJsonPath('message.content', 'The AI assistant is back to help.');

    expect($conversation->fresh()->handoff_status)->toBe(ConversationHandoffStatus::Ai)
        ->and($fake->payloads)->toHaveCount(1);
});

test('foreign team members cannot take over or reply to another team conversation', function () {
    [$user, $team] = humanHandoffContext('Owner Team');
    $foreignTeam = Team::factory()->create(['name' => 'Other Team']);
    $foreignBot = Bot::factory()->published()->create([
        'team_id' => $foreignTeam->id,
    ]);
    $visitor = WidgetVisitor::factory()->create(['bot_id' => $foreignBot->id]);
    $conversation = Conversation::factory()->create([
        'bot_id' => $foreignBot->id,
        'visitor_id' => $visitor->id,
        'handoff_status' => ConversationHandoffStatus::Requested->value,
    ]);

    $this->actingAs($user)
        ->post(route('conversations.handoff.take-over', [
            'current_team' => $team->slug,
            'conversation' => $conversation->public_id,
        ]))
        ->assertNotFound();

    expect($conversation->fresh()->handoff_status)->toBe(ConversationHandoffStatus::Requested);
});

test('polling is isolated by bot, origin, visitor, and conversation', function () {
    [, , $bot, $visitor, $conversation] = humanHandoffContext();
    $otherVisitor = WidgetVisitor::factory()->create(['bot_id' => $bot->id]);
    $otherConversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $otherVisitor->id,
    ]);

    $this->withHeader('Origin', 'https://example.com')
        ->getJson(route('widget.messages.poll', [
            'botPublicId' => $bot->public_id,
            'visitor_id' => $otherVisitor->public_id,
            'conversation_id' => $conversation->public_id,
        ]))
        ->assertNotFound();

    $this->withHeader('Origin', 'https://evil.example')
        ->getJson(route('widget.messages.poll', [
            'botPublicId' => $bot->public_id,
            'visitor_id' => $visitor->public_id,
            'conversation_id' => $conversation->public_id,
        ]))
        ->assertForbidden();

    expect($otherConversation->bot_id)->toBe($bot->id);
});

test('preview conversations cannot create operational handoff queue items', function () {
    [$user, $team, $bot] = humanHandoffContext();
    $preview = app(ConversationService::class)->createPreviewConversation($bot);

    expect(app(ConversationHandoffService::class)->request($team, $preview, 'manual'))->toBeFalse()
        ->and($preview->fresh()->handoff_status)->toBe(ConversationHandoffStatus::Ai);

    $response = $this->actingAs($user)
        ->get(route('conversations.index', [
            'current_team' => $team->slug,
            'source' => 'preview',
            'handoff' => 'needs_attention',
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('conversations.total', 0)
        ->where('handoffSummary.needsAttention', 0));
});

test('inbox separates needs attention and human active conversations', function () {
    [$user, $team, $bot] = humanHandoffContext();
    $requested = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => WidgetVisitor::factory()->create(['bot_id' => $bot->id])->id,
        'handoff_status' => ConversationHandoffStatus::Requested->value,
    ]);
    $human = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => WidgetVisitor::factory()->create(['bot_id' => $bot->id])->id,
        'handoff_status' => ConversationHandoffStatus::Human->value,
    ]);

    $needsAttention = $this->actingAs($user)
        ->get(route('conversations.index', [
            'current_team' => $team->slug,
            'handoff' => 'needs_attention',
        ]));
    $needsAttention->assertInertia(fn (Assert $page) => $page
        ->where('conversations.total', 1)
        ->where('conversations.data.0.reference', $requested->public_id)
        ->where('conversations.data.0.handoffLabel', 'Needs human'));

    $humanActive = $this->actingAs($user)
        ->get(route('conversations.index', [
            'current_team' => $team->slug,
            'handoff' => 'human',
        ]));
    $humanActive->assertInertia(fn (Assert $page) => $page
        ->where('conversations.total', 1)
        ->where('conversations.data.0.reference', $human->public_id)
        ->where('conversations.data.0.handoffLabel', 'Human active'));
});
