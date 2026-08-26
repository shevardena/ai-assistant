<?php

namespace App\Services\Onboarding;

use App\Enums\TemplateRequirementImportance;

final readonly class TemplateChannelRecommendation
{
    public function __construct(
        public string $key,
        public TemplateRequirementImportance $importance,
        public string $titleKey,
        public string $descriptionKey,
    ) {}

    /**
     * @return array{key: string, importance: string, titleKey: string, descriptionKey: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'importance' => $this->importance->value,
            'titleKey' => $this->titleKey,
            'descriptionKey' => $this->descriptionKey,
        ];
    }
}
