<?php

namespace App\Data;

use App\Enums\SubscriptionStatus;

readonly class TeamBillingSummary
{
    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $usage
     * @param  array<string, bool>  $features
     */
    public function __construct(
        public array $plan,
        public SubscriptionStatus $status,
        public BillingPeriod $period,
        public array $usage,
        public array $features,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan,
            'status' => $this->status->value,
            'period' => $this->period->toArray(),
            'usage' => $this->usage,
            'features' => $this->features,
        ];
    }
}
