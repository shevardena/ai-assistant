<?php

namespace App\Services\Api\LiveRead;

final readonly class LiveReadQuery
{
    /** @param list<array<string, mixed>> $filters @param list<array<string, mixed>> $sorts */
    public function __construct(
        public ?string $searchText,
        public array $filters,
        public array $sorts,
        public ResultCountIntent $resultCount,
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
        );
    }
}
