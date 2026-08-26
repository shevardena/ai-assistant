<?php

use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Engines\TypesenseSearchEngine;
use App\Services\Typesense\TypesenseClient;
use App\Services\Typesense\TypesenseCollectionManager;
use App\Services\Typesense\TypesenseDatasetIndexer;

test('reindexes active records and keeps Dataset collections isolated', function () {
    if (filter_var(env('TYPESENSE_INTEGRATION', false), FILTER_VALIDATE_BOOL) !== true) {
        $this->markTestSkipped('Set TYPESENSE_INTEGRATION=true to run against a real Typesense service.');
    }

    $dataset = Dataset::factory()->create();
    $otherDataset = Dataset::factory()->create();
    foreach ([$dataset, $otherDataset] as $currentDataset) {
        DatasetField::factory()->create([
            'dataset_id' => $currentDataset->id,
            'key' => 'name',
            'source_path' => 'name',
            'data_type' => 'string',
            'is_searchable' => true,
        ]);
    }
    DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'active',
        'payload' => ['name' => 'Active product'],
        'searchable_text' => 'Active product',
        'is_active' => true,
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'inactive',
        'payload' => ['name' => 'Inactive product'],
        'searchable_text' => 'Inactive product',
        'is_active' => false,
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $otherDataset->id,
        'external_id' => 'other',
        'payload' => ['name' => 'Other dataset product'],
        'searchable_text' => 'Other dataset product',
        'is_active' => true,
    ]);

    $client = app(TypesenseClient::class);
    $manager = app(TypesenseCollectionManager::class);

    try {
        app(TypesenseDatasetIndexer::class)->reindex($dataset);
        app(TypesenseDatasetIndexer::class)->reindex($otherDataset);

        $engine = app(TypesenseSearchEngine::class);
        $datasetResult = $engine->search(new SearchQuery($dataset->id, 'product'));
        $otherDatasetResult = $engine->search(new SearchQuery($otherDataset->id, 'product'));

        expect($datasetResult->records)->toHaveCount(1)
            ->and($datasetResult->records[0]->external_id)->toBe('active')
            ->and($otherDatasetResult->records)->toHaveCount(1)
            ->and($otherDatasetResult->records[0]->external_id)->toBe('other');
    } catch (Throwable $exception) {
        throw $exception;
    } finally {
        foreach ([$dataset, $otherDataset] as $currentDataset) {
            try {
                $client->deleteCollection($manager->collectionNameForDataset($currentDataset));
            } catch (Throwable) {
                // The integration cleanup should not hide the test result.
            }
        }
    }

});
