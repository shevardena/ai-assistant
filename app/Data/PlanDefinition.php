<?php

namespace App\Data;

use App\Enums\PlanFeature;
use App\Enums\PlanLimit;

readonly class PlanDefinition
{
    /**
     * @param  list<PlanFeature>  $features
     * @param  array<string, array{value: int|null, warning_threshold: float, enforcement: string}>  $limits
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public array $features,
        public array $limits,
        public bool $public = true,
    ) {}

    public function hasFeature(PlanFeature $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    /**
     * @return array{value: int|null, warning_threshold: float, enforcement: string}
     */
    public function limit(PlanLimit $limit): array
    {
        return $this->limits[$limit->value] ?? [
            'value' => null,
            'warning_threshold' => 0.8,
            'enforcement' => 'soft',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'public' => $this->public,
            'features' => collect($this->features)
                ->mapWithKeys(fn (PlanFeature $feature): array => [$feature->value => $feature->label()])
                ->all(),
            'limits' => $this->limits,
        ];
    }
}
