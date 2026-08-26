<?php

namespace App\Services\Improvements;

use DateTimeInterface;

final readonly class ImprovementOpportunity
{
    /**
     * @param  array{id: int, name: string, slug: string}|null  $bot
     * @param  list<array{label: string, value: string}>  $evidence
     * @param  array{label: string, url: string}  $destination
     */
    public function __construct(
        public string $type,
        public string $category,
        public string $priority,
        public string $title,
        public string $description,
        public string $recommendation,
        public ?array $bot,
        public array $evidence,
        public array $destination,
        public ?DateTimeInterface $lastSeenAt,
        public int $sortRank,
        public int $sortCount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'category' => $this->category,
            'priority' => $this->priority,
            'title' => $this->title,
            'description' => $this->description,
            'recommendation' => $this->recommendation,
            'bot' => $this->bot,
            'evidence' => $this->evidence,
            'destination' => $this->destination,
            'lastSeenAt' => $this->lastSeenAt?->format(DATE_ATOM),
        ];
    }
}
