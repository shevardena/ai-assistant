<?php

namespace App\Services\Ai\Mappers;

use App\Enums\PriceSemanticRole;
use App\Models\Bot;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Services\Ai\AiException;
use App\Services\Search\Data\SearchFilter;
use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Data\SearchSort;
use App\Services\Search\Enums\SearchOperator;
use App\Services\Search\Enums\SearchSortDirection;
use Illuminate\Database\Eloquent\Collection;

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
        $fields = $dataset->fields()->get()->keyBy('key');

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

            $field = $this->resolveField($fields, $filter['field']);
            $value = $this->filterValue($filter, $operator);

            if (! is_scalar($value) && ! is_array($value) && $value !== null) {
                throw new AiException('The search filter value is invalid.');
            }

            if ($operator === SearchOperator::Between) {
                $filters[] = new SearchFilter($field->key, SearchOperator::GreaterThanOrEqual, $value[0]);
                $filters[] = new SearchFilter($field->key, SearchOperator::LessThanOrEqual, $value[1]);
            } else {
                $filters[] = new SearchFilter($field->key, $operator, $value);
            }
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

            $sorts[] = new SearchSort($this->resolveField($fields, $sort['field'])->key, $direction);
        }

        $limit = $arguments['candidate_limit'] ?? $arguments['limit'] ?? null;
        $maxResults = array_key_exists('candidate_limit', $arguments)
            ? 100
            : min(max(1, (int) config('openai.max_results', 10)), 100);

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

    /** @param Collection<int|string, DatasetField> $fields */
    private function resolveField(Collection $fields, string $requested): DatasetField
    {
        $exact = $fields->get($requested);
        if ($exact instanceof DatasetField) {
            return $exact;
        }

        $role = PriceSemanticRole::normalize($requested);
        if (! $role instanceof PriceSemanticRole) {
            throw new AiException("The search field [{$requested}] is not available.");
        }

        $matches = $fields->filter(
            fn (DatasetField $field): bool => PriceSemanticRole::normalize($field->semantic_type, $field->key) === $role,
        )->values();

        if ($matches->isEmpty() && $role === PriceSemanticRole::CurrentPrice) {
            $matches = $fields->filter(
                fn (DatasetField $field): bool => PriceSemanticRole::normalize($field->semantic_type, $field->key) === PriceSemanticRole::RegularPrice,
            )->values();
        }

        if ($matches->count() !== 1) {
            throw new AiException("The semantic search field [{$requested}] is ambiguous or unavailable.");
        }

        return $matches->first();
    }

    /** @param array<string, mixed> $filter */
    private function filterValue(array $filter, SearchOperator $operator): mixed
    {
        if ($operator !== SearchOperator::Between) {
            return $filter['value'];
        }

        $value = $filter['value'];
        if (is_array($value) && count($value) === 2) {
            return array_values($value);
        }

        if (array_key_exists('minimum', $filter) && array_key_exists('maximum', $filter)
            && is_scalar($filter['minimum']) && is_scalar($filter['maximum'])) {
            return [$filter['minimum'], $filter['maximum']];
        }

        throw new AiException('The between filter requires minimum and maximum values.');
    }
}
