<?php

use App\Enums\TeamRole;
use App\Enums\ToolRunStatus;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SearchRun;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Models\WidgetVisitor;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

function conversationInboxContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Inbox Team']);
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Store Assistant']);

    return [$user, $team, $bot];
}

function customerConversation(Bot $bot, string $content, ?string $lastMessageAt = null): Conversation
{
    $visitor = WidgetVisitor::factory()->create(['bot_id' => $bot->id]);
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $visitor->id,
        'metadata' => ['source' => 'widget'],
        'last_message_at' => $lastMessageAt ?? now(),
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => $content,
    ]);

    return $conversation;
}

test('inbox lists only current team customer conversations with safe summaries', function () {
    [$user, $team, $bot] = conversationInboxContext();
    $conversation = customerConversation($bot, 'Do you have this laptop in stock?');
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Yes, it is available.',
    ]);
    $foreignBot = Bot::factory()->create(['name' => 'Foreign Bot']);
    $foreignConversation = customerConversation($foreignBot, 'Should not appear');
    $preview = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'metadata' => ['source' => 'dashboard_preview'],
    ]);

    $response = $this->actingAs($user)
        ->get(route('conversations.index', ['current_team' => $team->slug]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('conversations/index')
            ->where('filters.source', 'customer')
            ->where('conversations.total', 1)
            ->where('conversations.data.0.reference', $conversation->public_id)
            ->where('conversations.data.0.channel', 'website')
            ->where('conversations.data.0.bot.name', $bot->name)
            ->where('conversations.data.0.messageCount', 2)
            ->where('conversations.data.0.preview', 'Do you have this laptop in stock?')
            ->missing($foreignConversation->public_id)
            ->missing($preview->public_id)
            ->missing($conversation->id));
});

test('inbox supports Bot, source, date, search, pagination, and recent activity ordering', function () {
    Carbon::setTestNow('2026-08-22 12:00:00');
    [$user, $team, $bot] = conversationInboxContext();
    $secondBot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Support Bot']);
    $recent = customerConversation($bot, 'Recent stock question', '2026-08-22 11:00:00');
    customerConversation($bot, 'Older customer question', '2026-08-21 11:00:00');
    customerConversation($secondBot, 'Shipping question', '2026-08-22 10:00:00');
    Conversation::factory()->create([
        'bot_id' => $bot->id,
        'metadata' => ['source' => 'dashboard_preview'],
        'created_at' => '2026-08-22 09:00:00',
        'last_message_at' => '2026-08-22 09:00:00',
    ]);

    foreach (range(1, 24) as $index) {
        customerConversation($bot, "Paged question {$index}", '2026-08-20 10:00:00');
    }

    $response = $this->actingAs($user)
        ->get(route('conversations.index', [
            'current_team' => $team->slug,
            'bot' => $bot->slug,
            'range' => 'today',
            'search' => 'stock',
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('filters.bot', $bot->slug)
        ->where('filters.range', 'today')
        ->where('filters.search', 'stock')
        ->where('conversations.total', 1)
        ->where('conversations.data.0.reference', $recent->public_id));

    $all = $this->actingAs($user)
        ->get(route('conversations.index', [
            'current_team' => $team->slug,
            'source' => 'all',
        ]));

    $all->assertInertia(fn (Assert $page) => $page
        ->where('conversations.total', 28)
        ->where('conversations.per_page', 25)
        ->where('conversations.data.0.reference', $recent->public_id)
        ->where('conversations.data.24.reference', fn (string $reference): bool => $reference !== ''));

    $website = $this->actingAs($user)
        ->get(route('conversations.index', [
            'current_team' => $team->slug,
            'channel' => 'website',
        ]));

    $website->assertInertia(fn (Assert $page) => $page
        ->where('filters.channel', 'website')
        ->where('conversations.data.0.channel', 'website'));
});

test('inbox cannot open a conversation owned by another team', function () {
    [$user, $team] = conversationInboxContext();
    $foreignBot = Bot::factory()->create(['name' => 'Other Team Bot']);
    $foreignConversation = customerConversation($foreignBot, 'Private conversation');

    $this->actingAs($user)
        ->get(route('conversations.show', [
            'current_team' => $team->slug,
            'conversation' => $foreignConversation->public_id,
        ]))
        ->assertNotFound();
});

test('inbox detail is chronological, read-only, and privacy safe', function () {
    [$user, $team, $bot] = conversationInboxContext();
    $visitor = WidgetVisitor::factory()->create([
        'bot_id' => $bot->id,
        'external_customer_id' => 'private-customer-42',
    ]);
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $visitor->id,
        'metadata' => ['source' => 'widget'],
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Show me laptops.',
        'created_at' => now(),
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Here are the products.',
        'created_at' => now()->addMinute(),
        'metadata' => [
            'blocks' => [[
                'type' => 'product_cards',
                'data' => ['cards' => [['id' => 'sku-1', 'title' => 'Laptop']]],
            ]],
        ],
    ]);
    SearchRun::factory()->create([
        'bot_id' => $bot->id,
        'conversation_id' => $conversation->id,
    ]);
    ToolRun::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'conversation_id' => $conversation->id,
        'api_operation_id' => null,
        'tool_name' => 'add_to_cart',
        'status' => ToolRunStatus::Completed->value,
        'safe_arguments' => ['secret' => 'do not expose'],
        'safe_result' => ['internal_id' => 'do not expose'],
    ]);

    $response = $this->actingAs($user)
        ->get(route('conversations.show', [
            'current_team' => $team->slug,
            'conversation' => $conversation->public_id,
        ]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('conversations/show')
            ->where('conversation.reference', $conversation->public_id)
            ->where('conversation.channel', 'website')
            ->where('conversation.channelName', 'Website')
            ->where('conversation.visitor.label', 'Known customer')
            ->where('conversation.visitor.conversationCount', 1)
            ->where('conversation.searchesCount', 1)
            ->where('conversation.actions.0.name', 'Add to cart')
            ->where('conversation.actions.0.status', 'Completed')
            ->where('conversation.messages.0.role', 'user')
            ->where('conversation.messages.1.role', 'assistant')
            ->where('conversation.messages.1.blocks.0.type', 'product_cards')
            ->missing('conversation.visitor.externalCustomerId')
            ->missing('conversation.visitor.publicId')
            ->missing('conversation.actions.0.arguments')
            ->missing('conversation.actions.0.safeResult')
            ->missing('secret')
            ->missing('internal_id'));
});

test('preview and all source filters use explicit persisted source semantics', function () {
    [$user, $team, $bot] = conversationInboxContext();
    $customer = customerConversation($bot, 'Customer message');
    $preview = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'metadata' => ['source' => 'dashboard_preview'],
    ]);

    $previewResponse = $this->actingAs($user)
        ->get(route('conversations.index', [
            'current_team' => $team->slug,
            'source' => 'preview',
        ]));

    $previewResponse->assertInertia(fn (Assert $page) => $page
        ->where('conversations.total', 1)
        ->where('conversations.data.0.reference', $preview->public_id)
        ->where('conversations.data.0.source', 'preview'));

    $allResponse = $this->actingAs($user)
        ->get(route('conversations.index', [
            'current_team' => $team->slug,
            'source' => 'all',
        ]));

    $allResponse->assertInertia(fn (Assert $page) => $page
        ->where('conversations.total', 2)
        ->missing($customer->id));
});
test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
