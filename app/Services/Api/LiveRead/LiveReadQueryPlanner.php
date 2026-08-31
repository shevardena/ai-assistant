<?php

namespace App\Services\Api\LiveRead;

use App\Models\ApiOperation;
use InvalidArgumentException;
use Throwable;

final class LiveReadQueryPlanner
{
    /** @param array<string, mixed> $remoteArguments */
    public function plan(ApiOperation $operation, LiveReadQuery $query, array $remoteArguments = []): LiveReadQueryPlan
    {
        $fields = $this->fields($operation);
        $localFilters = [];
        $localSearchText = null;
        $mappingValue = $operation->getAttribute('request_mapping');
        $mapping = is_array($mappingValue) ? $mappingValue : [];
        $liveMapping = is_array($mapping['live_query'] ?? null) ? $mapping['live_query'] : [];

        if ($query->searchText !== null && is_string($liveMapping['search_text'] ?? null)) {
            $remoteParameter = $liveMapping['search_text'];
            $argument = $this->requestArgumentForRemoteParameter($operation, $remoteParameter);
            $remoteArguments[$argument ?? $remoteParameter] = $query->searchText;
        } elseif ($query->searchText !== null) {
            $localSearchText = $query->searchText;
        }

        foreach ($query->filters as $filter) {
            $field = (string) ($filter['field'] ?? '');
            $definition = $fields[$field] ?? null;
            if (! is_array($definition) || ($definition['queryable'] ?? false) !== true || ($definition['filterable'] ?? false) !== true) {
                throw new InvalidArgumentException("The live field [{$field}] cannot be filtered.");
            }

            $operator = (string) ($filter['operator'] ?? '');
            $this->assertOperator($definition['type'], $operator);
            $this->assertValue($definition['type'], $operator, $filter['value'] ?? null);

            $remoteArgument = data_get($liveMapping, "filters.{$field}.{$operator}");
            if (is_string($remoteArgument) && $remoteArgument !== '') {
                $remoteArguments[$remoteArgument] = $filter['value'] ?? null;
            } else {
                $localFilters[] = ['field' => $field, 'operator' => $operator, 'value' => $filter['value'] ?? null];
            }
        }

        $localSorts = [];
        $remoteSorts = [];
        foreach ($query->sorts as $sort) {
            $field = (string) ($sort['field'] ?? '');
            $definition = $fields[$field] ?? null;
            if (! is_array($definition) || ($definition['queryable'] ?? false) !== true || ($definition['sortable'] ?? false) !== true) {
                throw new InvalidArgumentException("The live field [{$field}] cannot be sorted.");
            }

            $direction = strtolower((string) ($sort['direction'] ?? 'asc'));
            if (! in_array($direction, ['asc', 'desc'], true)) {
                throw new InvalidArgumentException('The live sort direction is invalid.');
            }

            $remote = data_get($liveMapping, "sort.{$field}.{$direction}") ?? $definition['remote_sort'] ?? null;
            if ($remote !== null) {
                $remoteSorts[] = ['field' => $field, 'direction' => $direction, 'remote' => $remote];
            } else {
                $localSorts[] = ['field' => $field, 'direction' => $direction, 'type' => $definition['type']];
            }
        }

        $default = max(1, (int) $this->setting('live-read.default_results', 5));
        $maxResults = max(1, (int) $this->setting('live-read.max_results', 20));
        $count = $query->resultCount;
        $requestedMinimum = $count->minimum ?? ($count->mode === 'all' ? $default : $default);
        $requestedMaximum = $count->maximum ?? ($count->mode === 'minimum' ? max($default, $requestedMinimum) : $requestedMinimum);
        $effectiveLimit = min($maxResults, max(1, $requestedMaximum));

        return new LiveReadQueryPlan(
            localSearchText: $localSearchText,
            remoteArguments: $remoteArguments,
            localFilters: $localFilters,
            localSorts: $localSorts,
            remoteSorts: $remoteSorts,
            requestedMinimum: min($effectiveLimit, max(1, $requestedMinimum)),
            effectiveResultLimit: $effectiveLimit,
            candidateBudget: max(1, (int) $this->setting('live-read.max_candidates', 500)),
            pageBudget: max(1, (int) $this->setting('live-read.max_pages', 10)),
            requiresCompleteOrdering: $localSorts !== [],
        );
    }

