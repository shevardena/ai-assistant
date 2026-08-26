<?php

namespace App\Services\Onboarding;

use App\Enums\ChannelConnectionStatus;
use App\Enums\TemplateRequirementType;
use App\Enums\TemplateSetupAction;
use App\Enums\TemplateSupportStatus;
use App\Models\Bot;
use App\Models\Dataset;
use App\Services\Ai\BotCapabilityService;
use Illuminate\Support\Str;

final class BusinessTemplateSetupService
{
    public function __construct(private readonly BotCapabilityService $capabilities) {}

    /**
     * Resolve a safe, actionable setup plan from the Bot's current state.
     *
     * @return array<string, mixed>
     */
    public function forBot(Bot $bot, BusinessTemplateDefinition $template): array
    {
        $bot->loadMissing(['domains', 'cardTemplates', 'testScenarios']);
        $requirements = array_map(
            fn (TemplateRequirement $requirement): array => $this->requirement($bot, $requirement, $template->key),
            $template->requirements,
        );
        $required = array_values(array_filter($requirements, fn (array $requirement): bool => $requirement['importance'] === 'required'));
        $recommended = array_values(array_filter($requirements, fn (array $requirement): bool => $requirement['importance'] === 'recommended'));
        $optional = array_values(array_filter($requirements, fn (array $requirement): bool => $requirement['importance'] === 'optional'));
        $requiredReady = $required === [] || collect($required)->every(fn (array $requirement): bool => $requirement['status'] === 'ready');
        $configured = collect($requirements)->filter(fn (array $requirement): bool => $requirement['status'] === 'ready')->count();
        $steps = $this->steps($bot, $required, $template);
        $completedSteps = collect($steps)->where('completed', true)->count();

        return [
            'progress' => [
                'completed' => $configured,
                'total' => count($required),
                'percentage' => count($required) > 0 ? (int) round(($configured / count($required)) * 100) : 100,
                'requiredReady' => $requiredReady,
                'launchReady' => $requiredReady,
                'required' => count($required),
                'recommended' => count($recommended),
                'optional' => count($optional),
            ],
            'requirements' => $requirements,
            'groups' => $this->groups($requirements),
            'datasets' => $this->legacyDatasets($requirements),
            'capabilities' => $this->legacyCapabilities($bot, $template),
            'steps' => $steps,
            'workflows' => $this->workflows($bot, $requirements),
            'channels' => $this->channels($requirements),
            'suggestedTests' => $template->suggestedTestKeys,
            'legacyProgress' => [
                'completed' => $completedSteps,
                'total' => count($steps),
                'percentage' => count($steps) > 0 ? (int) round(($completedSteps / count($steps) * 100)) : 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requirement(Bot $bot, TemplateRequirement $definition, string $templateKey): array
    {
        $capabilityMap = $this->capabilityMap($bot);
        $status = $this->status($bot, $definition, $capabilityMap);
        $dataset = $status['dataset'] ?? null;
        $action = $this->action($bot, $definition, $dataset instanceof Dataset ? $dataset : null, $templateKey);
        $capabilities = array_map(
            fn (string $key): array => [
                'key' => $key,
                'labelKey' => 'templates.capabilities.'.$key,
                'status' => $capabilityMap[$key]['status'] ?? ($definition->supportStatus === TemplateSupportStatus::FutureCustom ? 'unavailable' : 'not_configured'),
            ],
            $definition->capabilities,
        );

        return [
            ...$definition->toArray(),
            'category' => $this->category($definition->type),
            'status' => $status['status'],
            'statusMessageKey' => $status['messageKey'],
            'statusReasonKey' => $status['reasonKey'],
            'dataset' => $dataset instanceof Dataset ? [
                'id' => $dataset->id,
                'name' => $dataset->name,
                'slug' => $dataset->slug,
                'status' => $dataset->status,
            ] : null,
            'capabilities' => $capabilities,
            'setup' => $action,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $capabilityMap
     * @return array{status: string, messageKey: string, reasonKey: string, dataset?: Dataset|null}
     */
    private function status(Bot $bot, TemplateRequirement $definition, array $capabilityMap): array
    {
        if ($definition->supportStatus === TemplateSupportStatus::FutureCustom) {
            return [
                'status' => 'unavailable',
                'messageKey' => 'templates.status.unavailable',
                'reasonKey' => 'templates.status.future_custom',
                'dataset' => null,
            ];
        }

        if ($definition->type === TemplateRequirementType::Channel) {
            $exists = $bot->channelConnections()
                ->where('channel', $definition->key)
                ->where('status', ChannelConnectionStatus::Active->value)
                ->exists();

            return [
                'status' => $exists ? 'ready' : 'not_configured',
                'messageKey' => $exists ? 'templates.status.ready' : 'templates.status.channel_not_configured',
                'reasonKey' => $exists ? 'templates.status.connected' : 'templates.status.channel_not_configured',
            ];
        }

        if ($definition->type === TemplateRequirementType::Workflow) {
            return [
                'status' => 'not_configured',
                'messageKey' => 'templates.status.workflow_not_configured',
                'reasonKey' => 'templates.status.workflow_not_configured',
            ];
        }

        if (in_array($definition->type, [TemplateRequirementType::Catalog, TemplateRequirementType::Knowledge], true)) {
            $dataset = $this->matchingDataset($bot, $definition);

            if (! $dataset instanceof Dataset) {
                return [
                    'status' => 'not_configured',
                    'messageKey' => 'templates.status.not_configured',
                    'reasonKey' => 'templates.status.dataset_not_connected',
                    'dataset' => null,
                ];
            }

            $capabilitiesReady = collect($definition->capabilities)->every(
                fn (string $key): bool => ($capabilityMap[$key]['status'] ?? null) === 'ready',
            );
            $ready = $dataset->status === 'ready' && $capabilitiesReady;

            return [
                'status' => $ready ? 'ready' : 'partially_configured',
                'messageKey' => $ready ? 'templates.status.ready' : 'templates.status.partially_configured',
                'reasonKey' => $dataset->status !== 'ready'
                    ? 'templates.status.dataset_needs_configuration'
                    : ($capabilitiesReady ? 'templates.status.ready' : 'templates.status.capability_not_ready'),
                'dataset' => $dataset,
            ];
        }

        if ($definition->capabilities === []) {
            return [
                'status' => 'not_configured',
                'messageKey' => 'templates.status.not_configured',
                'reasonKey' => 'templates.status.api_not_configured',
            ];
        }

        $capabilityStatuses = collect($definition->capabilities)
            ->map(fn (string $key): ?string => $capabilityMap[$key]['status'] ?? null);
        $ready = $capabilityStatuses->every(fn (?string $status): bool => $status === 'ready');
        $hasConfiguration = collect($definition->capabilities)->contains(
            fn (string $key): bool => in_array($capabilityMap[$key]['status'] ?? null, ['ready', 'disabled'], true)
                || (($capabilityMap[$key]['status'] ?? null) === 'needs_configuration'
                    && data_get($capabilityMap[$key] ?? [], 'details.operationName') !== null),
        );

        return [
            'status' => $ready ? 'ready' : ($hasConfiguration ? 'partially_configured' : 'not_configured'),
            'messageKey' => $ready ? 'templates.status.ready' : ($hasConfiguration ? 'templates.status.partially_configured' : 'templates.status.not_configured'),
            'reasonKey' => $ready ? 'templates.status.ready' : ($hasConfiguration ? 'templates.status.capability_not_ready' : 'templates.status.api_not_configured'),
        ];
    }

    /** @return array<string, mixed>|null */
    private function matchingDataset(Bot $bot, TemplateRequirement $definition): ?Dataset
    {
        $attachments = $bot->botDatasets()
            ->where('is_enabled', true)
            ->whereHas('dataset', fn ($query) => $query->where('team_id', $bot->team_id))
            ->with('dataset')
            ->get();

        return $attachments
            ->map(fn ($attachment): ?Dataset => $attachment->dataset)
            ->filter(fn (?Dataset $dataset): bool => $dataset instanceof Dataset)
            ->first(fn (Dataset $dataset): bool => $this->matchesDataset($dataset, $definition));
    }

    private function matchesDataset(Dataset $dataset, TemplateRequirement $definition): bool
    {
        $haystack = Str::lower($dataset->name.' '.$dataset->slug.' '.$dataset->entity_type);
        $terms = match ($definition->key) {
            'products' => ['product', 'catalog'],
            'vehicles' => ['vehicle', 'car'],
            'properties' => ['property', 'real estate'],
            'rooms' => ['room', 'hotel'],
            'services' => ['service'],
            'menu' => ['menu', 'food'],
            'policies', 'dealership_faq', 'real_estate_faq', 'clinic_faq', 'restaurant_faq', 'support_faq' => ['faq', 'knowledge', 'policy'],
            'help_center' => ['help', 'support'],
            'documentation' => ['documentation', 'docs'],
            'hotel_information' => ['hotel', 'information'],
            'locations', 'office_locations', 'clinic_locations', 'restaurant_locations' => ['location', 'office'],
            default => [$definition->key],
        };

        foreach ($terms as $term) {
            if (Str::contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array<string, mixed>> */
    private function capabilityMap(Bot $bot): array
    {
        $map = [];

        foreach ($this->capabilities->forBot($bot) as $group) {
            foreach ($group['capabilities'] as $capability) {
                $map[$capability['key']] = $capability;
            }
        }

        return $map;
    }

    /** @return array<string, mixed> */
    private function action(Bot $bot, TemplateRequirement $definition, ?Dataset $dataset, string $templateKey): array
    {
        $team = $bot->team->slug;
        $context = ['requirement' => $definition->key, 'capabilities' => $definition->capabilities];
        $url = match ($definition->setupAction) {
            TemplateSetupAction::CreateDataset => route('datasets.create', ['current_team' => $team]),
            TemplateSetupAction::ConnectDataSource => $dataset instanceof Dataset
                ? route('datasets.show', ['current_team' => $team, 'dataset' => $dataset])
                : route('data-sources.create', ['current_team' => $team]),
            TemplateSetupAction::ConfigureLiveApi,
            TemplateSetupAction::ConfigureWriteApi => route('data-sources.create', [
                'current_team' => $team,
                'template' => $templateKey,
                'requirement' => $definition->key,
                'capability' => $definition->capabilities[0] ?? null,
                'bot' => $bot->id,
            ]),
            TemplateSetupAction::ConfigureChannel => route('bots.channels.index', ['current_team' => $team, 'bot' => $bot]),
            TemplateSetupAction::OpenCapabilities => route('bots.capabilities.show', ['current_team' => $team, 'bot' => $bot]),
            TemplateSetupAction::OpenWorkflows => route('workflows.index', ['current_team' => $team]),
            TemplateSetupAction::RunBotTest => route('bots.tests.index', ['current_team' => $team, 'bot' => $bot]),
            TemplateSetupAction::None => null,
        };

        return [
            'type' => $definition->setupAction->value,
            'url' => $url,
            'labelKey' => $this->actionLabelKey($definition->setupAction, $dataset),
            'context' => $context,
        ];
    }

    private function actionLabelKey(TemplateSetupAction $action, ?Dataset $dataset): string
    {
        if ($action === TemplateSetupAction::ConnectDataSource && $dataset instanceof Dataset) {
            return 'templates.actions.configure_mapping';
        }

        return match ($action) {
            TemplateSetupAction::CreateDataset => 'templates.actions.create_dataset',
            TemplateSetupAction::ConnectDataSource => 'templates.actions.connect_data_source',
            TemplateSetupAction::ConfigureLiveApi => 'templates.actions.configure_live_api',
            TemplateSetupAction::ConfigureWriteApi => 'templates.actions.configure_write_api',
            TemplateSetupAction::ConfigureChannel => 'templates.actions.configure_channel',
            TemplateSetupAction::OpenCapabilities => 'templates.actions.open_capabilities',
            TemplateSetupAction::OpenWorkflows => 'templates.actions.open_workflows',
            TemplateSetupAction::RunBotTest => 'templates.actions.run_bot_test',
            TemplateSetupAction::None => 'templates.actions.not_available',
        };
    }

    /** @param list<array<string, mixed>> $requirements */
    private function groups(array $requirements): array
    {
        $groups = [];

        foreach (['data_knowledge', 'live_integrations', 'actions'] as $key) {
            $items = array_values(array_filter($requirements, static fn (array $requirement): bool => $requirement['category'] === $key));

            if ($items === []) {
                continue;
            }

            $groups[] = [
                'key' => $key,
                'titleKey' => 'templates.categories.'.$key,
                'requirements' => $items,
            ];
        }

        return $groups;
    }

    /** @param list<array<string, mixed>> $requirements */
    private function workflows(Bot $bot, array $requirements): array
    {
        return array_values(array_map(
            static fn (array $requirement): array => [
                'key' => $requirement['key'],
                'titleKey' => $requirement['titleKey'],
                'descriptionKey' => $requirement['descriptionKey'],
                'status' => $requirement['status'],
                'setup' => $requirement['setup'],
            ],
            array_filter($requirements, static fn (array $requirement): bool => $requirement['type'] === TemplateRequirementType::Workflow->value),
        ));
    }

    /** @param list<array<string, mixed>> $requirements */
    private function channels(array $requirements): array
    {
        return array_values(array_map(
            static fn (array $requirement): array => [
                'key' => $requirement['key'],
                'importance' => $requirement['importance'],
                'titleKey' => $requirement['titleKey'],
                'descriptionKey' => $requirement['descriptionKey'],
                'status' => $requirement['status'],
                'setup' => $requirement['setup'],
            ],
            array_filter($requirements, static fn (array $requirement): bool => $requirement['type'] === TemplateRequirementType::Channel->value),
        ));
    }

    /** @param list<array<string, mixed>> $required */
    private function steps(Bot $bot, array $required, BusinessTemplateDefinition $template): array
    {
        $dataReady = collect($required)
            ->filter(fn (array $requirement): bool => in_array($requirement['type'], [TemplateRequirementType::Catalog->value, TemplateRequirementType::Knowledge->value], true))
            ->every(fn (array $requirement): bool => $requirement['status'] === 'ready');
        $liveReady = collect($required)
            ->filter(fn (array $requirement): bool => in_array($requirement['type'], [TemplateRequirementType::LiveRead->value, TemplateRequirementType::LiveWrite->value], true))
            ->every(fn (array $requirement): bool => $requirement['status'] === 'ready');
        $appearance = $bot->getAttribute('appearance');
        $hasAppearance = is_array($appearance) && $appearance !== [];

        return array_map(function (array $step) use ($bot, $dataReady, $liveReady, $hasAppearance): array {
            $completed = match ($step['key']) {
                'data' => $dataReady,
                'capabilities' => $liveReady,
                'tests' => $bot->testScenarios->isNotEmpty(),
                'design' => $hasAppearance || $bot->cardTemplates->isNotEmpty(),
                'domain' => $bot->domains->where('is_active', true)->isNotEmpty(),
                'embed' => true,
                default => false,
            };

            return [
                ...$step,
                'completed' => $completed,
                'status' => $completed ? 'complete' : 'incomplete',
                'actionUrl' => $this->stepUrl($bot, $step['key']),
                'actionLabelKey' => $this->stepActionLabelKey($step['key']),
            ];
        }, $template->onboardingSteps);
    }

    private function stepUrl(Bot $bot, string $key): string
    {
        return match ($key) {
            'tests' => route('bots.tests.index', ['current_team' => $bot->team->slug, 'bot' => $bot]),
            'design' => route('bots.design.edit', ['current_team' => $bot->team->slug, 'bot' => $bot]),
            'domain', 'embed' => route('bots.show', ['current_team' => $bot->team->slug, 'bot' => $bot]),
            'capabilities' => route('bots.capabilities.show', ['current_team' => $bot->team->slug, 'bot' => $bot]),
            default => route('data-sources.create', ['current_team' => $bot->team->slug]),
        };
    }

    private function stepActionLabelKey(string $key): string
    {
        return match ($key) {
            'tests' => 'templates.actions.run_bot_test',
            'design' => 'templates.actions.open_design',
            'domain', 'embed' => 'templates.actions.open_bot',
            'capabilities' => 'templates.actions.open_capabilities',
            default => 'templates.actions.connect_data_source',
        };
    }

    private function category(TemplateRequirementType $type): string
    {
        return match ($type) {
            TemplateRequirementType::Knowledge, TemplateRequirementType::Catalog => 'data_knowledge',
            TemplateRequirementType::LiveRead => 'live_integrations',
            TemplateRequirementType::LiveWrite => 'actions',
            TemplateRequirementType::Workflow => 'automation',
            TemplateRequirementType::Channel => 'channels',
        };
    }

    /** @param list<array<string, mixed>> $requirements */
    private function legacyDatasets(array $requirements): array
    {
        return array_values(array_map(
            static fn (array $requirement): array => [
                'key' => $requirement['key'],
                'name' => $requirement['titleKey'],
                'description' => $requirement['descriptionKey'],
                'suggestedFields' => $requirement['suggestedFields'],
                'status' => $requirement['status'] === 'ready' ? 'ready' : ($requirement['status'] === 'not_configured' ? 'unavailable' : 'needs_configuration'),
                'statusMessage' => $requirement['statusMessageKey'],
                'dataset' => $requirement['dataset'],
                'actionUrl' => $requirement['setup']['url'],
                'actionLabel' => $requirement['setup']['labelKey'],
            ],
            array_filter($requirements, static fn (array $requirement): bool => in_array($requirement['type'], [TemplateRequirementType::Catalog->value, TemplateRequirementType::Knowledge->value], true)),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function legacyCapabilities(Bot $bot, BusinessTemplateDefinition $template): array
    {
        $map = $this->capabilityMap($bot);
        $requirements = collect($template->requirements)->keyBy('key');
        $keys = $template->capabilityKeys();

        return array_map(function (string $key) use ($map, $requirements): array {
            $requirement = $requirements->first(fn (TemplateRequirement $item): bool => in_array($key, $item->capabilities, true));
            $capability = $map[$key] ?? [];

            return [
                ...$capability,
                'key' => $key,
                'labelKey' => 'templates.capabilities.'.$key,
                'recommended' => $requirement instanceof TemplateRequirement
                    && $requirement->importance->value !== 'optional',
            ];
        }, $keys);
    }
}
