<?php

namespace App\Services\Search\Engines;

use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Services\Search\Contracts\SearchEngine;
use App\Services\Search\Data\SearchFilter;
use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Data\SearchResult;
use App\Services\Search\Enums\SearchOperator;
use App\Services\Typesense\TypesenseClient;
use App\Services\Typesense\TypesenseCollectionManager;
use App\Services\Typesense\TypesenseQueryTranslator;
use Illuminate\Database\Eloquent\Collection;

class TypesenseSearchEngine implements SearchEngine
{
    public function __construct(
        private readonly TypesenseClient $client,
        private readonly TypesenseCollectionManager $collectionManager,
        private readonly TypesenseQueryTranslator $queryTranslator,
        private readonly PostgresSearchEngine $postgresSearchEngine,
    ) {}

    public function search(SearchQuery $query): SearchResult
    {
        $fields = DatasetField::query()
            ->where('dataset_id', $query->datasetId)
            ->get()
            ->keyBy('key');

        if (collect($query->filters)->contains(
            fn (SearchFilter $filter): bool => $filter->operator === SearchOperator::Contains,
        )) {
            return $this->postgresSearchEngine->search($query);
        }

        $dataset = Dataset::query()->findOrFail($query->datasetId);
        $collectionName = $this->collectionManager->collectionNameForDataset($dataset);
        $response = $this->client->search(
            $collectionName,
            $this->queryTranslator->translate($query, $fields),
        );
        $recordIds = collect((array) ($response['hits'] ?? []))
            ->map(fn (array $hit): mixed => $hit['document']['dataset_record_id'] ?? null)
            ->filter(fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($recordIds === []) {
            return new SearchResult(records: [], total: 0);
        }

        /** @var Collection<int, DatasetRecord> $records */
        $records = DatasetRecord::query()
            ->active()
            ->where('dataset_id', $query->datasetId)
            ->whereIn('id', $recordIds)
            ->get()
            ->keyBy('id');
        /** @var list<DatasetRecord> $orderedRecords */
        $orderedRecords = array_values(collect($recordIds)
            ->map(fn (int $id): ?DatasetRecord => $records->get($id))
            ->filter(fn (?DatasetRecord $record): bool => $record instanceof DatasetRecord)
            ->values()
            ->all());

        return new SearchResult(
            records: $orderedRecords,
            total: count($orderedRecords),
        );
    }
}