    /** @return array<string, array<string, mixed>> */
    public function fields(ApiOperation $operation): array
    {
        $mappingValue = $operation->getAttribute('response_mapping');
        $mapping = is_array($mappingValue) ? $mappingValue : [];
        $collection = $mapping['collection'] ?? null;
        $source = is_array($collection)
            ? ($collection['fields'] ?? [])
            : ($mapping['output'] ?? $mapping['fields'] ?? []);
        $source = is_array($source) ? $source : [];
        $fields = [];

        foreach ((array) $source as $name => $definition) {
            if (! is_string($name) || is_array($definition) && ! is_string($definition['path'] ?? null)) {
                continue;
            }
            $metadata = is_array($definition) ? $definition : [];
            $path = is_string($definition) ? $definition : (string) $definition['path'];
            $type = $this->type($metadata['type'] ?? $metadata['data_type'] ?? null, $name);
            $fields[$name] = [
                'path' => $path,
                'type' => $type,
                'queryable' => (bool) ($metadata['queryable'] ?? true),
                'filterable' => (bool) ($metadata['filterable'] ?? true),
                'sortable' => (bool) ($metadata['sortable'] ?? in_array($type, ['string', 'integer', 'decimal', 'date', 'datetime'], true)),
                'searchable' => (bool) ($metadata['searchable'] ?? $type === 'string'),
                'displayable' => (bool) ($metadata['displayable'] ?? true),
                'remote_sort' => $metadata['remote_sort'] ?? null,
            ];
        }

        return $fields;
    }

    private function type(mixed $type, string $name): string
    {
        if (is_string($type) && in_array($type, ['string', 'integer', 'decimal', 'boolean', 'date', 'datetime'], true)) {
            return $type;
        }

        return in_array(strtolower($name), ['id', 'count', 'quantity'], true) ? 'integer' : 'string';
    }

    private function assertOperator(string $type, string $operator): void
    {
        $operators = match ($type) {
            'string' => ['eq', 'neq', 'contains', 'starts_with', 'ends_with', 'in', 'is_null', 'is_not_null'],
            'integer', 'decimal', 'date', 'datetime' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'in', 'is_null', 'is_not_null'],
            'boolean' => ['eq', 'neq', 'is_null', 'is_not_null'],
            default => [],
        };

        if (! in_array($operator, $operators, true)) {
            throw new InvalidArgumentException("The operator [{$operator}] is incompatible with {$type} fields.");
        }
    }

    private function assertValue(string $type, string $operator, mixed $value): void
    {
        if (in_array($operator, ['is_null', 'is_not_null'], true)) {
            return;
        }
        if ($operator === 'between' && (! is_array($value) || count($value) !== 2)) {
            throw new InvalidArgumentException('The between operator requires two values.');
        }
        if ($operator === 'between' && count($value) === 2 && $value[0] > $value[1]) {
            throw new InvalidArgumentException('The between operator requires an ascending range.');
        }
        $values = $operator === 'in' || $operator === 'between' ? (array) $value : [$value];
        foreach ($values as $item) {
            if ($type === 'boolean' && ! is_bool($item)) {
                throw new InvalidArgumentException('Boolean filters require boolean values.');
            }
            if (in_array($type, ['integer', 'decimal'], true) && (! is_int($item) && ! is_float($item) && ! (is_string($item) && is_numeric($item)))) {
                throw new InvalidArgumentException('Numeric filters require numeric values.');
            }
        }
    }

    private function setting(string $key, mixed $default): mixed
    {
        try {
            return config($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    private function requestArgumentForRemoteParameter(ApiOperation $operation, string $remoteParameter): ?string
    {
        $mappingValue = $operation->getAttribute('request_mapping');
        $mapping = is_array($mappingValue) ? $mappingValue : [];

        foreach (['query', 'body'] as $section) {
            $sectionMapping = $mapping[$section] ?? null;

            if (! is_array($sectionMapping)) {
                continue;
            }

            foreach ($sectionMapping as $argument => $destination) {
                if (is_string($argument) && $destination === $remoteParameter) {
                    return $argument;
                }
            }
        }

        return null;
    }
}
