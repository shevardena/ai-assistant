<?php

namespace App\Services\Api\LiveRead;

final class YearRangeParser
{
    /**
     * @return array{from: int, to: int}|null
     */
    public function parse(mixed $value): ?array
    {
        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            $year = (int) $value;

            return $this->isFullYear($year) ? ['from' => $year, 'to' => $year] : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        if (preg_match('/(?<![\p{L}\p{N}])(\d{2}|(?:19|20)\d{2})\s*[-–\/]\s*(\d{2}|(?:19|20)\d{2})(?![\p{L}\p{N}])/u', $text, $match) !== 1) {
            if (preg_match('/(?<![\p{L}\p{N}])(?:MY\s*)?((?:19|20)\d{2})(?![\p{L}\p{N}])/iu', $text, $match) !== 1) {
                return null;
            }

            $year = (int) $match[1];

            return ['from' => $year, 'to' => $year];
        }

        $from = $this->rangeYear((string) $match[1]);
        $to = $this->rangeYear((string) $match[2]);

        if ($from === null || $to === null || $from > $to) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, mixed>>  $fields
     */
    public function matches(int $requestedYear, array $record, array $fields): bool
    {
        foreach ($this->ranges($record, $fields) as $range) {
            if ($requestedYear >= $range['from'] && $requestedYear <= $range['to']) {
                return true;
            }
        }

        foreach ($this->textValues($record, $fields) as $value) {
            $range = $this->parse($value);

            if ($range !== null && $requestedYear >= $range['from'] && $requestedYear <= $range['to']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, mixed>>  $fields
     * @return list<array{from: int, to: int}>
     */
    public function ranges(array $record, array $fields): array
    {
        $ranges = $this->structuredRanges($record, $fields);

        foreach ($this->textValues($record, $fields) as $value) {
            $range = $this->parse($value);

            if ($range !== null) {
                $ranges[] = $range;
            }
        }

        return $ranges;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, mixed>>  $fields
     * @return list<array{from: int, to: int}>
     */
    private function structuredRanges(array $record, array $fields): array
    {
        $ranges = [];
        $singleFields = ['year'];
        $fromFields = ['year_from', 'model_year_start'];
        $toFields = ['year_to', 'model_year_end'];

        foreach ($singleFields as $field) {
            if (! $this->configuredField($field, $fields) || ! array_key_exists($field, $record)) {
                continue;
            }

            $year = $this->fullYear($record[$field]);
            if ($year !== null) {
                $ranges[] = ['from' => $year, 'to' => $year];
            }
        }

        foreach ($fromFields as $fromField) {
            if (! $this->configuredField($fromField, $fields) || ! array_key_exists($fromField, $record)) {
                continue;
            }

            $from = $this->fullYear($record[$fromField]);
            $to = null;
            foreach ($toFields as $toField) {
                if ($this->configuredField($toField, $fields) && array_key_exists($toField, $record)) {
                    $to = $this->fullYear($record[$toField]);

                    break;
                }
            }

            if ($from !== null) {
                $ranges[] = ['from' => $from, 'to' => $to ?? $from];
            }
        }

        return array_values(array_filter($ranges, static fn (array $range): bool => $range['from'] <= $range['to']));
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, mixed>>  $fields
     * @return list<string>
     */
    private function textValues(array $record, array $fields): array
    {
        $values = [];

        foreach ($fields as $name => $definition) {
            if (($definition['searchable'] ?? false) !== true && ($definition['displayable'] ?? false) !== true) {
                continue;
            }

            $value = $record[$name] ?? null;
            if (is_scalar($value)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function configuredField(string $name, array $fields): bool
    {
        return array_key_exists($name, $fields);
    }

    private function fullYear(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit(trim($value)))) {
            return null;
        }

        $year = (int) $value;

        return $this->isFullYear($year) ? $year : null;
    }

    private function rangeYear(string $value): ?int
    {
        $year = (int) $value;

        if (mb_strlen($value) === 2) {
            $year += $year <= 30 ? 2000 : 1900;
        }

        return $this->isFullYear($year) ? $year : null;
    }

    private function isFullYear(int $year): bool
    {
        return $year >= 1900 && $year <= 2100;
    }
}
