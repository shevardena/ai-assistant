<?php

use App\Enums\TeamRole;
use App\Models\Dataset;
use App\Models\DatasetRecord;
use App\Models\Team;
use App\Models\User;
use App\Services\Typesense\TypesenseDatasetSync;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\mock;

test('team members can open company knowledge and add a manual article', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);

    $this->actingAs($user)
        ->get(route('knowledge.index', $team->slug))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge/index')
            ->where('dataset.name', 'Company knowledge')
            ->where('dataset.recordCount', 0));

    $dataset = Dataset::query()
        ->where('team_id', $team->id)
        ->where('entity_type', 'knowledge')
        ->firstOrFail();

    expect($dataset->data_source_id)->toBeNull()
        ->and($dataset->fields()->pluck('key')->all())
        ->toBe(['title', 'content', 'category', 'source_url', 'language']);

    $sync = mock(TypesenseDatasetSync::class);
    $sync->shouldReceive('syncRecord')->once();
    app()->instance(TypesenseDatasetSync::class, $sync);

    $this->actingAs($user)
        ->post(route('datasets.records.store', [$team->slug, $dataset]), [
            'values' => [
                'title' => 'Returns policy',
                'content' => 'Customers can return unused items within 14 days.',
                'category' => 'returns',
                'source_url' => 'https://example.com/returns',
                'language' => 'en',
            ],
        ])
        ->assertRedirect();

    expect(DatasetRecord::query()->where('dataset_id', $dataset->id)->count())->toBe(1);
});

test('company knowledge is team scoped and does not reuse another team dataset', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);

    $foreignTeam = Team::factory()->create();

    $this->actingAs($user)->get(route('knowledge.index', $team->slug))->assertSuccessful();

    expect(Dataset::query()->where('team_id', $team->id)->where('slug', 'company-knowledge')->exists())->toBeTrue()
        ->and(Dataset::query()->where('team_id', $foreignTeam->id)->where('slug', 'company-knowledge')->exists())->toBeFalse();
});
