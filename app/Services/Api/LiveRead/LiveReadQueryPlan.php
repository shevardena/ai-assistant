<?php

namespace App\Services\Api\LiveRead;

final readonly class LiveReadQueryPlan
{
    /**
     * @param  array<string, mixed>  $remoteArguments
     * @param  list<array<string, mixed>>  $localFilters
     * @param  list<array<string, mixed>>  $localSorts
     * @param  list<array<string, mixed>>  $remoteSorts
     * @param  array<string, mixed>  $remoteQuery
     * @param  array<string, mixed>  $remoteBody
     * @param  list<array<string, mixed>>  $remoteConstraints
     * @param  list<array<string, mixed>>  $localConstraints
     * @param  list<array<string, mixed>>  $unsupportedConstraints
     */
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
        public ?string $remoteSearchParameter = null,
        public ?string $remoteSearchText = null,
        public array $remoteQuery = [],
        public array $remoteBody = [],
        public array $remoteConstraints = [],
        public array $localConstraints = [],
        public array $unsupportedConstraints = [],
    ) {}
}
