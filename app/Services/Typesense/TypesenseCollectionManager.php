<?php

namespace App\Services\Typesense;

use App\Models\Dataset;
use Typesense\Exceptions\ObjectNotFound;

class TypesenseCollectionManager
{
    public function __construct(private readonly TypesenseClient $client) {}

    public function collectionNameForDataset(Dataset $dataset): string
    {
        return 'dataset_'.$dataset->id;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function ensureCollection(string $collectionName, array $schema): bool
    {
        try {
            $existing = $this->client->retrieveCollection($collectionName);
        } catch (ObjectNotFound) {
            $this->client->createCollection($schema);

            return false;
        }

        if ($this->schemasMatch($existing, $schema)) {
            return false;
        }

        $this->client->deleteCollection($collectionName);
        $this->client->createCollection($schema);

        return true;
    }

    public function truncateDocuments(string $collectionName): int
    {
        $response = $this->client->deleteDocuments($collectionName, ['truncate' => true]);

        return (int) ($response['num_deleted'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $expected
     */
    private function schemasMatch(array $existing, array $expected): bool
    {
        $existingFields = array_map(
            fn (array $field): array => $this->normalizedField($field),
            (array) ($existing['fields'] ?? []),
        );
        $expectedFields = array_map(
            fn (array $field): array => $this->normalizedField($field),
            (array) ($expected['fields'] ?? []),
        );

        usort($existingFields, fn (array $left, array $right): int => $left['name'] <=> $right['name']);
        usort($expectedFields, fn (array $left, array $right): int => $left['name'] <=> $right['name']);

        return $existingFields === $expectedFields;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array{name: string, type: string, facet: bool, optional: bool, sort: bool}
     */
    private function normalizedField(array $field): array
    {
        return [
            'name' => (string) ($field['name'] ?? ''),
            'type' => (string) ($field['type'] ?? ''),
            'facet' => (bool) ($field['facet'] ?? false),
            'optional' => (bool) ($field['optional'] ?? false),
            'sort' => (bool) ($field['sort'] ?? false),
        ];
    }
}
