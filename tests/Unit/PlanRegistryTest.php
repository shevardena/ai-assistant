<?php

use App\Enums\PlanFeature;
use App\Enums\PlanLimit;
use App\Services\Billing\PlanRegistry;

test('the registry exposes stable public plans and an internal legacy plan', function () {
    $registry = new PlanRegistry;
    $plans = $registry->all();

    expect(array_column($plans, 'key'))->toBe(['free', 'starter', 'pro', 'business', 'legacy'])
        ->and(array_column($registry->publicPlans(), 'key'))->toBe(['free', 'starter', 'pro', 'business']);
});

test('plan definitions cover every supported feature and limit with valid semantics', function () {
    $registry = new PlanRegistry;

    foreach ($registry->all() as $plan) {
        expect($plan->key)->not->toBeEmpty()
            ->and(array_diff(
                array_map(fn (PlanFeature $feature): string => $feature->value, $plan->features),
                array_column(PlanFeature::cases(), 'value'),
            ))->toBeEmpty();

        foreach (PlanLimit::cases() as $limit) {
            $definition = $plan->limit($limit);

            expect($definition['value'] === null || $definition['value'] >= 0)->toBeTrue()
                ->and($definition['warning_threshold'])->toBeGreaterThan(0)
                ->and($definition['warning_threshold'])->toBeLessThanOrEqual(1)
                ->and($definition['enforcement'])->toBeIn(['soft', 'hard']);
        }
    }
});

test('legacy is unlimited and retains all current features', function () {
    $legacy = (new PlanRegistry)->legacy();

    foreach (PlanFeature::cases() as $feature) {
        expect($legacy->hasFeature($feature))->toBeTrue();
    }

    foreach (PlanLimit::cases() as $limit) {
        expect($legacy->limit($limit)['value'])->toBeNull();
    }
});
