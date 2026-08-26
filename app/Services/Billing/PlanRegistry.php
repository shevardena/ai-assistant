<?php

namespace App\Services\Billing;

use App\Data\PlanDefinition;
use App\Enums\PlanFeature;
use App\Enums\PlanLimit;

final class PlanRegistry
{
    /**
     * These are internal placeholder limits until commercial pricing is finalized.
     * They are deliberately high enough for development and small production pilots.
     *
     * @return list<PlanDefinition>
     */
    public function all(): array
    {
        return [
            $this->free(),
            $this->starter(),
            $this->pro(),
            $this->business(),
            $this->legacy(),
        ];
    }

    /**
     * @return list<PlanDefinition>
     */
    public function publicPlans(): array
    {
        return array_values(array_filter($this->all(), fn (PlanDefinition $plan): bool => $plan->public));
    }

    public function find(string $key): ?PlanDefinition
    {
        foreach ($this->all() as $plan) {
            if ($plan->key === $key) {
                return $plan;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toClientArray(PlanDefinition $plan): array
    {
        $stripePriceId = config('billing.plans.'.$plan->key.'.stripe_price_id');

        return array_merge($plan->toArray(), [
            'stripe_configured' => is_string($stripePriceId) && $stripePriceId !== '',
            'display_price' => config('billing.plans.'.$plan->key.'.display_price'),
            'currency' => config('billing.currency'),
        ]);
    }

    public function legacy(): PlanDefinition
    {
        return new PlanDefinition(
            key: 'legacy',
            name: 'Legacy',
            description: 'Existing Team access preserved while billing is introduced.',
            features: PlanFeature::cases(),
            limits: $this->unlimitedLimits(),
            public: false,
        );
    }

    private function free(): PlanDefinition
    {
        return new PlanDefinition(
            key: 'free',
            name: 'Free',
            description: 'A practical starting point for small assistants.',
            features: [PlanFeature::BusinessTemplates, PlanFeature::Notifications],
            limits: $this->limits(2, 3, 250, 100),
        );
    }

    private function starter(): PlanDefinition
    {
        return new PlanDefinition(
            key: 'starter',
            name: 'Starter',
            description: 'More room for a growing customer-facing assistant.',
            features: [
                PlanFeature::Analytics,
                PlanFeature::HumanHandoff,
                PlanFeature::BusinessTemplates,
                PlanFeature::Notifications,
                PlanFeature::VoiceInput,
            ],
            limits: $this->limits(5, 10, 2000, 500),
        );
    }

    private function pro(): PlanDefinition
    {
        return new PlanDefinition(
            key: 'pro',
            name: 'Pro',
            description: 'Advanced operations for teams running multiple assistants.',
            features: [
                PlanFeature::Analytics,
                PlanFeature::HumanHandoff,
                PlanFeature::Workflows,
                PlanFeature::BotTesting,
                PlanFeature::AdvancedHealth,
                PlanFeature::BusinessTemplates,
                PlanFeature::Notifications,
                PlanFeature::VoiceInput,
            ],
            limits: $this->limits(10, 25, 10000, 2000),
        );
    }

    private function business(): PlanDefinition
    {
        return new PlanDefinition(
            key: 'business',
            name: 'Business',
            description: 'Broad access for established customer-support operations.',
            features: PlanFeature::cases(),
            limits: $this->limits(null, null, null, null),
        );
    }

    /**
     * @return array<string, array{value: int|null, warning_threshold: float, enforcement: string}>
     */
    private function limits(?int $bots, ?int $members, ?int $conversations, ?int $actions): array
    {
        return [
            PlanLimit::Bots->value => $this->limit($bots),
            PlanLimit::TeamMembers->value => $this->limit($members),
            PlanLimit::MonthlyConversations->value => $this->limit($conversations),
            PlanLimit::MonthlyActions->value => $this->limit($actions),
        ];
    }

    /**
     * @return array{value: int|null, warning_threshold: float, enforcement: string}
     */
    private function limit(?int $value): array
    {
        return [
            'value' => $value,
            'warning_threshold' => 0.8,
            'enforcement' => 'hard',
        ];
    }

    /**
     * @return array<string, array{value: int|null, warning_threshold: float, enforcement: string}>
     */
    private function unlimitedLimits(): array
    {
        return $this->limits(null, null, null, null);
    }
}
