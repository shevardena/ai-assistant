<?php

use App\Enums\TeamRole;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DataSource;
use App\Models\SourceFile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function mappingContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $dataSource = DataSource::factory()->ready()->create(['team_id' => $team->id]);
    $dataset = Dataset::factory()->create([
        'team_id' => $team->id,
        'data_source_id' => $dataSource->id,
        'primary_key_path' => 'id',
    ]);

    return [$user, $team, $dataSource, $dataset];
}

function mappingSourceFile(
    DataSource $dataSource,
    User $user,
    string $path,
    string $name,
    ?string $contents = null,
): SourceFile {
    Storage::disk('local')->put(
        $path,
        $contents ?? "id,name,brand,price\n1,Phone,Samsung,499.99\n",
    );

    return SourceFile::factory()->create([
        'data_source_id' => $dataSource->id,
        'uploaded_by' => $user->id,
        'disk' => 'local',
        'path' => $path,
        'original_name' => $name,
        'metadata' => ['extension' => pathinfo($name, PATHINFO_EXTENSION)],
        'status' => 'uploaded',
    ]);
}

function mappingFieldPayload(array $overrides = []): array
{
    return [
        'id' => null,
        'source_path' => 'name',
        'key' => 'name',
        'canonical_name' => null,
        'label' => 'Name',
        'data_type' => 'string',
        'semantic_type' => null,
        'description' => null,
        'is_searchable' => true,
        'is_filterable' => false,
        'is_sortable' => false,
        'is_semantic' => false,
        'is_displayable' => true,
        'normalizer' => null,
        'config' => '{}',
        'position' => 0,
        'included' => true,
        ...$overrides,
    ];
}

test('file field discovery is tenant scoped and does not create fields', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = mappingContext();
    $sourceFile = mappingSourceFile($dataSource, $user, 'imports/catalog.csv', 'catalog.csv');

    $response = $this->actingAs($user)->postJson(
        route('datasets.fields.discovery', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]),
        ['source_file_id' => $sourceFile->id],
    );

    $response->assertOk()
        ->assertJsonPath('source_file.id', $sourceFile->id)
        ->assertJsonPath('fields.0.source_path', 'id');

    expect(DatasetField::query()->count())->toBe(0);
});

test('discovery rejects a source file from another data source', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = mappingContext();
    $otherDataSource = DataSource::factory()->ready()->create(['team_id' => $team->id]);
    $otherFile = mappingSourceFile($otherDataSource, $user, 'imports/other.csv', 'other.csv');

    $this->actingAs($user)
        ->postJson(route('datasets.fields.discovery', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]), ['source_file_id' => $otherFile->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source_file_id');
});

test('bulk save creates mappings in submitted order and persists flags', function () {
    [$user, $team, , $dataset] = mappingContext();
    $fields = [
        mappingFieldPayload([
            'source_path' => 'brand',
            'key' => 'brand',
            'label' => 'Brand',
            'is_filterable' => true,
            'position' => 0,
        ]),
        mappingFieldPayload([
            'source_path' => 'name',
            'key' => 'name',
            'label' => 'Product name',
            'is_searchable' => true,
            'position' => 1,
        ]),
        mappingFieldPayload([
            'source_path' => 'price',
            'key' => 'price',
            'label' => 'Price',
            'data_type' => 'decimal',
            'is_sortable' => true,
            'position' => 2,
        ]),
    ];

    $this->actingAs($user)
        ->put(route('datasets.fields.bulk-update', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]), ['fields' => $fields])
        ->assertRedirect(route('datasets.show', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]));

    expect($dataset->fields()->orderBy('position')->pluck('key')->all())
        ->toBe(['brand', 'name', 'price'])
        ->and($dataset->fields()->where('key', 'price')->firstOrFail()->is_sortable)
        ->toBeTrue();
});

test('bulk save preserves existing advanced values while updating editable mappings', function () {
    [$user, $team, , $dataset] = mappingContext();
    $field = DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'source_path' => 'price',
        'key' => 'price',
        'label' => 'Retail price',
        'allowed_operators' => ['gte', 'lte'],
        'aliases' => ['cost'],
        'config' => ['currency' => 'USD'],
    ]);

    $this->actingAs($user)->put(route('datasets.fields.bulk-update', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]), [
        'fields' => [mappingFieldPayload([
            'id' => $field->id,
            'source_path' => 'price',
            'key' => 'price',
            'label' => 'Price',
            'data_type' => 'decimal',
            'config' => '{"currency":"EUR"}',
        ])],
    ])->assertRedirect();

    $field->refresh();

    expect($field->label)->toBe('Price')
        ->and($field->allowed_operators)->toBe(['gte', 'lte'])
        ->and($field->aliases)->toBe(['cost'])
        ->and($field->config)->toBe(['currency' => 'EUR']);
});

test('bulk save rejects a field ID from another dataset without changing either dataset', function () {
    [$user, $team, , $dataset] = mappingContext();
    $otherDataset = Dataset::factory()->create(['team_id' => $team->id]);
    $otherField = DatasetField::factory()->create(['dataset_id' => $otherDataset->id]);

    $this->actingAs($user)
        ->put(route('datasets.fields.bulk-update', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]), ['fields' => [mappingFieldPayload(['id' => $otherField->id])]])
        ->assertSessionHasErrors('fields');

    expect($dataset->fields()->count())->toBe(0)
        ->and($otherField->fresh())->not->toBeNull();
});

