<?php

use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\BotDomain;
use App\Models\Team;
use App\Models\User;

function botDomainContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->published()->create(['team_id' => $team->id]);

    return [$user, $team, $bot];
}

test('dashboard users can add normalized exact-match domains', function () {
    [$user, $team, $bot] = botDomainContext();

    $this->actingAs($user)
        ->post(route('bots.domains.store', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]), ['domain' => 'https://Example.com/'])
        ->assertRedirect(route('bots.show', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]));

    expect(BotDomain::query()->where('bot_id', $bot->id)->value('domain'))
        ->toBe('example.com');
});

test('domain management rejects paths and disabled localhost domains', function () {
    [$user, $team, $bot] = botDomainContext();

    $this->actingAs($user)
        ->from(route('bots.show', ['current_team' => $team->slug, 'bot' => $bot]))
        ->post(route('bots.domains.store', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]), ['domain' => 'example.com/shop'])
        ->assertSessionHasErrors('domain');

    $this->actingAs($user)
        ->post(route('bots.domains.store', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]), ['domain' => 'http://localhost'])
        ->assertSessionHasErrors('domain');
});

test('domain management accepts public IP addresses', function () {
    [$user, $team, $bot] = botDomainContext();

    $this->actingAs($user)
        ->post(route('bots.domains.store', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]), ['domain' => '158.220.112.169'])
        ->assertRedirect();

    expect(BotDomain::query()->where('bot_id', $bot->id)->value('domain'))
        ->toBe('158.220.112.169');
});

test('domain matching is exact and wildcard suffixes are not implicit', function () {
    config()->set('widget.allow_localhost', true);
    [$user, $team, $bot] = botDomainContext();

    $this->actingAs($user)
        ->post(route('bots.domains.store', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]), ['domain' => 'example.com'])
        ->assertRedirect();

    $this->withHeader('Origin', 'https://shop.example.com')
        ->postJson(route('widget.session', ['botPublicId' => $bot->public_id]), [])
        ->assertForbidden();
});

test('team members cannot manage domains for another teams bot', function () {
    [$user, $team] = botDomainContext();
    $otherTeam = Team::factory()->create();
    $otherBot = Bot::factory()->create(['team_id' => $otherTeam->id]);
    $domain = BotDomain::factory()->create(['bot_id' => $otherBot->id]);

    $this->actingAs($user)
        ->post(route('bots.domains.store', [
            'current_team' => $team->slug,
            'bot' => $otherBot,
        ]), ['domain' => 'example.com'])
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('bots.domains.destroy', [
            'current_team' => $team->slug,
            'bot' => $otherBot,
            'domain' => $domain,
        ]))
        ->assertForbidden();
});
