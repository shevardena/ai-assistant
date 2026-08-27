<?php

namespace App\Services\Api\LiveRead;

final readonly class LiveReadQueryPlan
{
    /** @param array<string, mixed> $remoteArguments @param list<array<string, mixed>> $localFilters @param list<array<string, mixed>> $localSorts @param list<array<string, mixed>> $remoteSorts */
    public function __construct(
        public ?string $localSearchText,
        public array $remoteArguments,
        public array $localFilters,
        public array $localSorts,
        public array $remoteSorts,
        public int $requestedMinimum,
        public int $effectiveResultLimit,
        public int $candidateBudget,
        public int $pageBudget,
        public bool $requiresCompleteOrdering,
    ) {}
}
