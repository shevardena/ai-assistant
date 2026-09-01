<?php

use App\Models\ApiOperation;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\DataSource;
use App\Models\DataSourceCredential;
use App\Models\SourceRun;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: User, 1: DataSource, 2: Dataset, 3: ApiOperation}
 */
function restApiImportContext(array $operationOverrides = []): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $dataSource = DataSource::factory()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
    ]);
    $dataset = Dataset::factory()->create([
        'team_id' => $team->id,
        'data_source_id' => $dataSource->id,
        'primary_key_path' => 'id',
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'source_path' => 'id',
        'key' => 'id',
        'data_type' => 'string',
        'position' => 0,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'source_path' => 'name',
        'key' => 'name',
        'data_type' => 'string',
        'position' => 1,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'source_path' => 'price',
        'key' => 'price',
        'data_type' => 'decimal',
        'position' => 2,
    ]);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $dataSource->id,
        'method' => 'GET',
        'path' => '/products',
        'response_mapping' => [
            'records_path' => 'products',
            'sync_mode' => 'full_snapshot',
        ],
        ...$operationOverrides,
    ]);

    return [$user, $dataSource, $dataset, $operation];
}

function restApiJsonResponse(array $payload, int $status = 200): PromiseInterface
{
    return Http::response($payload, $status, ['Content-Type' => 'application/json']);
}

test('imports JSON API records through the existing mapping and normalization pipeline', function () {
    Http::fake([
        'https://api.example.test/*' => restApiJsonResponse([
            'products' => [
                ['id' => 'A', 'name' => 'Phone A', 'price' => '499'],
                ['id' => 'B', 'name' => 'Phone B', 'price' => '799'],
            ],
        ]),
    ]);
    [$user, $dataSource, $dataset, $operation] = restApiImportContext();

    $this->actingAs($user)
        ->post(route('datasets.api-imports.store', [
            'current_team' => $user->currentTeam->slug,
            'dataset' => $dataset,
        ]), ['api_operation_id' => $operation->id])
        ->assertRedirect(route('datasets.show', [
            'current_team' => $user->currentTeam->slug,
            'dataset' => $dataset,
        ]));

    $records = DatasetRecord::query()->where('dataset_id', $dataset->id)->orderBy('external_id')->get();
    $run = SourceRun::query()->where('dataset_id', $dataset->id)->firstOrFail();

    expect($records)->toHaveCount(2)
        ->and($records[0]->payload['name'])->toBe('Phone A')
        ->and((float) $records[0]->payload['price'])->toBe(499.0)
        ->and($run->status)->toBe('completed')
        ->and($run->rows_read)->toBe(2)
        ->and($run->rows_written)->toBe(2)
        ->and($run->rows_failed)->toBe(0)
        ->and($run->metadata['pages_fetched'])->toBe(1)
        ->and($dataSource->fresh()->status)->toBe('ready')
        ->and($dataSource->fresh()->last_synced_at)->not->toBeNull()
        ->and($dataset->fresh()->status)->toBe('ready');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.example.test/products');
});

test('automatically creates dataset field mappings on the first API import', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $dataSource = DataSource::factory()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
    ]);
    $dataset = Dataset::factory()->create([
        'team_id' => $team->id,
        'data_source_id' => $dataSource->id,
        'primary_key_path' => 'id',
    ]);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $dataSource->id,
        'method' => 'GET',
        'path' => '/products',
        'response_mapping' => [
            'records_path' => 'data',
            'sync_mode' => 'full_snapshot',
        ],
    ]);
    $response = [
        'data' => [[
            'id' => 35,
            'name' => 'Phone',
            'price' => '160.00',
            'image' => 'https://cdn.example.test/phone.jpg',
            'url' => 'https://example.test/products/35',
        ]],
    ];

    Http::fakeSequence()
        ->push($response)
        ->push($response);

    $this->actingAs($user)
        ->post(route('datasets.api-imports.store', [
            'current_team' => $team->slug,
            'dataset' => $dataset,
        ]), ['api_operation_id' => $operation->id])
        ->assertRedirect();

    expect(DatasetField::query()->where('dataset_id', $dataset->id)->pluck('key')->all())
        ->toBe(['id', 'name', 'price', 'image', 'url'])
        ->and(DatasetField::query()->where('dataset_id', $dataset->id)->where('key', 'price')->value('data_type'))
        ->toBe('decimal')
        ->and(DatasetField::query()->where('dataset_id', $dataset->id)->where('key', 'price')->value('semantic_type'))
        ->toBe('price')
        ->and(DatasetRecord::query()->where('dataset_id', $dataset->id)->count())
        ->toBe(1)
        ->and(SourceRun::query()->where('dataset_id', $dataset->id)->firstOrFail()->status)
        ->toBe('completed');

    Http::assertSentCount(2);
});

