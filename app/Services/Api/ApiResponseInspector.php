<?php

namespace App\Services\Api;

use Illuminate\Support\Str;

class ApiResponseInspector
{
    private const MAX_PATHS = 100;

    /**
     * Inspect a bounded API response for record arrays and scalar fields.
     *
     * @param  array<mixed, mixed>  $response
     * @return array{recordArrays: list<array{path: string, count: int, sample: list<array<string, mixed>>}>, fields: list<array<string, mixed>>}
     */
    public function inspect(array $response, ?string $recordsPath = null): array
    {
        $recordArrays = [];
        $this->findRecordArrays($response, '', $recordArrays);

        $selected = null;

        if ($recordsPath !== null && $recordsPath !== '') {
            $selected = $this->get($response, $recordsPath);
        }

        if (! is_array($selected) || ! array_is_list($selected)) {
            $selected = $recordArrays[0]['sample'] ?? [];
        }

        $fields = [];

        if (is_array($selected)) {
            foreach (array_slice($selected, 0, 10) as $row) {
                if (is_array($row)) {
                    $this->flatten($row, '', $fields);
                }
            }
        }

        return [
            'recordArrays' => $recordArrays,
            'fields' => array_values(array_map(
                fn (array $values, string $path): array => [
                    'path' => $path,
                    'sample' => array_values(array_unique(array_map(
                        fn (mixed $value): string => is_scalar($value) || $value === null ? (string) $value : '[object]',
                        array_slice($values, 0, 5),
                    ))),
                    'type' => $this->type($values),
                ],
                $fields,
                array_keys($fields),
            )),
        ];
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @param  list<array{path: string, count: int, sample: list<array<string, mixed>>}>  $result
     */
    private function findRecordArrays(array $value, string $prefix, array &$result): void
    {
        if (count($result) >= self::MAX_PATHS) {
            return;
        }

        if (array_is_list($value) && $value !== [] && collect($value)->every(fn (mixed $item): bool => is_array($item))) {
            $result[] = [
                'path' => $prefix === '' ? 'root' : $prefix,
                'count' => count($value),
                'sample' => array_values(array_slice($value, 0, 3)),
            ];
        }

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                $this->findRecordArrays($child, $path, $result);
            }
        }
    }

    /**
     * @param  array<mixed, mixed>  $row
     * @param  array<string, list<mixed>>  $fields
     */
    private function flatten(array $row, string $prefix, array &$fields): void
    {
        foreach ($row as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && ! array_is_list($value)) {
                $this->flatten($value, $path, $fields);

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $fields[$path] ??= [];

                if (count($fields[$path]) < 5) {
                    $fields[$path][] = $value;
                }
            }
        }
    }

    /** @param list<mixed> $values */
    private function type(array $values): string
    {
        $values = array_values(array_filter($values, fn (mixed $value): bool => $value !== null && $value !== ''));

        if ($values === []) {
            return 'string';
        }

        if (collect($values)->every(fn (mixed $value): bool => is_bool($value))) {
            return 'boolean';
        }

        if (collect($values)->every(fn (mixed $value): bool => is_int($value))) {
            return 'integer';
        }

        if (collect($values)->every(fn (mixed $value): bool => is_numeric($value))) {
            return 'number';
        }

        return 'string';
    }

    private function get(array $value, string $path): mixed
    {
        $path = Str::startsWith($path, '$.') ? Str::after($path, '$.') : $path;

        if ($path === 'root') {
            return $value;
        }

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
