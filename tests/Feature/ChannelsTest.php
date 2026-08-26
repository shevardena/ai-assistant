<?php

use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\ChannelConnection;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function channelContext(TeamRole $role = TeamRole::Member): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    return [$user, $team, $bot];
}

test('current team Bot channel page exposes Website and WhatsApp safely', function () {
    [$user, $team, $bot] = channelContext();

    $response = $this->actingAs($user)
        ->get(route('bots.channels.index', [$team->slug, $bot]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bots/channels')
            ->where('bot.id', $bot->id)
            ->has('channels', 7)
            ->where('channels.0.key', 'website')
            ->where('channels.0.implemented', true)
            ->where('channels.0.connection.status', 'draft')
            ->where('channels.0.connection.allowedDomains', 0)
            ->missing('channels.0.connection.configuration')
            ->where('channels.1.key', 'whatsapp')
            ->where('channels.1.implemented', true)
            ->where('channels.1.connection', null)
            ->where('channels.2.key', 'instagram')
            ->where('channels.2.implemented', true)
            ->where('channels.2.connection', null)
            ->where('channels.3.key', 'facebook_messenger')
            ->where('channels.3.implemented', true)
            ->where('channels.3.connection', null)
            ->where('channels.4.key', 'telegram')
            ->where('channels.4.implemented', true)
            ->where('channels.4.connection', null)
            ->where('channels.5.key', 'sms')
            ->where('channels.5.implemented', true)
            ->where('channels.5.connection', null)
            ->where('channels.6.key', 'email')
            ->where('channels.6.implemented', true)
            ->where('channels.6.connection', null));

    expect(ChannelConnection::query()
        ->where('bot_id', $bot->id)
        ->where('channel', 'website')
        ->count())->toBe(1);
});

test('foreign team Bots cannot be used to open the channel page', function () {
    [$user, $team] = channelContext();
    $foreignTeam = Team::factory()->create();
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id]);

    $this->actingAs($user)
        ->get(route('bots.channels.index', [$team->slug, $foreignBot]))
        ->assertNotFound();
});

test('channel configuration access follows the existing team RBAC', function () {
    [$contentManager, $team, $bot] = channelContext(TeamRole::ContentManager);

    $this->actingAs($contentManager)
        ->get(route('bots.channels.index', [$team->slug, $bot]))
        ->assertOk();

    [$supportAgent, $supportTeam, $supportBot] = channelContext(TeamRole::SupportAgent);

    $this->actingAs($supportAgent)
        ->get(route('bots.channels.index', [$supportTeam->slug, $supportBot]))
        ->assertForbidden();
});

test('new normal Bots receive one Website connection without trusting ownership input', function () {
    [$user, $team] = channelContext();
    $foreignTeam = Team::factory()->create();

    $this->actingAs($user)
        ->post(route('bots.store', $team->slug), [
            'name' => 'Channel Bot',
            'slug' => 'channel-bot',
            'default_language' => 'en',
            'team_id' => $foreignTeam->id,
        ])
        ->assertRedirect();

    $bot = Bot::query()->where('slug', 'channel-bot')->firstOrFail();

    expect($bot->team_id)->toBe($team->id)
        ->and($bot->channelConnections()->where('channel', 'website')->count())->toBe(1);
});
