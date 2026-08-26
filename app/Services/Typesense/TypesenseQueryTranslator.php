<?php

namespace App\Services\Typesense;

use App\Models\DatasetField;
use App\Services\Search\Data\SearchFilter;
use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Data\SearchSort;
use App\Services\Search\Enums\SearchOperator;
use App\Services\Search\Enums\SearchSortDirection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class TypesenseQueryTranslator
{
    /**
     * @param  Collection<string, DatasetField>  $fields
     * @return array<string, string|int>
     */
    public function translate(SearchQuery $query, Collection $fields): array
    {
        $searchableFields = $fields
            ->filter(fn (DatasetField $field): bool => $field->is_searchable
                && in_array($field->data_type, ['string', 'url'], true))
            ->keys()
            ->all();

        $parameters = [
            'q' => $query->text ?? '*',
            'query_by' => $searchableFields === [] ? 'searchable_text' : implode(',', $searchableFields),
            'per_page' => $query->limit,
        ];

        if ($query->filters !== []) {
            $parameters['filter_by'] = collect($query->filters)
                ->map(fn (SearchFilter $filter): string => $this->filter($filter, $fields->get($filter->field)))
                ->implode(' && ');
        }

        if ($query->sorts !== []) {
            $parameters['sort_by'] = collect($query->sorts)
                ->map(fn (SearchSort $sort): string => $sort->field.':'.($sort->direction === SearchSortDirection::Ascending ? 'asc' : 'desc'))
                ->implode(',');
        }

        return $parameters;
    }

    private function filter(SearchFilter $filter, ?DatasetField $field): string
    {
        if (! $field instanceof DatasetField) {
            throw new \InvalidArgumentException('Search filter field is invalid.');
        }

        $operator = match ($filter->operator) {
            SearchOperator::Equal => ':=',
            SearchOperator::NotEqual => ':!=',
            SearchOperator::GreaterThan => ':>',
            SearchOperator::GreaterThanOrEqual => ':>=',
            SearchOperator::LessThan => ':<',
            SearchOperator::LessThanOrEqual => ':<=',
            SearchOperator::Contains => ':',
        };

        return $field->key.$operator.$this->value($field, $filter->value);
    }

    private function value(DatasetField $field, mixed $value): string
    {
        $value = match ($field->data_type) {
            'boolean' => $value ? 'true' : 'false',
            'date' => (string) CarbonImmutable::parse((string) $value, 'UTC')->startOfDay()->getTimestamp(),
            'datetime' => (string) CarbonImmutable::parse((string) $value, 'UTC')->utc()->getTimestamp(),
            default => (string) $value,
        };

        if (in_array($field->data_type, ['integer', 'decimal', 'boolean', 'date', 'datetime'], true)) {
            return $value;
        }

        return '`'.str_replace('`', '\\`', $value).'`';
    }
}
