<?php

namespace App\Console\Commands\Search;

use App\Models\Dataset;
use App\Services\Typesense\TypesenseDatasetIndexer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('search:reindex-dataset {dataset : Dataset ID to reindex}')]
#[Description('Rebuild one Dataset in the configured Typesense index')]
class ReindexDatasetSearch extends Command
{
    public function handle(TypesenseDatasetIndexer $indexer): int
    {
        $dataset = Dataset::query()->find($this->argument('dataset'));

        if (! $dataset instanceof Dataset) {
            $this->error('The requested Dataset was not found.');

            return self::FAILURE;
        }

        try {
            $result = $indexer->reindex($dataset);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The Dataset could not be indexed. Verify the Typesense service and configuration.');

            return self::FAILURE;
        }

        $this->info("Indexed {$result->indexedRecords} active records into {$result->collectionName}.");
        $this->line("Removed {$result->removedDocuments} previous documents.");

        return self::SUCCESS;
    }
}
