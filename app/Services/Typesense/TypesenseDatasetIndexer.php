<?php

namespace App\Services\Typesense;

use App\Models\Dataset;
use App\Models\DatasetRecord;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

class TypesenseDatasetIndexer
{
    private const BATCH_SIZE = 250;

    public function __construct(
        private readonly TypesenseClient $client,
        private readonly TypesenseCollectionManager $collectionManager,
        private readonly TypesenseDocumentMapper $documentMapper,
        private readonly TypesenseSchemaBuilder $schemaBuilder,
    ) {}

    public function reindex(Dataset $dataset): TypesenseIndexResult
    {
        $fields = $dataset->fields()->get();
        $collectionName = $this->collectionManager->collectionNameForDataset($dataset);
        $schema = $this->schemaBuilder->build($collectionName, $fields);
        $schemaRebuilt = $this->collectionManager->ensureCollection($collectionName, $schema);
        $removedDocuments = $this->collectionManager->truncateDocuments($collectionName);
        $indexedRecords = 0;

        $dataset->records()
            ->active()
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function (Collection $records) use ($fields, $collectionName, &$indexedRecords): void {
                /** @var list<array<string, mixed>> $documents */
                $documents = array_values($records
                    ->map(fn (DatasetRecord $record): array => $this->documentMapper->map($record, $fields))
                    ->values()
                    ->all());

                if ($documents === []) {
                    return;
                }

                $responses = $this->client->importDocuments($collectionName, $documents);

                foreach ($responses as $response) {
                    if (($response['success'] ?? true) !== true) {
                        throw new RuntimeException('Typesense rejected a Dataset record during import.');
                    }
                }

                $indexedRecords += count($documents);
            });

        $dataset->forceFill(['last_indexed_at' => now()])->save();

        return new TypesenseIndexResult(
            collectionName: $collectionName,
            indexedRecords: $indexedRecords,
            removedDocuments: $removedDocuments,
            schemaRebuilt: $schemaRebuilt,
        );
    }

    public function upsertRecord(DatasetRecord $record): void
    {
        $fields = $record->dataset->fields()->get();
        $collectionName = $this->collectionManager->collectionNameForDataset($record->dataset);
        $schema = $this->schemaBuilder->build($collectionName, $fields);
        $this->collectionManager->ensureCollection($collectionName, $schema);

        $responses = $this->client->importDocuments($collectionName, [
            $this->documentMapper->map($record, $fields),
        ]);

        foreach ($responses as $response) {
            if (($response['success'] ?? true) !== true) {
                throw new RuntimeException('Typesense rejected a Dataset record during upsert.');
            }
        }
    }

    public function removeRecord(DatasetRecord $record): void
    {
        $collectionName = $this->collectionManager->collectionNameForDataset($record->dataset);

        $this->client->deleteDocuments($collectionName, [
            'filter_by' => 'id:='.((string) $record->id),
        ]);
    }
}
