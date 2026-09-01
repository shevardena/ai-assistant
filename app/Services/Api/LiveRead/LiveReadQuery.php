<?php

namespace App\Services\Api\LiveRead;

final readonly class LiveReadQuery
{
    /**
     * @param  list<array<string, mixed>>  $filters
     * @param  list<array<string, mixed>>  $sorts
     * @param  list<array<string, mixed>>  $constraints
     */
    public function __construct(
        public ?string $searchText,
        public array $filters,
        public array $sorts,
        public ResultCountIntent $resultCount,
        public array $constraints = [],
    ) {}

    /** @param array<string, mixed> $arguments */
    public static function fromArguments(array $arguments): self
    {
        return new self(
            is_string($arguments['search_text'] ?? $arguments['text'] ?? null)
                ? trim((string) ($arguments['search_text'] ?? $arguments['text'])) ?: null
                : null,
            array_values(array_filter((array) ($arguments['filters'] ?? []), 'is_array')),
            array_values(array_filter((array) ($arguments['sort'] ?? $arguments['sorts'] ?? []), 'is_array')),
            ResultCountIntent::from($arguments['result_count'] ?? null, isset($arguments['limit']) ? (int) $arguments['limit'] : null),
            self::normalizeConstraints($arguments['constraints'] ?? []),
        );
    }

    /**
     * Normalize the model-facing semantic constraint shape into the internal
     * planner shape while accepting the legacy internal `type` key.
     *
     * @return list<array{type: string, operator: string, value: mixed}>
     */
    public static function normalizeConstraints(mixed $constraints): array
    {
        $normalized = [];

        foreach (array_values(array_filter((array) $constraints, 'is_array')) as $constraint) {
            $type = trim((string) ($constraint['field'] ?? $constraint['type'] ?? ''));
            $operator = (string) ($constraint['operator'] ?? 'eq');
            $value = $constraint['value'] ?? null;

            if (strtolower($type) === 'year') {
                $value = self::normalizeYearValue($value);
            }

            $normalized[] = [
                'type' => $type,
                'operator' => $operator,
                'value' => $value,
            ];
        }

        return $normalized;
    }

    private static function normalizeYearValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::normalizeYearValue(...), $value);
        }

        return is_string($value) && ctype_digit(trim($value))
            ? (int) trim($value)
            : $value;
    }
}
