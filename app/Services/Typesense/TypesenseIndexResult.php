<?php

namespace App\Services\Typesense;

final readonly class TypesenseIndexResult
{
    public function __construct(
        public string $collectionName,
        public int $indexedRecords,
        public int $removedDocuments,
        public bool $schemaRebuilt,
    ) {}
}
