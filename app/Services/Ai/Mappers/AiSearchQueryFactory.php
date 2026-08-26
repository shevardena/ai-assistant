<?php

namespace App\Services\Ai\Mappers;

use App\Models\Bot;
use App\Models\Dataset;
use App\Services\Ai\AiException;
use App\Services\Search\Data\SearchFilter;
use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Data\SearchSort;
use App\Services\Search\Enums\SearchOperator;
use App\Services\Search\Enums\SearchSortDirection;

class AiSearchQueryFactory
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return array{dataset: Dataset, query: SearchQuery}
     */
    public function make(Bot $bot, array $arguments): array
    {
        foreach (['dataset', 'text', 'filters', 'sorts', 'limit'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $arguments)) {
                throw new AiException('The search arguments are incomplete.');
            }
        }

        $datasetSlug = $arguments['dataset'] ?? null;

        if (! is_string($datasetSlug)) {
            throw new AiException('The search dataset is invalid.');
        }

        $dataset = $bot->datasets()
            ->wherePivot('is_enabled', true)
            ->where('datasets.team_id', $bot->team_id)
            ->where('datasets.slug', $datasetSlug)
            ->first();

        if (! $dataset instanceof Dataset) {
            throw new AiException('The requested dataset is not available to this bot.');
        }

        $filters = [];

        if (! is_array($arguments['filters']) || ! is_array($arguments['sorts'])) {
            throw new AiException('The search filters or sorts are invalid.');
        }

        foreach ($arguments['filters'] as $filter) {
            if (! is_array($filter) || ! is_string($filter['field'] ?? null) || ! is_string($filter['operator'] ?? null)) {
                throw new AiException('The search filters are invalid.');
            }

            $operator = SearchOperator::tryFrom($filter['operator']);

            if (! $operator instanceof SearchOperator || ! array_key_exists('value', $filter)) {
                throw new AiException('The search filters are invalid.');
            }

            if (! is_scalar($filter['value']) && $filter['value'] !== null) {
                throw new AiException('The search filter value is invalid.');
            }

            $filters[] = new SearchFilter($filter['field'], $operator, $filter['value']);
        }

        $sorts = [];

        foreach ($arguments['sorts'] as $sort) {
            if (! is_array($sort) || ! is_string($sort['field'] ?? null) || ! is_string($sort['direction'] ?? null)) {
                throw new AiException('The search sorts are invalid.');
            }

            $direction = SearchSortDirection::tryFrom($sort['direction']);

            if (! $direction instanceof SearchSortDirection) {
                throw new AiException('The search sorts are invalid.');
            }

            $sorts[] = new SearchSort($sort['field'], $direction);
        }

        $limit = $arguments['limit'] ?? null;
        $maxResults = min(max(1, (int) config('openai.max_results', 10)), 100);

        if (! is_int($limit) || $limit < 1) {
            throw new AiException('The search result limit is invalid.');
        }

        $limit = min($limit, $maxResults);

        $text = $arguments['text'] ?? null;

        if (! is_string($text) && $text !== null) {
            throw new AiException('The search text is invalid.');
        }

        if (is_string($text) && mb_strlen($text) > 1000) {
            throw new AiException('The search text is too long.');
        }

        return [
            'dataset' => $dataset,
            'query' => new SearchQuery(
                datasetId: $dataset->id,
                text: $text,
                filters: $filters,
                sorts: $sorts,
                limit: $limit,
            ),
        ];
    }
}
