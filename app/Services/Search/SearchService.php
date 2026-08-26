<?php

namespace App\Services\Search;

use App\Models\DatasetField;
use App\Models\Team;
use App\Services\Imports\DatasetValueNormalizer;
use App\Services\Search\Contracts\SearchEngine;
use App\Services\Search\Data\SearchFilter;
use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Data\SearchResult;
use App\Services\Search\Data\SearchSort;
use App\Services\Search\Enums\SearchOperator;
use App\Services\Search\Exceptions\InvalidSearchCriteriaException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Throwable;

class SearchService
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 100;

    public function __construct(
        private readonly SearchEngine $searchEngine,
        private readonly DatasetValueNormalizer $valueNormalizer,
    ) {}

    /**
     * Search one Dataset resolved through a trusted current Team context.
     */
    public function search(Team $team, SearchQuery $query): SearchResult
    {
        $dataset = $team->datasets()
            ->whereKey($query->datasetId)
            ->firstOrFail();
        $fields = $dataset->fields()->get()->keyBy('key');

        $this->validateLimit($query->limit);

        $filters = $this->normalizeFilters($query, $fields);
        $sorts = $this->validateSorts($query, $fields);
        $text = $query->text === null ? null : Str::squish($query->text);

        return $this->searchEngine->search(new SearchQuery(
            datasetId: $dataset->id,
            text: $text === '' ? null : $text,
            filters: $filters,
            sorts: $sorts,
            limit: $query->limit,
        ));
    }

    /**
     * @param  Collection<int|string, DatasetField>  $fields
     * @return list<SearchFilter>
     */
    private function normalizeFilters(SearchQuery $query, Collection $fields): array
    {
        $filters = [];

        foreach ($query->filters as $filter) {
            $field = $fields->get($filter->field);

            if (! $field instanceof DatasetField) {
                throw new InvalidSearchCriteriaException("Unknown search field [{$filter->field}].");
            }

            if (! $field->is_filterable) {
                throw new InvalidSearchCriteriaException("Field [{$filter->field}] is not filterable.");
            }

            $this->validateOperator($field, $filter->operator);

            try {
                $value = $this->valueNormalizer->normalize($field, $filter->value);
            } catch (Throwable $exception) {
                throw new InvalidSearchCriteriaException(
                    "Invalid value for search field [{$filter->field}].",
                    previous: $exception,
                );
            }

            $filters[] = new SearchFilter(
                field: $filter->field,
                operator: $filter->operator,
                value: $value,
            );
        }

        return $filters;
    }

    /**
     * @param  Collection<int|string, DatasetField>  $fields
     * @return list<SearchSort>
     */
    private function validateSorts(SearchQuery $query, Collection $fields): array
    {
        $sorts = [];

        foreach ($query->sorts as $sort) {
            $field = $fields->get($sort->field);

            if (! $field instanceof DatasetField) {
                throw new InvalidSearchCriteriaException("Unknown sort field [{$sort->field}].");
            }

            if (! $field->is_sortable) {
                throw new InvalidSearchCriteriaException("Field [{$sort->field}] is not sortable.");
            }

            $sorts[] = $sort;
        }

        return $sorts;
    }

    private function validateOperator(DatasetField $field, SearchOperator $operator): void
    {
        $supportedOperators = match ($field->data_type) {
            'string', 'url' => [
                SearchOperator::Equal,
                SearchOperator::NotEqual,
                SearchOperator::Contains,
            ],
            'integer', 'decimal', 'date', 'datetime' => [
                SearchOperator::Equal,
                SearchOperator::NotEqual,
                SearchOperator::GreaterThan,
                SearchOperator::GreaterThanOrEqual,
                SearchOperator::LessThan,
                SearchOperator::LessThanOrEqual,
            ],
            'boolean' => [
                SearchOperator::Equal,
                SearchOperator::NotEqual,
            ],
            default => [],
        };

        $allowedOperators = array_filter((array) $field->allowed_operators);

        if ($allowedOperators !== []) {
            $supportedOperators = array_filter(
                $supportedOperators,
                fn (SearchOperator $supportedOperator): bool => in_array(
                    $supportedOperator->value,
                    $allowedOperators,
                    true,
                ),
            );
        }

        if (! in_array($operator, $supportedOperators, true)) {
            throw new InvalidSearchCriteriaException(
                "Operator [{$operator->value}] is not supported for field [{$field->key}].",
            );
        }
    }

    private function validateLimit(int $limit): void
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidSearchCriteriaException(
                'Search limit must be between 1 and '.self::MAX_LIMIT.'.',
            );
        }
    }
}
