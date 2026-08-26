<?php

namespace App\Services\Onboarding;

use App\Enums\TemplateDataMode;
use App\Enums\TemplateRequirementImportance;
use App\Enums\TemplateRequirementType;
use App\Enums\TemplateSetupAction;
use App\Enums\TemplateSupportStatus;

final readonly class TemplateRequirement
{
    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $recommendedSourceTypes
     * @param  list<string>  $suggestedFields
     */
    public function __construct(
        public string $key,
        public TemplateRequirementType $type,
        public TemplateRequirementImportance $importance,
        public ?TemplateDataMode $dataMode,
        public string $titleKey,
        public string $descriptionKey,
        public string $whyKey,
        public array $capabilities,
        public array $recommendedSourceTypes,
        public TemplateSetupAction $setupAction,
        public TemplateSupportStatus $supportStatus,
        public array $suggestedFields = [],
        public ?string $refreshRecommendation = null,
        public ?string $guidanceKey = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'importance' => $this->importance->value,
            'dataMode' => $this->dataMode?->value,
            'titleKey' => $this->titleKey,
            'descriptionKey' => $this->descriptionKey,
            'whyKey' => $this->whyKey,
            'capabilities' => $this->capabilities,
            'recommendedSourceTypes' => $this->recommendedSourceTypes,
            'setupAction' => $this->setupAction->value,
            'supportStatus' => $this->supportStatus->value,
            'suggestedFields' => $this->suggestedFields,
            'refreshRecommendation' => $this->refreshRecommendation,
            'guidanceKey' => $this->guidanceKey,
        ];
    }
}
