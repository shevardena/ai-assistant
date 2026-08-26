<?php

namespace App\Services\Search\Engines;

use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Services\Search\Contracts\SearchEngine;
use App\Services\Search\Data\SearchFilter;
use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Data\SearchResult;
use App\Services\Search\Data\SearchSort;
use App\Services\Search\Enums\SearchOperator;
use App\Services\Search\Enums\SearchSortDirection;
use App\Services\Search\Exceptions\InvalidSearchCriteriaException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PostgresSearchEngine implements SearchEngine
{
    private const BOOLEAN_VALUE_EXPRESSION = "CASE WHEN jsonb_extract_path_text(payload, ?) IN ('true', 'false') THEN jsonb_extract_path_text(payload, ?)::boolean ELSE NULL END";

    private const DECIMAL_VALUE_EXPRESSION = "CASE WHEN jsonb_extract_path_text(payload, ?) ~ '^-?([0-9]+([.][0-9]*)?|[.][0-9]+)$' THEN jsonb_extract_path_text(payload, ?)::numeric ELSE NULL END";

    private const INTEGER_VALUE_EXPRESSION = "CASE WHEN jsonb_extract_path_text(payload, ?) ~ '^-?[0-9]+$' THEN jsonb_extract_path_text(payload, ?)::bigint ELSE NULL END";

    private const TEXT_VALUE_EXPRESSION = 'jsonb_extract_path_text(payload, ?)';

    public function search(SearchQuery $query): SearchResult
    {
        $fields = $this->fieldsForQuery($query);
        $records = DatasetRecord::query()
            ->active()
            ->where('dataset_id', $query->datasetId);

        if ($query->text !== null && $query->text !== '') {
            $records->whereRaw('searchable_text ILIKE ?', ['%'.$this->escapeLikePattern($query->text).'%']);
        }

        foreach ($query->filters as $filter) {
            $field = $fields->get($filter->field);

            if (! $field instanceof DatasetField) {
                throw new InvalidSearchCriteriaException('Search filter field is invalid.');
            }

            $this->applyFilter($records, $filter, $field);
        }

        $total = (clone $records)->count();

        foreach ($query->sorts as $sort) {
            $field = $fields->get($sort->field);

            if (! $field instanceof DatasetField) {
                throw new InvalidSearchCriteriaException('Search sort field is invalid.');
            }

            $this->applySort($records, $sort, $field);
        }

        if ($query->sorts === []) {
            $records->latest('id');
        } else {
            $records->orderBy('id');
        }

        /** @var Collection<int, DatasetRecord> $resultRecords */
        $resultRecords = $records->limit($query->limit)->get();

        return new SearchResult(
            records: array_values($resultRecords->all()),
            total: $total,
        );
    }

    /**
     * @return Collection<string, DatasetField>
     */
    private function fieldsForQuery(SearchQuery $query): Collection
    {
        $keys = [];

        foreach ($query->filters as $filter) {
            $keys[] = $filter->field;
        }

        foreach ($query->sorts as $sort) {
            $keys[] = $sort->field;
        }

        if ($keys === []) {
            return new Collection;
        }

        return DatasetField::query()
            ->where('dataset_id', $query->datasetId)
            ->whereIn('key', array_values(array_unique($keys)))
            ->get()
            ->keyBy('key');
    }

    /**
     * @param  Builder<DatasetRecord>  $records
     */
    private function applyFilter(Builder $records, SearchFilter $filter, DatasetField $field): void
    {
        $valueExpression = $this->valueExpression($field);

        if ($filter->operator === SearchOperator::Contains) {
            if (! in_array($field->data_type, ['string', 'url'], true)) {
                throw new InvalidSearchCriteriaException(
                    "Operator [contains] is not supported for field [{$field->key}].",
                );
            }

            $records->whereRaw(
                "{$valueExpression['sql']} ILIKE ?",
                [...$valueExpression['bindings'], '%'.$this->escapeLikePattern((string) $filter->value).'%'],
            );

            return;
        }

        $operator = $this->comparisonOperator($filter->operator);

        $bindings = $valueExpression['bindings'];

        if ($filter->operator === SearchOperator::NotEqual) {
            $records->whereRaw(
                $this->notEqualSql($valueExpression['sql'], $operator),
                [...$bindings, ...$bindings, $filter->value],
            );

            return;
        }

        $records->whereRaw(
            $this->comparisonSql($valueExpression['sql'], $operator),
            [...$bindings, $filter->value],
        );
    }

    /**
     * @param  Builder<DatasetRecord>  $records
     */
    private function applySort(Builder $records, SearchSort $sort, DatasetField $field): void
    {
        $valueExpression = $this->valueExpression($field);
        $direction = match ($sort->direction) {
            SearchSortDirection::Ascending => 'ASC',
            SearchSortDirection::Descending => 'DESC',
        };

        $records->orderByRaw(
            $this->sortSql($valueExpression['sql'], $direction),
            $valueExpression['bindings'],
        );
    }

    /**
     * @return literal-string
     */
    private function comparisonOperator(SearchOperator $operator): string
    {
        return match ($operator) {
            SearchOperator::Equal => '=',
            SearchOperator::NotEqual => '<>',
            SearchOperator::GreaterThan => '>',
            SearchOperator::GreaterThanOrEqual => '>=',
            SearchOperator::LessThan => '<',
            SearchOperator::LessThanOrEqual => '<=',
            SearchOperator::Contains => throw new InvalidSearchCriteriaException('Search operator is invalid.'),
        };
    }

    /**
     * @param  literal-string  $expression
     * @param  literal-string  $operator
     * @return literal-string
     */
    private function comparisonSql(string $expression, string $operator): string
    {
        return "({$expression}) {$operator} ?";
    }

    /**
     * @param  literal-string  $expression
     * @param  literal-string  $operator
     * @return literal-string
     */
    private function notEqualSql(string $expression, string $operator): string
    {
        return "({$expression}) IS NOT NULL AND ({$expression}) {$operator} ?";
    }

    /**
     * @param  literal-string  $expression
     * @param  literal-string  $direction
     * @return literal-string
     */
    private function sortSql(string $expression, string $direction): string
    {
        return "({$expression}) {$direction} NULLS LAST";
    }

    /**
     * @return array{sql: literal-string, bindings: list<string>}
     */
    private function valueExpression(DatasetField $field): array
    {
        return match ($field->data_type) {
            'integer' => [
                'sql' => self::INTEGER_VALUE_EXPRESSION,
                'bindings' => [$field->key, $field->key],
            ],
            'decimal' => [
                'sql' => self::DECIMAL_VALUE_EXPRESSION,
                'bindings' => [$field->key, $field->key],
            ],
            'boolean' => [
                'sql' => self::BOOLEAN_VALUE_EXPRESSION,
                'bindings' => [$field->key, $field->key],
            ],
            'string', 'url', 'date', 'datetime' => [
                'sql' => self::TEXT_VALUE_EXPRESSION,
                'bindings' => [$field->key],
            ],
            default => throw new InvalidSearchCriteriaException(
                "Search is not supported for DatasetField type [{$field->data_type}].",
            ),
        };
    }

    private function escapeLikePattern(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
