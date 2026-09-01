<?php

namespace App\Services\Api\LiveRead;

use App\Models\ApiOperation;
use App\Services\Conversations\ConversationCycleLogger;
use InvalidArgumentException;
use Throwable;

final class LiveReadQueryPlanner
{
    public function __construct(private readonly ?ConversationCycleLogger $cycleLogger = null) {}

    /** @param array<string, mixed> $remoteArguments */
    public function plan(ApiOperation $operation, LiveReadQuery $query, array $remoteArguments = []): LiveReadQueryPlan
    {
        $fields = $this->fields($operation);
        $localFilters = [];
        $localSearchText = null;
        $remoteSearchParameter = null;
        $remoteSearchText = null;
        /** @var array<string, mixed> $remoteQuery */
        $remoteQuery = [];
        /** @var array<string, mixed> $remoteBody */
        $remoteBody = [];
        $remoteConstraints = [];
        $localConstraints = [];
        $unsupportedConstraints = [];
        $mappingValue = $operation->getAttribute('request_mapping');
        $mapping = is_array($mappingValue) ? $mappingValue : [];
        $liveMapping = is_array($mapping['live_query'] ?? null) ? $mapping['live_query'] : [];

        if ($query->searchText !== null && is_string($liveMapping['search_text'] ?? null)) {
            $remoteParameter = $liveMapping['search_text'];
            $argument = $this->requestArgumentForRemoteParameter($operation, $remoteParameter);
            if ($argument !== null) {
                $remoteArguments[$argument] = $query->searchText;
            } else {
                $remoteSearchParameter = $remoteParameter;
                $remoteSearchText = $query->searchText;
            }
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
                $this->addRemoteParameter($operation, $remoteArguments, $remoteQuery, $remoteBody, $remoteArgument, $filter['value'] ?? null);
            } else {
                $localFilters[] = ['field' => $field, 'operator' => $operator, 'value' => $filter['value'] ?? null];
            }
        }

