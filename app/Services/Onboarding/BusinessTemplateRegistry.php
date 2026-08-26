<?php

namespace App\Services\Onboarding;

use App\Enums\TemplateDataMode;
use App\Enums\TemplateRequirementImportance;
use App\Enums\TemplateRequirementType;
use App\Enums\TemplateSetupAction;
use App\Enums\TemplateSupportStatus;
use Illuminate\Support\Arr;

final class BusinessTemplateRegistry
{
    /**
     * @return list<BusinessTemplateDefinition>
     */
    public function all(): array
    {
        return [
            $this->ecommerce(),
            $this->carDealership(),
            $this->realEstate(),
            $this->hotel(),
            $this->clinic(),
            $this->restaurant(),
            $this->saasSupport(),
        ];
    }

    public function find(string $key): ?BusinessTemplateDefinition
    {
        return Arr::first($this->all(), fn (BusinessTemplateDefinition $template): bool => $template->key === $key);
    }

    public function get(string $key): BusinessTemplateDefinition
    {
        $template = $this->find($key);

        if (! $template instanceof BusinessTemplateDefinition) {
            throw new \InvalidArgumentException("Unknown business template [$key].");
        }

        return $template;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(
            static fn (BusinessTemplateDefinition $template): string => $template->key,
            $this->all(),
        );
    }

