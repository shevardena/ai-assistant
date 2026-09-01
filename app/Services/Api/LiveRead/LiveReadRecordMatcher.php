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

            $actual = $this->paths->get($record, (string) ($filter['field'] ?? ''));
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
     * @return list<array<string, mixed>>
     */
    public function sort(array $records, array $sorts): array
    {
        usort($records, function (array $left, array $right) use ($sorts): int {
            foreach ($sorts as $sort) {
                $comparison = $this->compare(
                    $this->paths->get($left, (string) ($sort['field'] ?? '')),
                    $this->paths->get($right, (string) ($sort['field'] ?? '')),
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
                && $this->compare($actual, $range[0], $type) >= 0
                && $this->compare($actual, $range[1], $type) <= 0;
        }
        if ($actual === null) {
            return false;
        }

        if ($type === 'string') {
            $left = mb_strtolower((string) $actual);
            $right = mb_strtolower((string) $expected);

            return match ($operator) {
                'eq' => $left === $right,
                'neq' => $left !== $right,
                'contains' => Str::contains($left, $right),
                'starts_with' => Str::startsWith($left, $right),
                'ends_with' => Str::endsWith($left, $right),
                default => false,
            };
        }

        $comparison = $this->compare($actual, $expected, $type);

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
        if (in_array($type, ['integer', 'decimal'], true)) {
            return ((float) $left) <=> ((float) $right);
        }

        if (in_array($type, ['date', 'datetime'], true)) {
            return strtotime((string) $left) <=> strtotime((string) $right);
        }

        return mb_strtolower((string) $left) <=> mb_strtolower((string) $right);
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
