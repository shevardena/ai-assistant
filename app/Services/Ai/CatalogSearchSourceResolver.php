<?php

namespace App\Services\Ai;

use App\Enums\DatasetStatus;
use App\Models\Bot;
use App\Models\BotDataset;
use App\Models\Dataset;
use App\Services\Api\RuntimeApiOperation;
use App\Services\Api\RuntimeApiOperationResolver;

final class CatalogSearchSourceResolver
{
    public function __construct(private readonly RuntimeApiOperationResolver $operations) {}

    /**
     * @return array{eligible: list<array<string, mixed>>, rejected: list<array<string, mixed>>, requested_dataset: string|null}
     */
    public function resolve(
        Bot $bot,
        ?string $requestedDataset = null,
        bool $restrictToRequested = false,
    ): array {
        $datasets = Dataset::query()
            ->where('team_id', $bot->team_id)
            ->with('dataSource')
            ->get();
        $attachments = $bot->botDatasets()
            ->whereIn('dataset_id', $datasets->pluck('id'))
            ->get()
            ->keyBy('dataset_id');
        $eligible = [];
        $rejected = [];

        foreach ($datasets as $dataset) {
            $attachment = $attachments->get($dataset->id);
            $base = [
                'type' => 'dataset',
                'id' => (int) $dataset->id,
                'name' => (string) $dataset->name,
                'slug' => (string) $dataset->slug,
            ];

            if (! $attachment instanceof BotDataset) {
                $rejected[] = [...$base, 'reason' => 'not_attached'];

                continue;
            }

            if (! $attachment->is_enabled) {
                $rejected[] = [...$base, 'reason' => 'disabled_pivot'];

                continue;
            }

            if (! in_array($dataset->entity_type, Dataset::catalogEntityTypes(), true)) {
                $rejected[] = [...$base, 'reason' => 'unsupported_entity_type'];

                continue;
            }

            if (! in_array($dataset->retrieval_mode, ['indexed', 'hybrid'], true)) {
                $rejected[] = [...$base, 'reason' => 'unsupported_retrieval_mode'];

                continue;
            }

            if ($dataset->status !== DatasetStatus::Ready->value) {
                $rejected[] = [...$base, 'reason' => 'dataset_not_ready'];

                continue;
            }

            $eligible[] = [
                ...$base,
                'mode' => $this->datasetMode($dataset),
                'dataset' => $dataset,
                'priority' => (int) $attachment->priority,
            ];
        }

        usort($eligible, static fn (array $left, array $right): int => [$left['priority'], $left['id']] <=> [$right['priority'], $right['id']]);

        $eligible = array_map(static function (array $source): array {
            unset($source['priority']);

            return $source;
        }, $eligible);

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

        if ($requestedDataset !== null && $restrictToRequested) {
            $matches = array_values(array_filter(
                $eligible,
                static fn (array $source): bool => $source['type'] === 'dataset'
                    && $source['slug'] === $requestedDataset,
            ));

            $eligible = $matches;

            if ($matches !== []) {
                $rejected = array_values(array_filter(
                    $rejected,
                    static fn (array $source): bool => ($source['slug'] ?? null) !== $requestedDataset,
                ));
            } elseif (! array_filter(
                $rejected,
                static fn (array $source): bool => ($source['slug'] ?? null) === $requestedDataset,
            )) {
                $rejected[] = [
                    'type' => 'requested_source',
                    'id' => null,
                    'name' => $requestedDataset,
                    'slug' => $requestedDataset,
                    'reason' => 'not_search_catalog_capable',
                ];
            }
        } elseif ($requestedDataset !== null) {
            usort($eligible, static function (array $left, array $right) use ($requestedDataset): int {
                $leftPreferred = ($left['slug'] ?? null) === $requestedDataset;
                $rightPreferred = ($right['slug'] ?? null) === $requestedDataset;

                return $rightPreferred <=> $leftPreferred;
            });
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