test('imports nested API records and applies bearer credentials without exposing them in metadata', function () {
    Http::fake([
        'https://api.example.test/*' => restApiJsonResponse([
            'data' => ['items' => [['id' => 'A', 'name' => 'Phone A', 'price' => '20.0']]],
        ]),
    ]);
    [$user, $dataSource, $dataset, $operation] = restApiImportContext([
        'response_mapping' => [
            'records_path' => 'data.items',
            'sync_mode' => 'full_snapshot',
        ],
    ]);
    DataSourceCredential::factory()->create([
        'data_source_id' => $dataSource->id,
        'key' => 'bearer_token',
        'encrypted_value' => 'top-secret-token',
    ]);

    $this->actingAs($user)->post(route('datasets.api-imports.store', [
        'current_team' => $user->currentTeam->slug,
        'dataset' => $dataset,
    ]), ['api_operation_id' => $operation->id])->assertRedirect();

    $run = SourceRun::query()->where('dataset_id', $dataset->id)->firstOrFail();

    Http::assertSent(fn ($request): bool => $request->header('Authorization') === ['Bearer top-secret-token']);
    expect($run->metadata)->not->toContain('top-secret-token')
        ->and((float) DatasetRecord::query()->where('dataset_id', $dataset->id)->firstOrFail()->payload['price'])->toBe(20.0);
});

test('full snapshot API imports reconcile active records', function () {
    [$user, $dataSource, $dataset, $operation] = restApiImportContext();
    DatasetRecord::factory()->createMany([
        ['dataset_id' => $dataset->id, 'external_id' => 'A', 'payload' => ['name' => 'A']],
        ['dataset_id' => $dataset->id, 'external_id' => 'B', 'payload' => ['name' => 'B']],
        ['dataset_id' => $dataset->id, 'external_id' => 'C', 'payload' => ['name' => 'C']],
    ]);
    Http::fake([
        'https://api.example.test/*' => restApiJsonResponse([
            'products' => [
                ['id' => 'A', 'name' => 'A', 'price' => '1'],
                ['id' => 'C', 'name' => 'C', 'price' => '3'],
                ['id' => 'D', 'name' => 'D', 'price' => '4'],
            ],
        ]),
    ]);

    $this->actingAs($user)->post(route('datasets.api-imports.store', [
        'current_team' => $user->currentTeam->slug,
        'dataset' => $dataset,
    ]), ['api_operation_id' => $operation->id])->assertRedirect();

    $records = DatasetRecord::query()->where('dataset_id', $dataset->id)->get()->keyBy('external_id');
    $run = SourceRun::query()->where('dataset_id', $dataset->id)->firstOrFail();

    expect($records->get('A')->is_active)->toBeTrue()
        ->and($records->get('B')->is_active)->toBeFalse()
        ->and($records->get('C')->is_active)->toBeTrue()
        ->and($records->get('D')->is_active)->toBeTrue()
        ->and($run->metadata['records_deactivated'])->toBe(1);
});

test('page pagination imports all pages and stops at an empty page', function () {
    [$user, $dataSource, $dataset, $operation] = restApiImportContext([
        'response_mapping' => [
            'records_path' => 'products',
            'sync_mode' => 'full_snapshot',
            'pagination' => [
                'type' => 'page',
                'parameter' => 'page',
                'start' => 1,
                'max_pages' => 5,
            ],
        ],
    ]);
    Http::fakeSequence()
        ->push(['products' => [['id' => 'A', 'name' => 'A', 'price' => '1']]], 200, ['Content-Type' => 'application/json'])
        ->push(['products' => [['id' => 'B', 'name' => 'B', 'price' => '2']]], 200, ['Content-Type' => 'application/json'])
        ->push(['products' => []], 200, ['Content-Type' => 'application/json']);

    $this->actingAs($user)->post(route('datasets.api-imports.store', [
        'current_team' => $user->currentTeam->slug,
        'dataset' => $dataset,
    ]), ['api_operation_id' => $operation->id])->assertRedirect();

    $run = SourceRun::query()->where('dataset_id', $dataset->id)->firstOrFail();

    expect(DatasetRecord::query()->where('dataset_id', $dataset->id)->count())->toBe(2)
        ->and($run->metadata['pages_fetched'])->toBe(3);
    Http::assertSentCount(3);
});

