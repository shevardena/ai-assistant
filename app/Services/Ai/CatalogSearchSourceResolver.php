<?php

namespace App\Services\Ai;

use App\Models\Bot;
use App\Models\Dataset;
use App\Services\Api\RuntimeApiOperation;
use App\Services\Api\RuntimeApiOperationResolver;

final class CatalogSearchSourceResolver
{
    public function __construct(private readonly RuntimeApiOperationResolver $operations) {}

    /**
     * @return array{eligible: list<array<string, mixed>>, rejected: list<array<string, mixed>>, requested_dataset: string|null}
     */
    public function resolve(Bot $bot, ?string $requestedDataset = null): array
    {
        $datasets = $bot->datasets()
            ->wherePivot('is_enabled', true)
            ->where('datasets.team_id', $bot->team_id)
            ->catalog()
            ->ready()
            ->with('dataSource')
            ->orderBy('bot_datasets.priority')
            ->get();
        $eligible = [];

        foreach ($datasets as $dataset) {
            $eligible[] = [
                'type' => 'dataset',
                'id' => (int) $dataset->id,
                'name' => (string) $dataset->name,
                'slug' => (string) $dataset->slug,
                'mode' => $this->datasetMode($dataset),
                'dataset' => $dataset,
            ];
        }

        $operation = $this->operations->resolveRead($bot, 'search_catalog');

        if ($operation instanceof RuntimeApiOperation) {
            $eligible[] = [
                'type' => 'api_operation',
                'id' => (int) $operation->operation->id,
                'name' => (string) $operation->operation->name,
                'key' => (string) $operation->operation->key,
                'mode' => 'live',
                'operation' => $operation,
            ];
        }

        $rejected = [];

        if ($requestedDataset !== null) {
            $requested = $bot->datasets()
                ->wherePivot('is_enabled', true)
                ->where('datasets.team_id', $bot->team_id)
                ->where('datasets.slug', $requestedDataset)
                ->first();
            $matches = array_values(array_filter(
                $eligible,
                static fn (array $source): bool => $source['type'] === 'dataset'
                    && $source['slug'] === $requestedDataset,
            ));

            if ($matches !== []) {
                $eligible = $matches;
            } else {
                $rejected[] = [
                    'type' => $requested instanceof Dataset ? 'dataset' : 'requested_source',
                    'id' => $requested instanceof Dataset ? (int) $requested->id : null,
                    'name' => $requested instanceof Dataset ? (string) $requested->name : $requestedDataset,
                    'slug' => $requestedDataset,
                    'reason' => 'not_search_catalog_capable',
                ];
            }
        }

        return [
            'eligible' => $eligible,
            'rejected' => $rejected,
            'requested_dataset' => $requestedDataset,
        ];
    }

    private function datasetMode(Dataset $dataset): string
    {
        return in_array($dataset->dataSource?->type, ['rest_api', 'graphql_api'], true)
            ? 'synced'
            : 'indexed';
    }
}
