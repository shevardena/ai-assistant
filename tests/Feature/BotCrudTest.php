<?php

use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access the bot index', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->get(route('bots.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});

test('authenticated users can access the bot index for their current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    $currentBot = Bot::factory()->create(['team_id' => $team->id]);
    $otherBot = Bot::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this
        ->actingAs($user)
        ->get(route('bots.index', ['current_team' => $team->slug]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bots/index')
            ->has('bots.data', 1)
            ->where('bots.data.0.id', $currentBot->id)
            ->where('bots.data', fn (Collection $bots): bool => ! $bots->pluck('id')->contains($otherBot->id)),
        );
});

test('bot index follows the selected current team', function () {
    $user = User::factory()->create();
    $firstTeam = Team::factory()->create();
    $secondTeam = Team::factory()->create();

    $firstTeam->members()->attach($user, ['role' => TeamRole::Member->value]);
    $secondTeam->members()->attach($user, ['role' => TeamRole::Member->value]);

    $firstBot = Bot::factory()->create(['team_id' => $firstTeam->id]);
    $secondBot = Bot::factory()->create(['team_id' => $secondTeam->id]);

    $user->switchTeam($firstTeam);

    $this->actingAs($user)
        ->get(route('bots.index', ['current_team' => $firstTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('bots.data', 1)
            ->where('bots.data.0.id', $firstBot->id),
        );

    $this->actingAs($user)
        ->get(route('bots.index', ['current_team' => $secondTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('bots.data', 1)
            ->where('bots.data.0.id', $secondBot->id),
        );
});

test('authenticated users can access the bot create page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->get(route('bots.create', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('bots/create'));
});

test('a bot is created for the current team regardless of submitted ownership fields', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    $response = $this
        ->actingAs($user)
        ->post(route('bots.store', ['current_team' => $team->slug]), [
            'name' => 'Support Bot',
            'slug' => 'support-bot',
            'default_language' => 'en',
            'instructions' => 'Be helpful.',
            'team_id' => $otherTeam->id,
            'status' => 'published',
        ]);

    $bot = Bot::query()->where('slug', 'support-bot')->firstOrFail();

    $response->assertRedirect(route('bots.show', [
        'current_team' => $team->slug,
        'bot' => $bot,
    ]));

    expect($bot->team_id)->toBe($team->id)
        ->and($bot->status)->toBe('draft');
});

test('bot slugs are unique within a team but may repeat across teams', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    Bot::factory()->create(['team_id' => $team->id, 'slug' => 'shared-slug']);

    $this->actingAs($user)
        ->from(route('bots.create', ['current_team' => $team->slug]))
        ->post(route('bots.store', ['current_team' => $team->slug]), [
            'name' => 'Duplicate Bot',
            'slug' => 'shared-slug',
            'default_language' => 'en',
        ])
        ->assertSessionHasErrors('slug');

    $otherTeam->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($otherTeam);

    $this->actingAs($user)
        ->post(route('bots.store', ['current_team' => $otherTeam->slug]), [
            'name' => 'Other Bot',
            'slug' => 'shared-slug',
            'default_language' => 'en',
        ])
        ->assertRedirect();

    expect(Bot::query()->where('team_id', $otherTeam->id)->where('slug', 'shared-slug')->exists())->toBeTrue();
});

test('a soft-deleted bot slug can be reused within the same team', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $deletedBot = Bot::factory()->create([
        'team_id' => $team->id,
        'slug' => 'store-assistant',
    ]);

    $deletedBot->delete();

    $this->actingAs($user)
        ->post(route('bots.store', ['current_team' => $team->slug]), [
            'name' => 'Store Assistant',
            'slug' => 'store-assistant',
            'default_language' => 'en',
        ])
        ->assertRedirect();

    expect(Bot::query()
        ->where('team_id', $team->id)
        ->where('slug', 'store-assistant')
        ->exists())->toBeTrue();
});

test('users can view and edit a bot from their current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->get(route('bots.show', ['current_team' => $team->slug, 'bot' => $bot]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bots/show')
            ->where('bot.id', $bot->id),
        );

    $this->actingAs($user)
        ->get(route('bots.edit', ['current_team' => $team->slug, 'bot' => $bot]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bots/edit')
            ->where('bot.id', $bot->id),
        );
});

test('users cannot view or edit a bot from another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $otherTeam->id]);

    $this->actingAs($user)
        ->get(route('bots.show', ['current_team' => $team->slug, 'bot' => $bot]))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('bots.edit', ['current_team' => $team->slug, 'bot' => $bot]))
        ->assertForbidden();
});

test('users can update a current-team bot but cannot change its team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    $response = $this
        ->actingAs($user)
        ->patch(route('bots.update', ['current_team' => $team->slug, 'bot' => $bot]), [
            'name' => 'Updated Bot',
            'slug' => $bot->slug,
            'default_language' => 'en',
            'team_id' => $otherTeam->id,
        ]);

    $response->assertRedirect(route('bots.show', [
        'current_team' => $team->slug,
        'bot' => $bot,
    ]));

    expect($bot->fresh()->name)->toBe('Updated Bot')
        ->and($bot->fresh()->team_id)->toBe($team->id);
});

test('users cannot update a bot from another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $otherTeam->id]);

    $this->actingAs($user)
        ->patch(route('bots.update', ['current_team' => $team->slug, 'bot' => $bot]), [
            'name' => 'Should Not Update',
            'slug' => $bot->slug,
            'default_language' => 'en',
        ])
        ->assertForbidden();

    expect($bot->fresh()->name)->not->toBe('Should Not Update');
});

test('users can soft-delete a current-team bot', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->delete(route('bots.destroy', ['current_team' => $team->slug, 'bot' => $bot]))
        ->assertRedirect(route('bots.index', ['current_team' => $team->slug]));

    $this->assertSoftDeleted('bots', ['id' => $bot->id]);
});

test('users cannot delete a bot from another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $otherTeam->id]);

    $this->actingAs($user)
        ->delete(route('bots.destroy', ['current_team' => $team->slug, 'bot' => $bot]))
        ->assertForbidden();

    $this->assertDatabaseHas('bots', [
        'id' => $bot->id,
        'deleted_at' => null,
    ]);
});

test('bot validation requires the identity fields', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->post(route('bots.store', ['current_team' => $team->slug]), [])
        ->assertSessionHasErrors(['name', 'slug', 'default_language']);
});
