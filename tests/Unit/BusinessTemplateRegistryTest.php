<?php

use App\Enums\TemplateDataMode;
use App\Enums\TemplateRequirementImportance;
use App\Enums\TemplateRequirementType;
use App\Enums\TemplateSetupAction;
use App\Enums\TemplateSupportStatus;
use App\Services\Onboarding\BusinessTemplateRegistry;

test('business template registry contains unique first-party definitions', function (): void {
    $templates = (new BusinessTemplateRegistry)->all();
    $keys = array_map(static fn ($template): string => $template->key, $templates);

    expect($keys)->toBe([
        'ecommerce',
        'car_dealership',
        'real_estate',
        'hotel',
        'clinic',
        'restaurant',
        'saas_support',
    ])
        ->and(array_unique($keys))->toHaveCount(count($keys))
        ->and((new BusinessTemplateRegistry)->get('ecommerce')->recommendedDatasets)->not->toBeEmpty()
        ->and(array_unique(array_map(
            static fn ($template): string => $template->key,
            $templates,
        )))->toHaveCount(7);
});

test('every template requirement is typed and data requirements declare a mode', function (): void {
    foreach ((new BusinessTemplateRegistry)->all() as $template) {
        expect($template->version)->toBe(2);

        foreach ($template->requirements as $requirement) {
            expect($requirement->type)->toBeInstanceOf(TemplateRequirementType::class)
                ->and($requirement->importance)->toBeInstanceOf(TemplateRequirementImportance::class)
                ->and($requirement->setupAction)->toBeInstanceOf(TemplateSetupAction::class)
                ->and($requirement->supportStatus)->toBeInstanceOf(TemplateSupportStatus::class);

            if (in_array($requirement->type, [TemplateRequirementType::Catalog, TemplateRequirementType::Knowledge, TemplateRequirementType::LiveRead, TemplateRequirementType::LiveWrite], true)) {
                expect($requirement->dataMode)->toBeInstanceOf(TemplateDataMode::class);
            }
        }
    }
});

test('ecommerce separates synced catalog work from live and write integrations', function (): void {
    $requirements = collect((new BusinessTemplateRegistry)->get('ecommerce')->requirements)->keyBy('key');

    expect($requirements['products']->type)->toBe(TemplateRequirementType::Catalog)
        ->and($requirements['products']->importance)->toBe(TemplateRequirementImportance::Required)
        ->and($requirements['products']->dataMode)->toBe(TemplateDataMode::Hybrid)
        ->and($requirements['orders']->type)->toBe(TemplateRequirementType::LiveRead)
        ->and($requirements['orders']->dataMode)->toBe(TemplateDataMode::Live)
        ->and($requirements['cart']->type)->toBe(TemplateRequirementType::LiveWrite)
        ->and($requirements['cart']->supportStatus)->toBe(TemplateSupportStatus::RequiresApi)
        ->and($requirements['orders']->capabilities)->toContain('check_order_status')
        ->and($requirements['tracking']->capabilities)->toContain('track_order');
});

test('hotel and clinic templates do not claim unsupported clinical or reservation behavior', function (): void {
    $hotel = collect((new BusinessTemplateRegistry)->get('hotel')->requirements)->keyBy('key');
    $clinic = collect((new BusinessTemplateRegistry)->get('clinic')->requirements)->keyBy('key');

    expect($hotel['rooms']->type)->toBe(TemplateRequirementType::Catalog)
        ->and($hotel['availability']->supportStatus)->toBe(TemplateSupportStatus::FutureCustom)
        ->and($hotel['reservation']->supportStatus)->toBe(TemplateSupportStatus::FutureCustom)
        ->and($clinic['appointment_booking']->capabilities)->toContain('book_appointment')
        ->and(collect($clinic)->pluck('capabilities')->flatten()->contains('diagnose'))->toBeFalse();
});
