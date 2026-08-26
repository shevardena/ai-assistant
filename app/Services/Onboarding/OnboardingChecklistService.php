<?php

namespace App\Services\Onboarding;

use App\Models\Bot;

final class OnboardingChecklistService
{
    public function __construct(private readonly BusinessTemplateSetupService $setup) {}

    /**
     * Keep the original service entry point while setup readiness lives in the
     * V2 architecture service.
     *
     * @return array<string, mixed>
     */
    public function forBot(Bot $bot, BusinessTemplateDefinition $template): array
    {
        return $this->setup->forBot($bot, $template);
    }
}