test('bulk save rejects invalid types and duplicate keys before saving', function () {
    [$user, $team, , $dataset] = mappingContext();

    $this->actingAs($user)
        ->put(route('datasets.fields.bulk-update', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]), [
            'fields' => [
                mappingFieldPayload(['key' => 'brand', 'source_path' => 'brand']),
                mappingFieldPayload([
                    'key' => 'brand',
                    'source_path' => 'brand_copy',
                    'data_type' => 'money',
                ]),
            ],
        ])
        ->assertSessionHasErrors(['fields.1.data_type', 'fields']);

    expect($dataset->fields()->count())->toBe(0);
});

test('unchecked existing mappings are removed while new unchecked rows are ignored', function () {
    [$user, $team, , $dataset] = mappingContext();
    $existing = DatasetField::factory()->create(['dataset_id' => $dataset->id]);

    $this->actingAs($user)->put(route('datasets.fields.bulk-update', [
        'current_team' => $team->slug,
        'dataset' => $dataset,
    ]), [
        'fields' => [
            mappingFieldPayload([
                'id' => $existing->id,
                'source_path' => $existing->source_path,
                'key' => $existing->key,
                'included' => false,
            ]),
            mappingFieldPayload([
                'source_path' => 'new_field',
                'key' => 'new_field',
                'included' => false,
            ]),
        ],
    ])->assertRedirect();

    expect($dataset->fields()->count())->toBe(0);
});

test('unmapped page returns only source paths without existing mappings', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = mappingContext();
    $sourceFile = mappingSourceFile($dataSource, $user, 'imports/catalog.csv', 'catalog.csv');
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'source_path' => 'name',
        'key' => 'name',
    ]);

    $this->actingAs($user)
        ->get(route('datasets.fields.unmapped.index', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
            'source_file_id' => $sourceFile->id,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('datasets/fields/unmapped')
            ->where('sourceFile.id', $sourceFile->id)
            ->has('fields', 3)
            ->where('fields.0.sourcePath', 'id')
            ->where('fields.1.sourcePath', 'brand')
            ->where('fields.2.sourcePath', 'price'));
});

test('selected unmapped fields are created in order without changing existing mappings', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = mappingContext();
    $sourceFile = mappingSourceFile($dataSource, $user, 'imports/catalog.csv', 'catalog.csv');
    $existing = DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'source_path' => 'name',
        'key' => 'name',
        'label' => 'Product name',
        'is_searchable' => true,
        'position' => 0,
    ]);

    $this->actingAs($user)
        ->post(route('datasets.fields.unmapped.store', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]), [
            'source_file_id' => $sourceFile->id,
            'fields' => [
                [
                    'source_path' => 'brand',
                    'key' => 'manufacturer',
                    'label' => 'Manufacturer',
                    'data_type' => 'string',
                ],
                [
                    'source_path' => 'price',
                    'key' => 'price',
                    'label' => 'Price',
                    'data_type' => 'decimal',
                ],
            ],
        ])
        ->assertRedirect(route('datasets.show', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]));

    expect($dataset->fields()->orderBy('position')->pluck('source_path')->all())
        ->toBe(['name', 'brand', 'price'])
        ->and($existing->fresh()->label)->toBe('Product name')
        ->and($existing->fresh()->is_searchable)->toBeTrue()
        ->and($dataset->fields()->where('source_path', 'brand')->firstOrFail()->is_filterable)
        ->toBeTrue();
});

test('unmapped store rejects a stale source path without creating any fields', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = mappingContext();
    $sourceFile = mappingSourceFile($dataSource, $user, 'imports/catalog.csv', 'catalog.csv');

    $this->actingAs($user)
        ->post(route('datasets.fields.unmapped.store', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]), [
            'source_file_id' => $sourceFile->id,
            'fields' => [mappingFieldPayload([
                'source_path' => 'secret_field',
                'key' => 'secret_field',
                'label' => 'Secret field',
            ])],
        ])
        ->assertSessionHasErrors('fields.0.source_path');

    expect($dataset->fields()->count())->toBe(0);
});

test('unmapped store rejects a key collision without partially creating selections', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = mappingContext();
    $sourceFile = mappingSourceFile($dataSource, $user, 'imports/catalog.csv', 'catalog.csv');
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'source_path' => 'name',
        'key' => 'name',
    ]);

    $this->actingAs($user)
        ->post(route('datasets.fields.unmapped.store', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]), [
            'source_file_id' => $sourceFile->id,
            'fields' => [
                mappingFieldPayload([
                    'source_path' => 'brand',
                    'key' => 'name',
                    'label' => 'Brand',
                ]),
                mappingFieldPayload([
                    'source_path' => 'price',
                    'key' => 'price',
                    'label' => 'Price',
                    'data_type' => 'decimal',
                ]),
            ],
        ])
        ->assertSessionHasErrors('fields.0.key');

    expect($dataset->fields()->pluck('source_path')->all())->toBe(['name']);
});

test('unmapped discovery rejects a source file from another data source', function () {
    Storage::fake('local');
    [$user, $team, $dataSource, $dataset] = mappingContext();
    $otherDataSource = DataSource::factory()->ready()->create(['team_id' => $team->id]);
    $otherFile = mappingSourceFile($otherDataSource, $user, 'imports/other.csv', 'other.csv');

    $this->actingAs($user)
        ->get(route('datasets.fields.unmapped.index', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
            'source_file_id' => $otherFile->id,
        ]))
        ->assertSessionHasErrors('source_file_id');
});
