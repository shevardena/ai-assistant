<?php

namespace App\Services\Ai;

class AiSearchResponse
{
    /**
     * @param  list<array<string, mixed>>  $searches
     * @param  array<string, mixed>|null  $usage
     * @param  list<array<string, mixed>>  $cardSources
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array{tool: string, outcome: string}>  $toolOutcomes
     * @param  list<string>  $actionProposals
     */
    public function __construct(
        public readonly string $answer,
        public readonly int $toolCallsCount,
        public readonly array $searches,
        public readonly ?array $usage,
        public readonly array $cardSources = [],
        public readonly array $blocks = [],
        public readonly array $toolOutcomes = [],
        public readonly array $actionProposals = [],
    ) {}
}