    /** @return list<string> */
    public function workflowKeys(): array
    {
        $keys = [];

        foreach ($this->all() as $template) {
            foreach ($template->workflowRecommendations as $workflow) {
                $keys[] = $workflow->key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function ecommerce(): BusinessTemplateDefinition
    {
        return $this->template(
            key: 'ecommerce',
            nameKey: 'templates.ecommerce.name',
            descriptionKey: 'templates.ecommerce.description',
            bestForKey: 'templates.ecommerce.best_for',
            recommendedBotName: 'Store Assistant',
            outcomeKeys: ['product_discovery', 'product_details', 'recommendations', 'comparison', 'policy_answers', 'stock_checking', 'order_tracking', 'cart_actions', 'lead_capture'],
            requirements: [
                $this->catalog('products', 'templates.requirements.products', TemplateRequirementImportance::Required, TemplateDataMode::Hybrid, ['search_catalog', 'get_product_details', 'recommend_products', 'compare_products'], ['rest_api', 'graphql_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, TemplateSupportStatus::Supported, ['external_id', 'name', 'description', 'category', 'brand', 'price', 'url', 'image', 'sku'], 'six_hourly'),
                $this->knowledge('policies', 'templates.requirements.policies', TemplateRequirementImportance::Recommended, ['file', 'rest_api', 'graphql_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, ['question', 'answer'], 'daily'),
                $this->live('inventory', 'templates.requirements.inventory', TemplateRequirementType::LiveRead, TemplateRequirementImportance::Recommended, ['check_stock'], TemplateSetupAction::ConfigureLiveApi),
                $this->live('orders', 'templates.requirements.orders', TemplateRequirementType::LiveRead, TemplateRequirementImportance::Recommended, ['check_order_status'], TemplateSetupAction::ConfigureLiveApi),
                $this->live('tracking', 'templates.requirements.tracking', TemplateRequirementType::LiveRead, TemplateRequirementImportance::Recommended, ['track_order'], TemplateSetupAction::ConfigureLiveApi),
                $this->live('shipping', 'templates.requirements.shipping', TemplateRequirementType::LiveRead, TemplateRequirementImportance::Optional, ['get_shipping_info'], TemplateSetupAction::ConfigureLiveApi),
                $this->live('cart', 'templates.requirements.cart', TemplateRequirementType::LiveWrite, TemplateRequirementImportance::Optional, ['add_to_cart'], TemplateSetupAction::ConfigureWriteApi),
                $this->live('lead_capture', 'templates.requirements.lead_capture', TemplateRequirementType::LiveWrite, TemplateRequirementImportance::Optional, ['capture_lead'], TemplateSetupAction::ConfigureWriteApi),
                $this->live('support_ticket', 'templates.requirements.support_ticket', TemplateRequirementType::LiveWrite, TemplateRequirementImportance::Optional, ['create_support_ticket'], TemplateSetupAction::ConfigureWriteApi),
                $this->workflow('lead_notification', TemplateRequirementImportance::Recommended),
                $this->workflow('support_notification', TemplateRequirementImportance::Recommended),
                $this->workflow('handoff_notification', TemplateRequirementImportance::Optional),
                $this->channel('website', TemplateRequirementImportance::Recommended),
                $this->channel('whatsapp', TemplateRequirementImportance::Optional),
            ],
            suggestedTestKeys: ['templates.tests.ecommerce.product_search', 'templates.tests.ecommerce.policy', 'templates.tests.ecommerce.stock'],
        );
    }

    private function carDealership(): BusinessTemplateDefinition
    {
        return $this->template(
            key: 'car_dealership',
            nameKey: 'templates.car_dealership.name',
            descriptionKey: 'templates.car_dealership.description',
            bestForKey: 'templates.car_dealership.best_for',
            recommendedBotName: 'Vehicle Assistant',
            outcomeKeys: ['vehicle_discovery', 'vehicle_comparison', 'inventory_availability', 'test_drive_requests', 'lead_qualification', 'trade_in_inquiry', 'finance_faq', 'locations'],
            requirements: [
                $this->catalog('vehicles', 'templates.requirements.vehicles', TemplateRequirementImportance::Required, TemplateDataMode::Hybrid, ['search_catalog', 'get_product_details', 'recommend_products', 'compare_products'], ['rest_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, TemplateSupportStatus::Supported, ['external_id', 'make', 'model', 'year', 'trim', 'price', 'mileage', 'fuel_type', 'transmission', 'body_type', 'image', 'location', 'url'], 'hourly'),
                $this->live('vehicle_availability', 'templates.requirements.vehicle_availability', TemplateRequirementType::LiveRead, TemplateRequirementImportance::Recommended, ['check_stock'], TemplateSetupAction::ConfigureLiveApi),
                $this->knowledge('dealership_faq', 'templates.requirements.dealership_faq', TemplateRequirementImportance::Recommended, ['file', 'rest_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, ['question', 'answer'], 'daily'),
                $this->live('locations', 'templates.requirements.locations', TemplateRequirementType::LiveRead, TemplateRequirementImportance::Recommended, ['get_store_locations'], TemplateSetupAction::ConfigureLiveApi),
                $this->live('test_drive', 'templates.requirements.test_drive', TemplateRequirementType::LiveWrite, TemplateRequirementImportance::Recommended, ['book_appointment'], TemplateSetupAction::ConfigureWriteApi),
                $this->live('lead_capture', 'templates.requirements.lead_capture', TemplateRequirementType::LiveWrite, TemplateRequirementImportance::Recommended, ['capture_lead'], TemplateSetupAction::ConfigureWriteApi),
                $this->future('trade_in', 'templates.requirements.trade_in', TemplateRequirementImportance::Optional),
                $this->workflow('lead_notification', TemplateRequirementImportance::Recommended),
                $this->workflow('test_drive_notification', TemplateRequirementImportance::Recommended),
                $this->channel('website', TemplateRequirementImportance::Recommended),
                $this->channel('whatsapp', TemplateRequirementImportance::Optional),
            ],
            suggestedTestKeys: ['templates.tests.car_dealership.vehicle_search', 'templates.tests.car_dealership.test_drive'],
        );
    }

    private function realEstate(): BusinessTemplateDefinition
    {
        return $this->template(
            key: 'real_estate',
            nameKey: 'templates.real_estate.name',
            descriptionKey: 'templates.real_estate.description',
            bestForKey: 'templates.real_estate.best_for',
            recommendedBotName: 'Property Assistant',
            outcomeKeys: ['property_discovery', 'property_comparison', 'buyer_qualification', 'viewing_requests', 'office_information', 'lead_capture'],
            requirements: [
                $this->catalog('properties', 'templates.requirements.properties', TemplateRequirementImportance::Required, TemplateDataMode::Hybrid, ['search_catalog', 'get_product_details', 'recommend_products', 'compare_products'], ['rest_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, TemplateSupportStatus::Supported, ['external_id', 'title', 'property_type', 'sale_or_rent', 'price', 'bedrooms', 'bathrooms', 'area', 'location', 'description', 'amenities', 'image', 'url', 'status'], 'hourly'),
                $this->future('listing_status', 'templates.requirements.listing_status', TemplateRequirementImportance::Recommended),
                $this->knowledge('real_estate_faq', 'templates.requirements.real_estate_faq', TemplateRequirementImportance::Recommended, ['file', 'rest_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, ['question', 'answer'], 'daily'),
                $this->live('viewing_booking', 'templates.requirements.viewing_booking', TemplateRequirementType::LiveWrite, TemplateRequirementImportance::Recommended, ['book_appointment'], TemplateSetupAction::ConfigureWriteApi),
                $this->live('lead_qualification', 'templates.requirements.lead_qualification', TemplateRequirementType::LiveWrite, TemplateRequirementImportance::Recommended, ['capture_lead'], TemplateSetupAction::ConfigureWriteApi),
                $this->live('office_locations', 'templates.requirements.office_locations', TemplateRequirementType::LiveRead, TemplateRequirementImportance::Optional, ['get_store_locations'], TemplateSetupAction::ConfigureLiveApi),
                $this->workflow('lead_notification', TemplateRequirementImportance::Recommended),
                $this->workflow('viewing_notification', TemplateRequirementImportance::Recommended),
                $this->channel('website', TemplateRequirementImportance::Recommended),
                $this->channel('whatsapp', TemplateRequirementImportance::Optional),
            ],
            suggestedTestKeys: ['templates.tests.real_estate.property_search', 'templates.tests.real_estate.lead'],
        );
    }

    private function hotel(): BusinessTemplateDefinition
    {
        return $this->template(
            key: 'hotel',
            nameKey: 'templates.hotel.name',
            descriptionKey: 'templates.hotel.description',
            bestForKey: 'templates.hotel.best_for',
            recommendedBotName: 'Guest Assistant',
            outcomeKeys: ['room_discovery', 'hotel_information', 'policies', 'availability', 'rates', 'reservation_support'],
            requirements: [
                $this->catalog('rooms', 'templates.requirements.rooms', TemplateRequirementImportance::Required, TemplateDataMode::Hybrid, ['search_catalog', 'get_product_details'], ['rest_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, TemplateSupportStatus::Supported, ['room_type', 'description', 'capacity', 'bed_configuration', 'amenities', 'image', 'url'], 'daily'),
                $this->knowledge('hotel_information', 'templates.requirements.hotel_information', TemplateRequirementImportance::Required, ['file', 'rest_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, ['question', 'answer'], 'daily'),
                $this->future('availability', 'templates.requirements.availability', TemplateRequirementImportance::Recommended),
                $this->future('rates', 'templates.requirements.rates', TemplateRequirementImportance::Recommended),
                $this->future('reservation', 'templates.requirements.reservation', TemplateRequirementImportance::Optional),
                $this->future('reservation_lookup', 'templates.requirements.reservation_lookup', TemplateRequirementImportance::Optional),
                $this->workflow('reservation_notification', TemplateRequirementImportance::Optional),
                $this->channel('website', TemplateRequirementImportance::Recommended),
                $this->channel('whatsapp', TemplateRequirementImportance::Recommended),
            ],
            suggestedTestKeys: ['templates.tests.hotel.room_information', 'templates.tests.hotel.policies'],
        );
    }

    private function clinic(): BusinessTemplateDefinition
    {
        return $this->template(
            key: 'clinic',
            nameKey: 'templates.clinic.name',
            descriptionKey: 'templates.clinic.description',
            bestForKey: 'templates.clinic.best_for',
            recommendedBotName: 'Clinic Assistant',
            outcomeKeys: ['service_information', 'hours_locations', 'insurance_information', 'preparation_information', 'appointment_booking', 'support_escalation'],
            requirements: [
                $this->catalog('services', 'templates.requirements.services', TemplateRequirementImportance::Required, TemplateDataMode::Synced, ['search_catalog', 'get_product_details'], ['file', 'rest_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, TemplateSupportStatus::Supported, ['name', 'description', 'price', 'url'], 'daily'),
                $this->knowledge('clinic_faq', 'templates.requirements.clinic_faq', TemplateRequirementImportance::Required, ['file', 'rest_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, ['question', 'answer'], 'daily'),
                $this->live('clinic_locations', 'templates.requirements.clinic_locations', TemplateRequirementType::LiveRead, TemplateRequirementImportance::Recommended, ['get_store_locations'], TemplateSetupAction::ConfigureLiveApi),
                $this->future('appointment_slots', 'templates.requirements.appointment_slots', TemplateRequirementImportance::Recommended),
                $this->live('appointment_booking', 'templates.requirements.appointment_booking', TemplateRequirementType::LiveWrite, TemplateRequirementImportance::Recommended, ['book_appointment'], TemplateSetupAction::ConfigureWriteApi),
                $this->live('patient_intake', 'templates.requirements.patient_intake', TemplateRequirementType::LiveWrite, TemplateRequirementImportance::Optional, ['capture_lead'], TemplateSetupAction::ConfigureWriteApi),
                $this->workflow('appointment_notification', TemplateRequirementImportance::Recommended),
                $this->channel('website', TemplateRequirementImportance::Recommended),
                $this->channel('whatsapp', TemplateRequirementImportance::Optional),
            ],
            suggestedTestKeys: ['templates.tests.clinic.service_information', 'templates.tests.clinic.appointment'],
        );
    }

    private function restaurant(): BusinessTemplateDefinition
    {
        return $this->template(
            key: 'restaurant',
            nameKey: 'templates.restaurant.name',
            descriptionKey: 'templates.restaurant.description',
            bestForKey: 'templates.restaurant.best_for',
            recommendedBotName: 'Restaurant Assistant',
            outcomeKeys: ['menu_discovery', 'menu_questions', 'dietary_information', 'hours_locations', 'restaurant_policies', 'reservation_requests'],
            requirements: [
                $this->catalog('menu', 'templates.requirements.menu', TemplateRequirementImportance::Required, TemplateDataMode::Hybrid, ['search_catalog', 'get_product_details', 'recommend_products'], ['file', 'rest_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, TemplateSupportStatus::Supported, ['item_name', 'description', 'category', 'price', 'dietary_labels', 'image'], 'daily'),
                $this->knowledge('restaurant_faq', 'templates.requirements.restaurant_faq', TemplateRequirementImportance::Recommended, ['file', 'rest_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, ['question', 'answer'], 'daily'),
                $this->live('restaurant_locations', 'templates.requirements.restaurant_locations', TemplateRequirementType::LiveRead, TemplateRequirementImportance::Recommended, ['get_store_locations'], TemplateSetupAction::ConfigureLiveApi),
                $this->future('table_availability', 'templates.requirements.table_availability', TemplateRequirementImportance::Recommended),
                $this->future('table_reservation', 'templates.requirements.table_reservation', TemplateRequirementImportance::Recommended),
                $this->workflow('reservation_notification', TemplateRequirementImportance::Optional),
                $this->channel('website', TemplateRequirementImportance::Recommended),
                $this->channel('instagram', TemplateRequirementImportance::Optional),
                $this->channel('whatsapp', TemplateRequirementImportance::Optional),
            ],
            suggestedTestKeys: ['templates.tests.restaurant.menu', 'templates.tests.restaurant.policies'],
        );
    }

    private function saasSupport(): BusinessTemplateDefinition
    {
        return $this->template(
            key: 'saas_support',
            nameKey: 'templates.saas_support.name',
            descriptionKey: 'templates.saas_support.description',
            bestForKey: 'templates.saas_support.best_for',
            recommendedBotName: 'Support Assistant',
            outcomeKeys: ['documentation_search', 'faq', 'troubleshooting', 'support_tickets', 'human_escalation', 'service_status'],
            requirements: [
                $this->knowledge('help_center', 'templates.requirements.help_center', TemplateRequirementImportance::Required, ['file', 'rest_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, ['question', 'answer', 'url'], 'daily'),
                $this->knowledge('support_faq', 'templates.requirements.support_faq', TemplateRequirementImportance::Recommended, ['file', 'rest_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, ['question', 'answer'], 'daily'),
                $this->knowledge('documentation', 'templates.requirements.documentation', TemplateRequirementImportance::Recommended, ['file', 'rest_api', 'csv', 'json', 'xlsx'], TemplateSetupAction::ConnectDataSource, ['title', 'description', 'url'], 'daily'),
                $this->live('support_ticket', 'templates.requirements.support_ticket', TemplateRequirementType::LiveWrite, TemplateRequirementImportance::Recommended, ['create_support_ticket'], TemplateSetupAction::ConfigureWriteApi),
                $this->future('account_lookup', 'templates.requirements.account_lookup', TemplateRequirementImportance::Optional),
                $this->future('service_status', 'templates.requirements.service_status', TemplateRequirementImportance::Optional),
                $this->workflow('support_notification', TemplateRequirementImportance::Recommended),
                $this->workflow('handoff_notification', TemplateRequirementImportance::Recommended),
                $this->channel('website', TemplateRequirementImportance::Recommended),
                $this->channel('email', TemplateRequirementImportance::Recommended),
            ],
            suggestedTestKeys: ['templates.tests.saas_support.documentation', 'templates.tests.saas_support.ticket'],
        );
    }

    /**
     * @param  list<TemplateRequirement>  $requirements
     * @param  list<string>  $suggestedTestKeys
     */
    private function template(
        string $key,
        string $nameKey,
        string $descriptionKey,
        string $bestForKey,
        string $recommendedBotName,
        array $outcomeKeys,
        array $requirements,
        array $suggestedTestKeys,
    ): BusinessTemplateDefinition {
        return new BusinessTemplateDefinition(
            key: $key,
            version: 2,
            nameKey: $nameKey,
            descriptionKey: $descriptionKey,
            bestForKey: $bestForKey,
            recommendedBotName: $recommendedBotName,
            outcomeKeys: $outcomeKeys,
            requirements: $requirements,
            workflowRecommendations: $this->workflows($requirements),
            channelRecommendations: $this->channels($requirements),
            suggestedTestKeys: $suggestedTestKeys,
            onboardingSteps: $this->standardSteps(),
        );
    }

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $sourceTypes
     * @param  list<string>  $suggestedFields
     */
    private function catalog(string $key, string $translationPrefix, TemplateRequirementImportance $importance, TemplateDataMode $dataMode, array $capabilities, array $sourceTypes, TemplateSetupAction $action, TemplateSupportStatus $supportStatus, array $suggestedFields, string $refresh): TemplateRequirement
    {
        return $this->requirement($key, TemplateRequirementType::Catalog, $importance, $dataMode, $translationPrefix, $capabilities, $sourceTypes, $action, $supportStatus, $suggestedFields, $refresh);
    }

    /**
     * @param  list<string>  $sourceTypes
     * @param  list<string>  $suggestedFields
     * @param  list<string>  $capabilities
     */
    private function knowledge(string $key, string $translationPrefix, TemplateRequirementImportance $importance, array $sourceTypes, TemplateSetupAction $action, array $suggestedFields, string $refresh, TemplateRequirementType $type = TemplateRequirementType::Knowledge, array $capabilities = []): TemplateRequirement
    {
        return $this->requirement($key, $type, $importance, TemplateDataMode::Synced, $translationPrefix, $capabilities, $sourceTypes, $action, TemplateSupportStatus::Supported, $suggestedFields, $refresh);
    }

    /** @param list<string> $capabilities */
    private function live(string $key, string $translationPrefix, TemplateRequirementType $type, TemplateRequirementImportance $importance, array $capabilities, TemplateSetupAction $action): TemplateRequirement
    {
        return $this->requirement($key, $type, $importance, TemplateDataMode::Live, $translationPrefix, $capabilities, ['rest_api', 'graphql_api'], $action, TemplateSupportStatus::RequiresApi);
    }

    private function future(string $key, string $translationPrefix, TemplateRequirementImportance $importance): TemplateRequirement
    {
        return $this->requirement($key, TemplateRequirementType::LiveRead, $importance, TemplateDataMode::Live, $translationPrefix, [], [], TemplateSetupAction::None, TemplateSupportStatus::FutureCustom);
    }

    private function workflow(string $key, TemplateRequirementImportance $importance): TemplateRequirement
    {
        return $this->requirement($key, TemplateRequirementType::Workflow, $importance, null, "templates.requirements.{$key}", [], [], TemplateSetupAction::OpenWorkflows, TemplateSupportStatus::Supported);
    }

    private function channel(string $key, TemplateRequirementImportance $importance): TemplateRequirement
    {
        return $this->requirement($key, TemplateRequirementType::Channel, $importance, null, "templates.requirements.{$key}", [], [], TemplateSetupAction::ConfigureChannel, TemplateSupportStatus::Supported);
    }

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $sourceTypes
     * @param  list<string>  $suggestedFields
     */
    private function requirement(string $key, TemplateRequirementType $type, TemplateRequirementImportance $importance, ?TemplateDataMode $dataMode, string $translationPrefix, array $capabilities, array $sourceTypes, TemplateSetupAction $action, TemplateSupportStatus $supportStatus, array $suggestedFields = [], ?string $refresh = null): TemplateRequirement
    {
        if (in_array('rest_api', $sourceTypes, true) && ! in_array('graphql_api', $sourceTypes, true)) {
            $restIndex = array_search('rest_api', $sourceTypes, true);
            if (is_int($restIndex)) {
                $sourceTypes = array_values(array_unique([
                    ...array_slice($sourceTypes, 0, $restIndex + 1),
                    'graphql_api',
                    ...array_slice($sourceTypes, $restIndex + 1),
                ]));
            }
        }

        $copyKey = match ($type) {
            TemplateRequirementType::Knowledge => 'templates.requirement_copy.knowledge',
            TemplateRequirementType::Catalog => 'templates.requirement_copy.catalog',
            TemplateRequirementType::LiveRead => 'templates.requirement_copy.live_read',
            TemplateRequirementType::LiveWrite => 'templates.requirement_copy.live_write',
            TemplateRequirementType::Workflow => 'templates.requirement_copy.workflow',
            TemplateRequirementType::Channel => 'templates.requirement_copy.channel',
        };

        return new TemplateRequirement(
            key: $key,
            type: $type,
            importance: $importance,
            dataMode: $dataMode,
            titleKey: $translationPrefix.'.title',
            descriptionKey: $copyKey.'.description',
            whyKey: $copyKey.'.why',
            capabilities: $capabilities,
            recommendedSourceTypes: $sourceTypes,
            setupAction: $action,
            supportStatus: $supportStatus,
            suggestedFields: $suggestedFields,
            refreshRecommendation: $refresh,
            guidanceKey: null,
        );
    }

    /** @param list<TemplateRequirement> $requirements */
    private function workflows(array $requirements): array
    {
        $workflows = [];

        foreach ($requirements as $requirement) {
            if ($requirement->type !== TemplateRequirementType::Workflow) {
                continue;
            }

            $workflows[] = new TemplateWorkflowRecommendation(
                key: $requirement->key,
                titleKey: $requirement->titleKey,
                descriptionKey: $requirement->descriptionKey,
            );
        }

        return $workflows;
    }

    /** @param list<TemplateRequirement> $requirements */
    private function channels(array $requirements): array
    {
        $channels = [];

        foreach ($requirements as $requirement) {
            if ($requirement->type !== TemplateRequirementType::Channel) {
                continue;
            }

            $channels[] = new TemplateChannelRecommendation(
                key: $requirement->key,
                importance: $requirement->importance,
                titleKey: $requirement->titleKey,
                descriptionKey: $requirement->descriptionKey,
            );
        }

        return $channels;
    }

    /** @return list<array{key: string, labelKey: string, descriptionKey: string}> */
    private function standardSteps(): array
    {
        return [
            ['key' => 'data', 'labelKey' => 'templates.setup.steps.data.title', 'descriptionKey' => 'templates.setup.steps.data.description'],
            ['key' => 'capabilities', 'labelKey' => 'templates.setup.steps.capabilities.title', 'descriptionKey' => 'templates.setup.steps.capabilities.description'],
            ['key' => 'tests', 'labelKey' => 'templates.setup.steps.tests.title', 'descriptionKey' => 'templates.setup.steps.tests.description'],
            ['key' => 'design', 'labelKey' => 'templates.setup.steps.design.title', 'descriptionKey' => 'templates.setup.steps.design.description'],
            ['key' => 'domain', 'labelKey' => 'templates.setup.steps.domain.title', 'descriptionKey' => 'templates.setup.steps.domain.description'],
            ['key' => 'embed', 'labelKey' => 'templates.setup.steps.embed.title', 'descriptionKey' => 'templates.setup.steps.embed.description'],
        ];
    }
}