test('a valid empty full snapshot deactivates existing records', function () {
    [$user, $dataSource, $dataset, $operation] = restApiImportContext();
    DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'A',
        'is_active' => true,
    ]);
    Http::fake([
        'https://api.example.test/*' => restApiJsonResponse(['products' => []]),
    ]);

    $this->actingAs($user)->post(route('datasets.api-imports.store', [
        'current_team' => $user->currentTeam->slug,
        'dataset' => $dataset,
    ]), ['api_operation_id' => $operation->id])->assertRedirect();

    expect(DatasetRecord::query()->where('dataset_id', $dataset->id)->firstOrFail()->is_active)->toBeFalse()
        ->and(SourceRun::query()->where('dataset_id', $dataset->id)->firstOrFail()->status)->toBe('completed');
});

test('a later API snapshot reactivates an inactive record', function () {
    [$user, $dataSource, $dataset, $operation] = restApiImportContext();
    $dataSource->update(['status' => 'error']);
    $dataset->update(['status' => 'error']);
    DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'B',
        'is_active' => false,
    ]);
    Http::fake([
        'https://api.example.test/*' => restApiJsonResponse([
            'products' => [['id' => 'B', 'name' => 'B', 'price' => '5']],
        ]),
    ]);

    $this->actingAs($user)->post(route('datasets.api-imports.store', [
        'current_team' => $user->currentTeam->slug,
        'dataset' => $dataset,
    ]), ['api_operation_id' => $operation->id])->assertRedirect();

    $run = SourceRun::query()->where('dataset_id', $dataset->id)->firstOrFail();

    expect(DatasetRecord::query()->where('dataset_id', $dataset->id)->where('external_id', 'B')->firstOrFail()->is_active)->toBeTrue()
        ->and($run->metadata['records_reactivated'])->toBe(1)
        ->and($dataSource->fresh()->status)->toBe('ready')
        ->and($dataset->fresh()->status)->toBe('ready');
});

test('normalization failures preserve the source ID without deactivating its existing record', function () {
    [$user, $dataSource, $dataset, $operation] = restApiImportContext();
    DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'B',
        'is_active' => true,
    ]);
    Http::fake([
        'https://api.example.test/*' => restApiJsonResponse([
            'products' => [
                ['id' => 'A', 'name' => 'A', 'price' => '1'],
                ['id' => 'B', 'name' => 'B', 'price' => 'not-a-number'],
            ],
        ]),
    ]);

    $this->actingAs($user)->post(route('datasets.api-imports.store', [
        'current_team' => $user->currentTeam->slug,
        'dataset' => $dataset,
    ]), ['api_operation_id' => $operation->id])->assertRedirect();

    $run = SourceRun::query()->where('dataset_id', $dataset->id)->firstOrFail();
    $record = DatasetRecord::query()->where('dataset_id', $dataset->id)->where('external_id', 'B')->firstOrFail();

    expect($record->is_active)->toBeTrue()
        ->and($run->rows_read)->toBe(2)
        ->and($run->rows_written)->toBe(1)
        ->and($run->rows_failed)->toBe(1);
});

test('duplicate API external IDs fail before snapshot writes', function () {
    [$user, $dataSource, $dataset, $operation] = restApiImportContext();
    $existing = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'existing',
    ]);
    Http::fake([
        'https://api.example.test/*' => restApiJsonResponse([
            'products' => [
                ['id' => 'A', 'name' => 'A', 'price' => '1'],
                ['id' => 'A', 'name' => 'A again', 'price' => '2'],
            ],
        ]),
    ]);

    $this->actingAs($user)->post(route('datasets.api-imports.store', [
        'current_team' => $user->currentTeam->slug,
        'dataset' => $dataset,
    ]), ['api_operation_id' => $operation->id])->assertSessionHasErrors('api_operation_id');

    expect(DatasetRecord::query()->where('dataset_id', $dataset->id)->pluck('id')->all())->toBe([$existing->id])
        ->and(SourceRun::query()->where('dataset_id', $dataset->id)->firstOrFail()->status)->toBe('failed')
        ->and($dataSource->fresh()->status)->toBe('error')
        ->and($dataset->fresh()->status)->toBe('error');
});
