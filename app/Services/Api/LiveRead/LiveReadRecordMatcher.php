<?php

namespace App\Services\Api\LiveRead;

use App\Services\Imports\SourcePathResolver;
use Illuminate\Support\Str;

final class LiveReadRecordMatcher
{
    public function __construct(
        private readonly SourcePathResolver $paths,
        private readonly ?YearRangeParser $years = null,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     * @param  list<array<string, mixed>>  $filters
     * @param  array<string, array<string, mixed>>  $fields
     */
    public function matches(array $record, array $filters, array $fields): bool
    {
        foreach ($filters as $filter) {
            $definition = $fields[(string) ($filter['field'] ?? '')] ?? null;
            if (! is_array($definition)) {
                return false;
            }

            $field = (string) ($filter['field'] ?? '');
            $actual = $this->valueForField($record, $field, $definition);
            if (! $this->matchesOne($actual, (string) $filter['operator'], $filter['value'] ?? null, (string) $definition['type'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, mixed>>  $fields
     */
    public function matchesSearchText(array $record, ?string $searchText, array $fields): bool
    {
        if ($searchText === null || trim($searchText) === '') {
            return true;
        }

        $needle = mb_strtolower(trim($searchText));

        foreach ($fields as $name => $definition) {
            if (($definition['searchable'] ?? false) !== true) {
                continue;
            }

            $value = $record[$name] ?? null;
            if ($value !== null && Str::contains(mb_strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<array<string, mixed>>  $constraints
     * @param  array<string, array<string, mixed>>  $fields
     */
    public function matchesConstraints(array $record, array $constraints, array $fields): bool
    {
        foreach ($constraints as $constraint) {
            $type = Str::lower((string) ($constraint['type'] ?? ''));
            $operator = (string) ($constraint['operator'] ?? 'eq');
            $value = $constraint['value'] ?? null;

            if ($type === 'year') {
                if (! $this->matchesYear($record, $fields, $operator, $value)) {
                    return false;
                }

                continue;
            }

            if (! in_array($type, ['brand', 'category', 'product_type'], true)
                || ! is_scalar($value)
                || ! $this->matchesSearchText($record, (string) $value, $fields)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<array<string, mixed>>  $sorts
     * @param  array<string, array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    public function sort(array $records, array $sorts, array $fields = []): array
    {
        usort($records, function (array $left, array $right) use ($sorts, $fields): int {
            foreach ($sorts as $sort) {
                $comparison = $this->compare(
                    $this->valueForSort($left, $sort, $fields),
                    $this->valueForSort($right, $sort, $fields),
                    (string) ($sort['type'] ?? 'string'),
                );
                if ($comparison !== 0) {
                    return ($sort['direction'] ?? 'asc') === 'desc' ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $records;
    }

    /** @param array<string, mixed> $sort */
    private function valueForSort(array $record, array $sort, array $fields): mixed
    {
        $field = (string) ($sort['field'] ?? '');
        $definition = $fields[$field] ?? [];

        return $this->valueForField($record, $field, is_array($definition) ? $definition : []);
    }

    /** @param array<string, mixed> $definition */
    private function valueForField(array $record, string $field, array $definition): mixed
    {
        if (is_array($definition['derived_from'] ?? null)) {
            $current = $this->paths->get($record, (string) ($definition['derived_from']['current_price'] ?? ''));
            $regular = $this->paths->get($record, (string) ($definition['derived_from']['regular_price'] ?? ''));
            $current = $this->normalizeTypedValue($current, 'decimal');
            $regular = $this->normalizeTypedValue($regular, 'decimal');

            return $regular === null || $regular <= 0 || $current === null
                ? null
                : (($regular - $current) / $regular) * 100;
        }

        return $this->paths->get($record, (string) ($definition['resolved_field'] ?? $field));
    }

    private function matchesOne(mixed $actual, string $operator, mixed $expected, string $type): bool
    {
        if ($operator === 'is_null') {
            return $actual === null;
        }
        if ($operator === 'is_not_null') {
            return $actual !== null;
        }
        if ($operator === 'in') {
            return in_array(true, array_map(fn (mixed $item): bool => $this->matchesOne($actual, 'eq', $item, $type), (array) $expected), true);
        }
        if ($operator === 'between') {
            $range = array_values((array) $expected);

            return count($range) === 2
                && $this->matchesOne($actual, 'gte', $range[0], $type)
                && $this->matchesOne($actual, 'lte', $range[1], $type);
        }
        if ($actual === null) {
            return false;
        }

        $normalizedActual = $this->normalizeTypedValue($actual, $type);
        $normalizedExpected = $this->normalizeTypedValue($expected, $type);
        if ($normalizedActual === null || $normalizedExpected === null) {
            return false;
        }

        if ($type === 'string') {
            $left = mb_strtolower($normalizedActual);
            $right = mb_strtolower($normalizedExpected);

            return match ($operator) {
                'eq' => $left === $right,
                'neq' => $left !== $right,
                'contains' => Str::contains($left, $right),
                'starts_with' => Str::startsWith($left, $right),
                'ends_with' => Str::endsWith($left, $right),
                default => false,
            };
        }

        $comparison = $this->compare($normalizedActual, $normalizedExpected, $type);

        return match ($operator) {
            'eq' => $comparison === 0,
            'neq' => $comparison !== 0,
            'gt' => $comparison > 0,
            'gte' => $comparison >= 0,
            'lt' => $comparison < 0,
            'lte' => $comparison <= 0,
            default => false,
        };
    }

    private function compare(mixed $left, mixed $right, string $type): int
    {
        $normalizedLeft = $this->normalizeTypedValue($left, $type);
        $normalizedRight = $this->normalizeTypedValue($right, $type);

        if ($normalizedLeft === null && $normalizedRight === null) {
            return 0;
        }
        if ($normalizedLeft === null) {
            return 1;
        }
        if ($normalizedRight === null) {
            return -1;
        }

        if (in_array($type, ['integer', 'decimal'], true)) {
            return $normalizedLeft <=> $normalizedRight;
        }

        if (in_array($type, ['date', 'datetime'], true)) {
            return $normalizedLeft <=> $normalizedRight;
        }

        if ($type === 'boolean') {
            return ((int) $normalizedLeft) <=> ((int) $normalizedRight);
        }

        return mb_strtolower($normalizedLeft) <=> mb_strtolower($normalizedRight);
    }

    private function normalizeTypedValue(mixed $value, string $type): int|float|bool|string|null
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        return match ($type) {
            'integer' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            'decimal' => is_numeric($value) && is_finite((float) $value) ? (float) $value : null,
            'boolean' => $this->normalizeBoolean($value),
            'date', 'datetime' => ($timestamp = strtotime((string) $value)) === false ? null : $timestamp,
            default => (string) $value,
        };
    }

    private function normalizeBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
        }

        if (in_array($value, [1, '1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($value, [0, '0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function matchesYear(array $record, array $fields, string $operator, mixed $value): bool
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit(trim($value)))) {
            return false;
        }

        $requested = (int) $value;
        if ($requested < 1900 || $requested > 2100) {
            return false;
        }

        foreach (($this->years ?? new YearRangeParser)->ranges($record, $fields) as $range) {
            $matches = match ($operator) {
                'eq' => $requested >= $range['from'] && $requested <= $range['to'],
                'gt' => $range['to'] > $requested,
                'gte' => $range['to'] >= $requested,
                'lt' => $range['from'] < $requested,
                'lte' => $range['from'] <= $requested,
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
