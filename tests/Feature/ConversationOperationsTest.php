<?php

use App\Enums\ConversationHandoffStatus;
use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\ConversationTag;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function conversationOperationsContext(TeamRole $role = TeamRole::Member): array
{
    $user = User::factory()->create(['name' => 'Operations Agent']);
    $team = Team::factory()->create(['name' => 'Operations Team']);
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Operations Bot']);
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'metadata' => ['source' => 'widget'],
        'handoff_status' => ConversationHandoffStatus::Ai->value,
    ]);

    return [$user, $team, $bot, $conversation];
}

test('conversation operations use a separate status and allow reversible updates', function () {
    [$user, $team, , $conversation] = conversationOperationsContext();

    expect($conversation->conversation_status->value)->toBe('open');

    $this->actingAs($user)
        ->patch(route('conversations.status.update', [$team->slug, $conversation->public_id]), ['status' => 'resolved'])
        ->assertRedirect();

    expect($conversation->fresh()->conversation_status->value)->toBe('resolved');

    $this->actingAs($user)
        ->patch(route('conversations.status.update', [$team->slug, $conversation->public_id]), ['status' => 'open'])
        ->assertRedirect();

    expect($conversation->fresh()->conversation_status->value)->toBe('open');
});

test('conversation assignment is team scoped and not available to analysts', function () {
    [$user, $team, , $conversation] = conversationOperationsContext();
    $assignee = User::factory()->create(['name' => 'Support Agent']);
    $team->members()->attach($assignee, ['role' => TeamRole::SupportAgent->value]);

    $this->actingAs($user)
        ->patch(route('conversations.assignment.update', [$team->slug, $conversation->public_id]), [
            'assigned_to_user_id' => $assignee->id,
        ])
        ->assertRedirect();

    expect($conversation->fresh()->assigned_to_user_id)->toBe($assignee->id)
        ->and($assignee->notifications()->where('type', 'App\\Notifications\\TeamEventNotification')->count())->toBe(1);

    $this->actingAs($user)
        ->patch(route('conversations.assignment.update', [$team->slug, $conversation->public_id]), [
            'assigned_to_user_id' => $assignee->id,
        ])
        ->assertRedirect();

    expect($assignee->notifications()->where('type', 'App\\Notifications\\TeamEventNotification')->count())->toBe(1);

    $analyst = User::factory()->create();
    $team->members()->attach($analyst, ['role' => TeamRole::Analyst->value]);
    $analyst->switchTeam($team);

    $this->actingAs($analyst)
        ->patch(route('conversations.assignment.update', [$team->slug, $conversation->public_id]), [
            'assigned_to_user_id' => $assignee->id,
        ])
        ->assertForbidden();
});

test('notes are internal records and tags are normalized and team scoped', function () {
    [$user, $team, , $conversation] = conversationOperationsContext();

    $this->actingAs($user)
        ->post(route('conversations.notes.store', [$team->slug, $conversation->public_id]), [
            'body' => '  Follow   up with the customer.  ',
        ])
        ->assertRedirect();

    $note = ConversationNote::query()->firstOrFail();
    expect($note->body)->toBe('Follow   up with the customer.')
        ->and(Message::query()->count())->toBe(0);

    $this->actingAs($user)
        ->post(route('conversation-tags.store', [$team->slug]), ['name' => '  VIP   Customer '])
        ->assertRedirect();

    $tag = ConversationTag::query()->where('team_id', $team->id)->firstOrFail();
    expect($tag->name)->toBe('VIP Customer')
        ->and($tag->slug)->toBe('vip-customer');

    $this->actingAs($user)
        ->post(route('conversations.tags.attach', [$team->slug, $conversation->public_id, $tag->public_id]))
        ->assertRedirect();

    expect($conversation->fresh()->tags()->whereKey($tag->id)->exists())->toBeTrue();

    $foreignTeam = Team::factory()->create();
    $foreignTag = ConversationTag::factory()->create(['team_id' => $foreignTeam->id]);

    $this->actingAs($user)
        ->post(route('conversations.tags.attach', [$team->slug, $conversation->public_id, $foreignTag->public_id]))
        ->assertNotFound();
});

test('inbox filters status and assignee without leaving the current team', function () {
    [$user, $team, , $conversation] = conversationOperationsContext();
    $assignee = User::factory()->create();
    $team->members()->attach($assignee, ['role' => TeamRole::SupportAgent->value]);
    $conversation->update([
        'conversation_status' => 'pending',
        'assigned_to_user_id' => $assignee->id,
        'metadata' => ['source' => 'customer'],
    ]);
    Conversation::factory()->create([
        'bot_id' => $conversation->bot_id,
        'conversation_status' => 'closed',
        'metadata' => ['source' => 'widget'],
    ]);

    $this->actingAs($user)
        ->get(route('conversations.index', [
            'current_team' => $team->slug,
            'status' => 'pending',
            'assignee' => (string) $assignee->id,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'pending')
            ->where('filters.assignee', (string) $assignee->id)
            ->where('conversations.total', 1)
            ->where('conversations.data.0.reference', $conversation->public_id)
            ->where('conversations.data.0.assignee.name', $assignee->name));
});

test('foreign conversations cannot be modified through operations routes', function () {
    [$user, $team] = conversationOperationsContext();
    $foreignTeam = Team::factory()->create(['name' => 'Foreign Operations Team']);
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id]);
    $foreignConversation = Conversation::factory()->create(['bot_id' => $foreignBot->id]);

    $this->actingAs($user)
        ->patch(route('conversations.status.update', [$team->slug, $foreignConversation->public_id]), ['status' => 'closed'])
        ->assertNotFound();

    expect($foreignConversation->fresh()->conversation_status->value)->toBe('open');
});
