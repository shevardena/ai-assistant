<?php

use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\BotDataset;
use App\Models\Dataset;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

function botDatasetFixture(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $firstDataset = Dataset::factory()->ready()->create(['team_id' => $team->id]);
    $secondDataset = Dataset::factory()->ready()->create(['team_id' => $team->id]);

    return [$user, $team, $bot, $firstDataset, $secondDataset];
}

test('a current-team user can attach multiple datasets to a bot', function () {
    [$user, $team, $bot, $firstDataset, $secondDataset] = botDatasetFixture();

    $this->actingAs($user)
        ->put(route('bots.datasets.update', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]), [
            'datasets' => [$firstDataset->id, $secondDataset->id],
        ])
        ->assertRedirect(route('bots.show', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]));

    expect($bot->botDatasets()->pluck('dataset_id')->all())
        ->toEqualCanonicalizing([$firstDataset->id, $secondDataset->id])
        ->and($bot->fresh()->status)->toBe('ready');
});

test('a current-team user can detach all datasets from a bot', function () {
    [$user, $team, $bot, $firstDataset] = botDatasetFixture();
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $firstDataset->id,
    ]);

    $this->actingAs($user)
        ->put(route('bots.datasets.update', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]), ['datasets' => []])
        ->assertRedirect();

    expect($bot->botDatasets()->count())->toBe(0)
        ->and($bot->fresh()->status)->toBe('draft');
});

test('existing pivot metadata is preserved and duplicate IDs create one attachment', function () {
    [$user, $team, $bot, $firstDataset, $secondDataset] = botDatasetFixture();
    $existing = BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $firstDataset->id,
        'priority' => 9,
        'is_enabled' => false,
        'settings' => ['display' => 'compact'],
    ]);

    $this->actingAs($user)
        ->put(route('bots.datasets.update', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]), [
            'datasets' => [$firstDataset->id, $secondDataset->id, $secondDataset->id],
        ])
        ->assertRedirect();

    $existing->refresh();

    expect($bot->botDatasets()->count())->toBe(2)
        ->and($existing->priority)->toBe(9)
        ->and($existing->is_enabled)->toBeFalse()
        ->and($existing->settings)->toBe(['display' => 'compact']);
});

test('cross-team dataset IDs are rejected and existing attachments remain unchanged', function () {
    [$user, $team, $bot, $firstDataset] = botDatasetFixture();
    $otherTeam = Team::factory()->create();
    $otherDataset = Dataset::factory()->ready()->create(['team_id' => $otherTeam->id]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $firstDataset->id,
    ]);

    $this->actingAs($user)
        ->put(route('bots.datasets.update', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]), [
            'datasets' => [$otherDataset->id],
        ])
        ->assertSessionHasErrors('datasets');

    expect($bot->fresh()->botDatasets()->pluck('dataset_id')->all())
        ->toBe([$firstDataset->id]);
});

test('a bot from another team cannot be modified', function () {
    [$user, $team, $bot] = botDatasetFixture();
    $otherTeam = Team::factory()->create();
    $otherBot = Bot::factory()->create(['team_id' => $otherTeam->id]);
    $dataset = Dataset::factory()->ready()->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->put(route('bots.datasets.update', [
            'current_team' => $team->slug,
            'bot' => $otherBot,
        ]), [
            'datasets' => [$dataset->id],
        ])
        ->assertForbidden();
});

test('bot show payload contains only current-team datasets and their attachment state', function () {
    [$user, $team, $bot, $firstDataset] = botDatasetFixture();
    $otherTeam = Team::factory()->create();
    $otherDataset = Dataset::factory()->ready()->create(['team_id' => $otherTeam->id]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $firstDataset->id,
    ]);

    $this->actingAs($user)
        ->get(route('bots.show', [
            'current_team' => $team->slug,
            'bot' => $bot,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('bots/show')
            ->has('bot.datasets', 2)
            ->where('bot.datasets', fn (Collection $datasets): bool => $datasets
                ->pluck('id')
                ->contains($firstDataset->id)
                && ! $datasets->pluck('id')->contains($otherDataset->id)
                && $datasets->firstWhere('id', $firstDataset->id)['attached'] === true),
        );
});
