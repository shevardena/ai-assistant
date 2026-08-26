<?php

namespace App\Services\Onboarding;

final readonly class TemplateWorkflowRecommendation
{
    public function __construct(
        public string $key,
        public string $titleKey,
        public string $descriptionKey,
    ) {}

    /**
     * @return array{key: string, titleKey: string, descriptionKey: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'titleKey' => $this->titleKey,
            'descriptionKey' => $this->descriptionKey,
        ];
    }
}
