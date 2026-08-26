<?php

use App\Enums\TeamRole;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DataSource;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function fieldPayload(array $overrides = []): array
{
    return [
        'source_path' => 'attributes.storage',
        'key' => 'storage_gb',
        'canonical_name' => 'storage',
        'label' => 'Storage',
        'data_type' => 'integer',
        'semantic_type' => 'storage',
        'description' => 'Storage capacity in gigabytes.',
        'is_searchable' => '1',
        'is_filterable' => '1',
        'is_sortable' => '0',
        'is_semantic' => '0',
        'is_displayable' => '1',
        'normalizer' => null,
        'config' => '{"unit":"gb"}',
        'position' => 1,
        ...$overrides,
    ];
}

function prepareFieldTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataSource = DataSource::factory()->create(['team_id' => $team->id]);
    $dataset = Dataset::factory()->create([
        'team_id' => $team->id,
        'data_source_id' => $dataSource->id,
    ]);

    return [$user, $team, $dataset];
}

test('users can access the dataset field create page for their dataset', function () {
    [$user, $team, $dataset] = prepareFieldTeam();

    $this->actingAs($user)
        ->get(route('datasets.fields.create', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('datasets/fields/create')
            ->where('dataset.id', $dataset->id),
        );
});

test('a field can be created under the current teams dataset', function () {
    [$user, $team, $dataset] = prepareFieldTeam();

    $response = $this
        ->actingAs($user)
        ->post(route('datasets.fields.store', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]), fieldPayload([
            'dataset_id' => 999999,
        ]));

    $field = DatasetField::query()->where('key', 'storage_gb')->firstOrFail();

    $response->assertRedirect(route('datasets.show', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]));

    expect($field->dataset_id)->toBe($dataset->id)
        ->and($field->data_type)->toBe('integer')
        ->and($field->is_searchable)->toBeTrue()
        ->and($field->is_filterable)->toBeTrue()
        ->and($field->config)->toBe(['unit' => 'gb']);
});

test('users can update their dataset field mapping', function () {
    [$user, $team, $dataset] = prepareFieldTeam();
    $otherDataset = Dataset::factory()->create(['team_id' => $team->id]);
    $field = DatasetField::factory()->create(['dataset_id' => $dataset->id]);

    $response = $this
        ->actingAs($user)
        ->patch(route('datasets.fields.update', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
            'field' => $field,
        ]), fieldPayload([
            'source_path' => 'price_gel',
            'key' => 'price',
            'label' => 'Price',
            'data_type' => 'decimal',
            'dataset_id' => $otherDataset->id,
            'is_filterable' => '1',
        ]));

    $response->assertRedirect(route('datasets.show', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]));

    $updatedField = $field->fresh();

    expect($updatedField->dataset_id)->toBe($dataset->id)
        ->and($updatedField->key)->toBe('price')
        ->and($updatedField->data_type)->toBe('decimal')
        ->and($updatedField->is_filterable)->toBeTrue();
});

test('users can delete their dataset field mapping', function () {
    [$user, $team, $dataset] = prepareFieldTeam();
    $field = DatasetField::factory()->create(['dataset_id' => $dataset->id]);

    $this->actingAs($user)
        ->delete(route('datasets.fields.destroy', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
            'field' => $field,
        ]))
        ->assertRedirect(route('datasets.show', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]));

    $this->assertModelMissing($field);
});

test('users cannot access a field under another teams dataset', function () {
    [$user, $team, $dataset] = prepareFieldTeam();
    $otherTeam = Team::factory()->create();
    $otherDataSource = DataSource::factory()->create(['team_id' => $otherTeam->id]);
    $otherDataset = Dataset::factory()->create([
        'team_id' => $otherTeam->id,
        'data_source_id' => $otherDataSource->id,
    ]);
    $field = DatasetField::factory()->create(['dataset_id' => $otherDataset->id]);

    $this->actingAs($user)
        ->get(route('datasets.fields.edit', [
            'current_team' => $team->slug,
            'dataset' => $otherDataset,
            'field' => $field,
        ]))
        ->assertForbidden();
});

test('a field cannot be manipulated through a different dataset parent route', function () {
    [$user, $team, $dataset] = prepareFieldTeam();
    $otherDataset = Dataset::factory()->create(['team_id' => $team->id]);
    $field = DatasetField::factory()->create(['dataset_id' => $otherDataset->id]);

    $this->actingAs($user)
        ->patch(route('datasets.fields.update', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
            'field' => $field,
        ]), fieldPayload(['key' => 'should_not_update']))
        ->assertNotFound();

    expect($field->fresh()->key)->not->toBe('should_not_update');
});

test('field keys are unique within a dataset but may repeat in another dataset', function () {
    [$user, $team, $dataset] = prepareFieldTeam();
    $otherDataset = Dataset::factory()->create(['team_id' => $team->id]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'brand',
    ]);

    $this->actingAs($user)
        ->post(route('datasets.fields.store', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]), fieldPayload(['key' => 'brand']))
        ->assertSessionHasErrors('key');

    $this->actingAs($user)
        ->post(route('datasets.fields.store', [
            'current_team' => $team->slug,
            'dataset' => $otherDataset,
        ]), fieldPayload(['key' => 'brand']))
        ->assertRedirect();

    expect(DatasetField::query()
        ->where('dataset_id', $otherDataset->id)
        ->where('key', 'brand')
        ->exists())->toBeTrue();
});

test('field validation enforces supported types, flags, position, and JSON config', function () {
    [$user, $team, $dataset] = prepareFieldTeam();

    $this->actingAs($user)
        ->post(route('datasets.fields.store', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]), fieldPayload([
            'data_type' => 'number',
            'is_searchable' => 'maybe',
            'position' => -1,
            'config' => 'not-json',
        ]))
        ->assertSessionHasErrors(['data_type', 'is_searchable', 'position', 'config']);
});
