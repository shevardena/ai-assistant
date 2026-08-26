<?php

namespace App\Data;

use Illuminate\Support\Carbon;

readonly class BillingPeriod
{
    public function __construct(
        public Carbon $start,
        public Carbon $end,
    ) {}

    /**
     * @return array{start: string, end: string, label: string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toISOString(),
            'end' => $this->end->toISOString(),
            'label' => $this->start->translatedFormat('F Y'),
        ];
    }
}
