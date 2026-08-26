<?php

namespace App\Services\Onboarding;

use App\Enums\TemplateRequirementImportance;
use App\Enums\TemplateRequirementType;

final readonly class BusinessTemplateDefinition
{
    /**
     * @param  list<string>  $outcomeKeys
     * @param  list<TemplateRequirement>  $requirements
     * @param  list<TemplateWorkflowRecommendation>  $workflowRecommendations
     * @param  list<TemplateChannelRecommendation>  $channelRecommendations
     * @param  list<string>  $suggestedTestKeys
     * @param  list<array{key: string, labelKey: string, descriptionKey: string}>  $onboardingSteps
     */
    public function __construct(
        public string $key,
        public int $version,
        public string $nameKey,
        public string $descriptionKey,
        public string $bestForKey,
        public string $recommendedBotName,
        public array $outcomeKeys,
        public array $requirements,
        public array $workflowRecommendations,
        public array $channelRecommendations,
        public array $suggestedTestKeys,
        public array $onboardingSteps,
    ) {
        $this->recommendedDatasets = $this->legacyDatasets(false);
        $this->optionalDatasets = $this->legacyDatasets(true);
        $this->recommendedCapabilities = $this->legacyCapabilities(false);
        $this->optionalCapabilities = $this->legacyCapabilities(true);
        $this->recommendedWorkflows = array_map(
            static fn (TemplateWorkflowRecommendation $workflow): array => [
                'key' => $workflow->key,
                'name' => $workflow->titleKey,
                'description' => $workflow->descriptionKey,
            ],
            $this->workflowRecommendations,
        );
    }

    /** @var list<array<string, mixed>> */
    public array $recommendedDatasets;

    /** @var list<array<string, mixed>> */
    public array $optionalDatasets;

    /** @var list<string> */
    public array $recommendedCapabilities;

    /** @var list<string> */
    public array $optionalCapabilities;

    /** @var list<array{key: string, name: string, description: string}> */
    public array $recommendedWorkflows;

    /**
     * @return list<TemplateRequirement>
     */
    public function requirementsOfType(TemplateRequirementType $type): array
    {
        return array_values(array_filter(
            $this->requirements,
            static fn (TemplateRequirement $requirement): bool => $requirement->type === $type,
        ));
    }

    /**
     * @return list<TemplateRequirement>
     */
    public function requiredRequirements(): array
    {
        return array_values(array_filter(
            $this->requirements,
            static fn (TemplateRequirement $requirement): bool => $requirement->importance === TemplateRequirementImportance::Required,
        ));
    }

    /**
     * @return list<string>
     */
    public function capabilityKeys(): array
    {
        $keys = [];

        foreach ($this->requirements as $requirement) {
            if ($requirement->supportStatus->value === 'future_custom') {
                continue;
            }

            $keys = [...$keys, ...$requirement->capabilities];
        }

        return array_values(array_unique($keys));
    }

    /**
     * Compatibility view for consumers from the first template version.
     *
     * @return list<array<string, mixed>>
     */
    private function legacyDatasets(bool $optional): array
    {
        return array_values(array_map(
            static fn (TemplateRequirement $requirement): array => [
                'key' => $requirement->key,
                'name' => $requirement->titleKey,
                'description' => $requirement->descriptionKey,
                'suggestedFields' => $requirement->suggestedFields,
            ],
            array_filter(
                $this->requirements,
                static fn (TemplateRequirement $requirement): bool => in_array($requirement->type, [TemplateRequirementType::Catalog, TemplateRequirementType::Knowledge], true)
                    && ($optional
                        ? $requirement->importance === TemplateRequirementImportance::Optional
                        : $requirement->importance !== TemplateRequirementImportance::Optional),
            ),
        ));
    }

    /**
     * @return list<string>
     */
    private function legacyCapabilities(bool $optional): array
    {
        $keys = [];

        foreach ($this->requirements as $requirement) {
            if (($optional
                    ? $requirement->importance !== TemplateRequirementImportance::Optional
                    : $requirement->importance === TemplateRequirementImportance::Optional)
                || $requirement->supportStatus->value === 'future_custom') {
                continue;
            }

            $keys = [...$keys, ...$requirement->capabilities];
        }

        return array_values(array_unique($keys));
    }

    /**
     * Serialize trusted metadata for Inertia consumers.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'nameKey' => $this->nameKey,
            'descriptionKey' => $this->descriptionKey,
            'bestForKey' => $this->bestForKey,
            'recommendedBotName' => $this->recommendedBotName,
            'outcomeKeys' => $this->outcomeKeys,
            'requirements' => array_map(
                static fn (TemplateRequirement $requirement): array => $requirement->toArray(),
                $this->requirements,
            ),
            'workflowRecommendations' => array_map(
                static fn (TemplateWorkflowRecommendation $workflow): array => $workflow->toArray(),
                $this->workflowRecommendations,
            ),
            'channelRecommendations' => array_map(
                static fn (TemplateChannelRecommendation $channel): array => $channel->toArray(),
                $this->channelRecommendations,
            ),
            'suggestedTestKeys' => $this->suggestedTestKeys,
            'capabilityCount' => count($this->capabilityKeys()),
            'onboardingSteps' => $this->onboardingSteps,
            'recommendedDatasets' => $this->recommendedDatasets,
            'optionalDatasets' => $this->optionalDatasets,
            'recommendedCapabilities' => $this->recommendedCapabilities,
            'optionalCapabilities' => $this->optionalCapabilities,
            'recommendedWorkflows' => $this->recommendedWorkflows,
        ];
    }
}
