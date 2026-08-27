<?php

namespace App\Services\Api\LiveRead;

final readonly class ResultCountIntent
{
    public function __construct(
        public string $mode = 'default',
        public ?int $minimum = null,
        public ?int $maximum = null,
    ) {}

    /** @param array<string, mixed>|null $value */
    public static function from(mixed $value, ?int $legacyLimit = null): self
    {
        if (! is_array($value)) {
            return $legacyLimit !== null
                ? new self('exact', max(1, $legacyLimit), max(1, $legacyLimit))
                : new self;
        }

        $mode = (string) ($value['mode'] ?? 'default');
        $count = isset($value['value']) && is_numeric($value['value']) ? max(1, (int) $value['value']) : null;
        $minimum = isset($value['minimum']) && is_numeric($value['minimum']) ? max(1, (int) $value['minimum']) : $count;
        $maximum = isset($value['maximum']) && is_numeric($value['maximum']) ? max(1, (int) $value['maximum']) : $count;

        return match ($mode) {
            'exact' => new self('exact', $count, $count),
            'minimum' => new self('minimum', $minimum, null),
            'maximum' => new self('maximum', null, $maximum),
            'range' => new self('range', $minimum, $maximum),
            'all' => new self('all'),
            default => new self,
        };
    }
}
