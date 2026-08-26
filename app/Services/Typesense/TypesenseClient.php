<?php

namespace App\Services\Typesense;

use Typesense\Client as VendorClient;

class TypesenseClient
{
    public function __construct(private readonly VendorClient $client) {}

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function createCollection(array $schema): array
    {
        return $this->client->collections->create($schema);
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveCollection(string $collectionName): array
    {
        return $this->client->collections[$collectionName]->retrieve();
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteCollection(string $collectionName): array
    {
        return $this->client->collections[$collectionName]->delete();
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, bool|int|string>  $queryParameters
     * @return array<string, mixed>
     */
    public function deleteDocuments(string $collectionName, array $queryParameters): array
    {
        return $this->client->collections[$collectionName]->documents->delete($queryParameters);
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    public function importDocuments(string $collectionName, array $documents): array
    {
        /** @var list<array<string, mixed>> $result */
        $result = $this->client->collections[$collectionName]->documents->import(
            $documents,
            ['action' => 'upsert'],
        );

        return $result;
    }

    /**
     * @param  array<string, string|int>  $searchParameters
     * @return array<string, mixed>
     */
    public function search(string $collectionName, array $searchParameters): array
    {
        return $this->client->collections[$collectionName]->documents->search($searchParameters);
    }
}
