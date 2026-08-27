<?php

namespace App\Services\Ai;

use App\Enums\ApiOperationMode;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Services\Api\LiveOperationCapabilityService;
use Illuminate\Database\Eloquent\Collection;

class BotCapabilityService
{
    /**
     * @var list<string>
     */
    private const CATALOG_TYPES = ['catalog', 'product', 'car', 'hotel', 'property', 'generic'];

    /**
     * @var list<string>
     */
    private const KNOWLEDGE_TYPES = ['faq', 'knowledge'];

    public function __construct(
        private readonly BotToolRegistry $toolRegistry,
        private readonly LiveOperationCapabilityService $liveOperations,
    ) {}

    /**
     * Build the safe, customer-facing capability summary for a Bot.
     *
     * @return list<array<string, mixed>>
     */
    public function forBot(Bot $bot): array
    {
        $availableTools = [];

        foreach ($this->toolRegistry->forBot($bot) as $tool) {
            $availableTools[$tool->name()] = true;
        }

        return [
            [
                'key' => 'data',
                'label' => 'Data',
                'capabilities' => [
                    $this->datasetCapability($bot, $availableTools, 'search_catalog', 'Product Search', 'Let visitors find products or listings from attached datasets.', self::CATALOG_TYPES, false),
                    $this->datasetCapability($bot, $availableTools, 'get_product_details', 'Product Details', 'Answer detailed questions about a specific result.', self::CATALOG_TYPES, false),
                    $this->datasetCapability($bot, $availableTools, 'lookup_faq', 'FAQ / Knowledge', 'Answer questions from FAQ and knowledge datasets.', self::KNOWLEDGE_TYPES, true),
                    $this->datasetCapability($bot, $availableTools, 'recommend_products', 'Recommendations', 'Recommend matching products or listings based on visitor needs.', self::CATALOG_TYPES, true),
                    $this->datasetCapability($bot, $availableTools, 'compare_products', 'Product Comparison', 'Compare multiple products or listings using real dataset fields.', self::CATALOG_TYPES, true),
                ],
            ],
            [
                'key' => 'live_information',
                'label' => 'Live Information',
                'capabilities' => [
                    $this->apiCapability($bot, $availableTools, 'check_stock', 'Stock Availability', 'Check current inventory through a connected API.', ApiOperationMode::Read),
                    $this->apiCapability($bot, $availableTools, 'get_shipping_info', 'Shipping Information', 'Retrieve live shipping cost, availability, or delivery estimates.', ApiOperationMode::Read),
                    $this->apiCapability($bot, $availableTools, 'check_order_status', 'Order Status', 'Check the current state of a customer order.', ApiOperationMode::Read),
                    $this->apiCapability($bot, $availableTools, 'track_order', 'Tracking', 'Retrieve carrier and shipment tracking information.', ApiOperationMode::Read),
                    $this->apiCapability($bot, $availableTools, 'get_store_locations', 'Store Locations', 'Find nearby stores, branches, or pickup locations.', ApiOperationMode::Read),
                ],
            ],
            [
                'key' => 'actions',
                'label' => 'Actions',
                'capabilities' => [
                    $this->apiCapability($bot, $availableTools, 'capture_lead', 'Capture Lead', 'Submit visitor contact details to your CRM or sales system.', ApiOperationMode::Write, true),
                    $this->apiCapability($bot, $availableTools, 'create_support_ticket', 'Create Support Ticket', 'Create a support request in your connected helpdesk.', ApiOperationMode::Write, true),
                    $this->apiCapability($bot, $availableTools, 'book_appointment', 'Book Appointment', 'Check availability and book confirmed appointments.', ApiOperationMode::Write, true),
                    $this->apiCapability($bot, $availableTools, 'add_to_cart', 'Add to Cart', 'Add a selected product to the visitor’s external cart.', ApiOperationMode::Write, true),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, bool>  $availableTools
     * @param  list<string>  $entityTypes
     * @return array<string, mixed>
     */
    private function datasetCapability(Bot $bot, array $availableTools, string $key, string $label, string $description, array $entityTypes, bool $requiresDisplayableField): array
    {
        $relevantDatasets = $this->attachedDatasets($bot, $entityTypes);
        $readyDatasets = $this->attachedDatasets($bot, $entityTypes, true, $requiresDisplayableField);
        $liveReady = $key === 'search_catalog'
            && $this->liveOperations->has($bot, $key);
        $isReady = ($readyDatasets->isNotEmpty() || $liveReady) && isset($availableTools[$key]);

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'kind' => 'data',
            'status' => $isReady ? 'ready' : ($relevantDatasets->isNotEmpty() ? 'needs_configuration' : 'unavailable'),
            'statusMessage' => $isReady
                ? 'Available to the assistant.'
                : ($relevantDatasets->isNotEmpty() || $liveReady ? 'Complete the dataset or live operation configuration.' : 'Attach a compatible dataset or live operation to enable this capability.'),
            'requiresConfirmation' => false,
            'details' => [
                'datasets' => $this->datasetDetails($isReady ? $readyDatasets : $relevantDatasets),
                'live' => $liveReady,
            ],
            'configureUrl' => route('bots.show', ['current_team' => $bot->team->slug, 'bot' => $bot]),
            'configureLabel' => 'Manage datasets',
        ];
    }

    /**
     * @param  array<string, bool>  $availableTools
     * @return array<string, mixed>
     */
    private function apiCapability(Bot $bot, array $availableTools, string $key, string $label, string $description, ApiOperationMode $mode, bool $requiresConfirmation = false): array
    {
        $attachments = $this->apiAttachments($bot, $key);
        $enabledAttachment = $attachments->first(fn (BotApiOperation $attachment): bool => $attachment->is_enabled);
        $attachment = $enabledAttachment instanceof BotApiOperation ? $enabledAttachment : $attachments->first();
        $operation = $attachment?->apiOperation;
        $dataSource = $operation?->dataSource;
        $isReady = $enabledAttachment instanceof BotApiOperation && isset($availableTools[$key]);
        $isDisabled = $attachments->isNotEmpty() && ! ($enabledAttachment instanceof BotApiOperation);

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'kind' => $mode === ApiOperationMode::Write ? 'action' : 'live',
            'status' => $isReady ? 'ready' : ($isDisabled ? 'disabled' : 'needs_configuration'),
            'statusMessage' => $isReady
                ? 'Available to the assistant.'
                : ($isDisabled
                    ? 'This capability is disabled for this Bot.'
                    : ($attachments->isEmpty() ? 'Connect an enabled API operation for this capability.' : sprintf('Configure an enabled %s API operation and complete its required settings.', $mode->value))),
            'requiresConfirmation' => $requiresConfirmation,
            'details' => [
                'operationName' => $operation?->name,
                'dataSourceName' => $dataSource?->name,
                'mode' => $operation instanceof ApiOperation
                    ? ($operation->execution_mode ?? $mode->value)
                    : $mode->value,
            ],
            'configureUrl' => $dataSource instanceof DataSource
                ? route('data-sources.edit', ['current_team' => $bot->team->slug, 'data_source' => $dataSource])
                : route('data-sources.index', ['current_team' => $bot->team->slug]),
            'configureLabel' => $dataSource instanceof DataSource ? 'Configure integration' : 'View data sources',
        ];
    }

    /**
     * @param  list<string>  $entityTypes
     * @return Collection<int, Dataset>
     */
    private function attachedDatasets(Bot $bot, array $entityTypes, bool $ready = false, bool $requiresDisplayableField = false): Collection
    {
        $query = $bot->datasets()
            ->where('datasets.team_id', $bot->team_id)
            ->whereIn('datasets.entity_type', $entityTypes)
            ->select(['datasets.id', 'datasets.name', 'datasets.slug', 'datasets.status', 'datasets.entity_type']);

        if ($ready) {
            $query->wherePivot('is_enabled', true)->ready();
        }

        if ($requiresDisplayableField) {
            $query->whereHas('fields', fn ($fields) => $fields->where('is_displayable', true));
        }

        return $query->orderBy('datasets.name')->get();
    }

    /**
     * @return Collection<int, BotApiOperation>
     */
    private function apiAttachments(Bot $bot, string $toolName): Collection
    {
        return $bot->botApiOperations()
            ->where('tool_name', $toolName)
            ->whereHas('bot', fn ($query) => $query->where('team_id', $bot->team_id))
            ->whereHas('apiOperation.dataSource', fn ($query) => $query->where('team_id', $bot->team_id))
            ->with(['apiOperation.dataSource'])
            ->latest()
            ->get();
    }

    /**
     * @param  Collection<int, Dataset>  $datasets
     * @return list<array{name: string, slug: string}>
     */
    private function datasetDetails(Collection $datasets): array
    {
        return array_values($datasets->map(fn (Dataset $dataset): array => [
            'name' => (string) $dataset->name,
            'slug' => (string) $dataset->slug,
        ])->all());
    }
}