        foreach ($query->constraints as $constraint) {
            $type = strtolower(trim((string) ($constraint['type'] ?? '')));
            $operator = (string) ($constraint['operator'] ?? 'eq');
            $value = $constraint['value'] ?? null;

            if ($type === '' || ! $this->isSupportedSemanticOperator($operator)) {
                $unsupportedConstraints[] = ['type' => $type, 'operator' => $operator, 'value' => $value];

                continue;
            }

            if ($type === 'year' && ! $this->validYearConstraintValue($value, $operator)) {
                throw new InvalidArgumentException('The year constraint value is invalid.');
            }

            $definition = $this->constraintMapping($liveMapping, $type, $operator);
            $remote = $this->remoteConstraint($definition, $operator, $value);

            if ($remote !== null) {
                foreach ($remote['values'] as $parameter => $remoteValue) {
                    $this->addRemoteParameter($operation, $remoteArguments, $remoteQuery, $remoteBody, $parameter, $remoteValue);
                }
                $remoteConstraints[] = [
                    'type' => $type,
                    'operator' => $operator,
                    'value' => $value,
                    'parameters' => $remote['values'],
                    'strategy' => $remote['strategy'],
                ];

                continue;
            }

            if ($this->supportsLocalConstraint($type, $fields)) {
                $localConstraints[] = ['type' => $type, 'operator' => $operator, 'value' => $value];
            } else {
                $unsupportedConstraints[] = ['type' => $type, 'operator' => $operator, 'value' => $value];
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

        $plan = new LiveReadQueryPlan(
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
            remoteSearchParameter: $remoteSearchParameter,
            remoteSearchText: $remoteSearchText,
            remoteQuery: $remoteQuery,
            remoteBody: $remoteBody,
            remoteConstraints: $remoteConstraints,
            localConstraints: $localConstraints,
            unsupportedConstraints: $unsupportedConstraints,
        );

        $this->cycleLogger?->event('live_read.plan.finalized', [
            'normalized_search_text' => $query->searchText,
            'remote_arguments' => $plan->remoteArguments,
            'remote_query' => $plan->remoteQuery,
            'remote_body' => $plan->remoteBody,
            'remote_constraints' => $plan->remoteConstraints,
            'remote_search_parameter' => $plan->remoteSearchParameter,
            'local_search_text' => $plan->localSearchText,
            'local_filter_count' => count($plan->localFilters),
            'local_constraints' => $plan->localConstraints,
            'unsupported_constraints' => $plan->unsupportedConstraints,
            'remote_sort_count' => count($plan->remoteSorts),
            'effective_result_limit' => $plan->effectiveResultLimit,
            'candidate_budget' => $plan->candidateBudget,
            'page_budget' => $plan->pageBudget,
        ]);
        $this->cycleLogger?->event('search_catalog.constraints.planned', [
            'remote_constraints' => $plan->remoteConstraints,
            'local_constraints' => $plan->localConstraints,
            'unsupported_constraints' => $plan->unsupportedConstraints,
        ]);

        return $plan;
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

    /** @param array<string, mixed> $liveMapping */
    private function constraintMapping(array $liveMapping, string $type, string $operator): mixed
    {
        $mappings = $liveMapping['constraints'] ?? $liveMapping['constraint_mappings'] ?? [];

        if (! is_array($mappings)) {
            return null;
        }

        return data_get($mappings, "{$type}.{$operator}") ?? data_get($mappings, $type);
    }

    /** @return array{strategy: string, values: array<string, mixed>}|null */
    private function remoteConstraint(mixed $definition, string $operator, mixed $value): ?array
    {
        if (is_string($definition) && $definition !== '') {
            return ['strategy' => 'single_parameter', 'values' => [$definition => $value]];
        }

        if (! is_array($definition)) {
            return null;
        }

        $strategy = (string) ($definition['strategy'] ?? 'single_parameter');
        if ($strategy === 'single_parameter') {
            $parameter = $definition['remote_parameter'] ?? $definition['parameter'] ?? $definition['remote'] ?? null;

            return is_string($parameter) && $parameter !== ''
                ? ['strategy' => $strategy, 'values' => [$parameter => $value]]
                : null;
        }

        if ($strategy !== 'range_parameters') {
            return null;
        }

        $from = $definition['remote_from_parameter'] ?? $definition['from_parameter'] ?? null;
        $to = $definition['remote_to_parameter'] ?? $definition['to_parameter'] ?? null;
        $values = [];

        if ($operator === 'eq') {
            if (is_string($from) && $from !== '') {
                $values[$from] = $value;
            }
            if (is_string($to) && $to !== '') {
                $values[$to] = $value;
            }
        } elseif (in_array($operator, ['gt', 'gte'], true) && is_string($from) && $from !== '') {
            $values[$from] = $value;
        } elseif (in_array($operator, ['lt', 'lte'], true) && is_string($to) && $to !== '') {
            $values[$to] = $value;
        } elseif ($operator === 'between' && is_array($value) && count($value) === 2) {
            if (is_string($from) && $from !== '') {
                $values[$from] = $value[0];
            }
            if (is_string($to) && $to !== '') {
                $values[$to] = $value[1];
            }
        }

        return $values === [] ? null : ['strategy' => $strategy, 'values' => $values];
    }

    /**
     * @param  array<string, mixed>  $remoteArguments
     * @param  array<string, mixed>  $remoteQuery
     * @param  array<string, mixed>  $remoteBody
     */
    private function addRemoteParameter(
        ApiOperation $operation,
        array &$remoteArguments,
        array &$remoteQuery,
        array &$remoteBody,
        string $parameter,
        mixed $value,
    ): void {
        $argument = $this->requestArgumentForRemoteParameter($operation, $parameter);
        if ($argument !== null) {
            $remoteArguments[$argument] = $value;

            return;
        }

        $mappingValue = $operation->getAttribute('request_mapping');
        $mapping = is_array($mappingValue) ? $mappingValue : [];
        foreach (['query', 'body'] as $section) {
            $sectionMapping = $mapping[$section] ?? [];
            if (! is_array($sectionMapping) || ! in_array($parameter, $sectionMapping, true)) {
                continue;
            }

            if ($section === 'query') {
                $remoteQuery[$parameter] = $value;
            } else {
                $remoteBody[$parameter] = $value;
            }

            return;
        }

        $remoteQuery[$parameter] = $value;
    }

    private function isSupportedSemanticOperator(string $operator): bool
    {
        return in_array($operator, ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between'], true);
    }

    private function validYearConstraintValue(mixed $value, string $operator): bool
    {
        $values = $operator === 'between' ? (array) $value : [$value];

        foreach ($values as $year) {
            if (! is_int($year) && ! (is_string($year) && ctype_digit(trim($year)))) {
                return false;
            }

            if ((int) $year < 1900 || (int) $year > 2100) {
                return false;
            }
        }

        return $operator !== 'between' || count($values) === 2 && (int) $values[0] <= (int) $values[1];
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function supportsLocalConstraint(string $type, array $fields): bool
    {
        return in_array($type, ['year', 'brand', 'category', 'product_type'], true)
            && array_filter($fields, static fn (array $field): bool => ($field['searchable'] ?? false) === true || ($field['displayable'] ?? false) === true) !== [];
    }
}
