<?php

namespace App\Services\Api\LiveRead;

use App\Services\Imports\SourcePathResolver;
use Illuminate\Support\Str;

final class LiveReadRecordMatcher
{
    public function __construct(private readonly SourcePathResolver $paths) {}

    /** @param array<string, mixed> $record @param list<array<string, mixed>> $filters */
    public function matches(array $record, array $filters, array $fields): bool
    {
        foreach ($filters as $filter) {
            $definition = $fields[(string) ($filter['field'] ?? '')] ?? null;
            if (! is_array($definition)) {
                return false;
            }

            $actual = $record[(string) $filter['field']] ?? null;
            if (! $this->matchesOne($actual, (string) $filter['operator'], $filter['value'] ?? null, (string) $definition['type'])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $record @param array<string, array<string, mixed>> $fields */
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

    /** @param list<array<string, mixed>> $sorts */
    public function sort(array $records, array $sorts): array
    {
        usort($records, function (array $left, array $right) use ($sorts): int {
            foreach ($sorts as $sort) {
                $comparison = $this->compare($left[$sort['field']] ?? null, $right[$sort['field']] ?? null, (string) $sort['type']);
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
}
