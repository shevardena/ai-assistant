<?php

namespace App\Services\Ai;

use App\Models\Bot;
use App\Services\Ai\Tools\AddToCartTool;
use App\Services\Ai\Tools\BookAppointmentTool;
use App\Services\Ai\Tools\CaptureLeadTool;
use App\Services\Ai\Tools\CheckOrderStatusTool;
use App\Services\Ai\Tools\CheckStockTool;
use App\Services\Ai\Tools\CompareProductsTool;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Ai\Tools\CreateSupportTicketTool;
use App\Services\Ai\Tools\GetProductDetailsTool;
use App\Services\Ai\Tools\GetShippingInfoTool;
use App\Services\Ai\Tools\GetStoreLocationsTool;
use App\Services\Ai\Tools\LookupFaqTool;
use App\Services\Ai\Tools\RecommendProductsTool;
use App\Services\Ai\Tools\RequestHumanHandoffTool;
use App\Services\Ai\Tools\SearchCatalogTool;
use App\Services\Ai\Tools\TrackOrderTool;

class BotToolRegistry
{
    public function __construct(
        private readonly BotRuntimeContextBuilder $contextBuilder,
        private readonly SearchCatalogTool $searchCatalogTool,
        private readonly CompareProductsTool $compareProductsTool,
        private readonly CheckStockTool $checkStockTool,
        private readonly CheckOrderStatusTool $checkOrderStatusTool,
        private readonly AddToCartTool $addToCartTool,
        private readonly CaptureLeadTool $captureLeadTool,
        private readonly CreateSupportTicketTool $createSupportTicketTool,
        private readonly BookAppointmentTool $bookAppointmentTool,
        private readonly GetShippingInfoTool $getShippingInfoTool,
        private readonly GetProductDetailsTool $getProductDetailsTool,
        private readonly LookupFaqTool $lookupFaqTool,
        private readonly RecommendProductsTool $recommendProductsTool,
        private readonly TrackOrderTool $trackOrderTool,
        private readonly GetStoreLocationsTool $getStoreLocationsTool,
        private readonly RequestHumanHandoffTool $requestHumanHandoffTool,
    ) {}

    /**
     * Return the statically registered tools allowed for this Bot.
     *
     * @return list<BotTool>
     */
    public function forBot(Bot $bot): array
    {
        $context = $this->contextBuilder->build($bot);

        $tools = [];

        $tools[] = $this->requestHumanHandoffTool;

        if ($bot->datasets()
            ->wherePivot('is_enabled', true)
            ->where('datasets.team_id', $bot->team_id)
            ->catalog()
            ->ready()
            ->exists()) {
            $tools[] = $this->searchCatalogTool;
        }

        if ($bot->datasets()
            ->wherePivot('is_enabled', true)
            ->where('datasets.team_id', $bot->team_id)
            ->catalog()
            ->ready()
            ->exists()) {
            $tools[] = $this->getProductDetailsTool;
        }

        if ($bot->datasets()
            ->wherePivot('is_enabled', true)
            ->where('datasets.team_id', $bot->team_id)
            ->knowledge()
            ->ready()
            ->whereHas('fields', fn ($query) => $query->where('is_displayable', true))
            ->exists()) {
            $tools[] = $this->lookupFaqTool;
        }

        if ($bot->datasets()
            ->wherePivot('is_enabled', true)
            ->where('datasets.team_id', $bot->team_id)
            ->catalog()
            ->ready()
            ->whereHas('fields', fn ($query) => $query->where('is_displayable', true))
            ->exists()) {
            $tools[] = $this->recommendProductsTool;
        }

        if ($bot->datasets()
            ->wherePivot('is_enabled', true)
            ->where('datasets.team_id', $bot->team_id)
            ->catalog()
            ->ready()
            ->whereHas('fields', fn ($query) => $query->where('is_displayable', true))
            ->exists()) {
            $tools[] = $this->compareProductsTool;
        }

        if ($this->checkStockTool->isAvailable($bot)) {
            $tools[] = $this->checkStockTool;
        }

        if ($this->addToCartTool->isAvailable($bot)) {
            $tools[] = $this->addToCartTool;
        }

        if ($this->getShippingInfoTool->isAvailable($bot)) {
            $tools[] = $this->getShippingInfoTool;
        }

        if ($this->checkOrderStatusTool->isAvailable($bot)) {
            $tools[] = $this->checkOrderStatusTool;
        }

        if ($this->trackOrderTool->isAvailable($bot)) {
            $tools[] = $this->trackOrderTool;
        }

        if ($this->getStoreLocationsTool->isAvailable($bot)) {
            $tools[] = $this->getStoreLocationsTool;
        }

        if ($this->captureLeadTool->isAvailable($bot)) {
            $tools[] = $this->captureLeadTool;
        }

        if ($this->createSupportTicketTool->isAvailable($bot)) {
            $tools[] = $this->createSupportTicketTool;
        }

        if ($this->bookAppointmentTool->isAvailable($bot)) {
            $tools[] = $this->bookAppointmentTool;
        }

        return $tools;
    }

    public function find(Bot $bot, string $toolName): ?BotTool
    {
        foreach ($this->forBot($bot) as $tool) {
            if ($tool->name() === $toolName) {
                return $tool;
            }
        }

        return null;
    }

    public function findForAction(Bot $bot, string $toolName): ?BotTool
    {
        foreach ($this->allTools() as $tool) {
            if ($tool->name() === $toolName) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Return names from the statically registered runtime tool catalog.
     *
     * @return list<string>
     */
    public function knownToolNames(): array
    {
        return array_map(
            static fn (BotTool $tool): string => $tool->name(),
            $this->allTools(),
        );
    }

    /**
     * @return list<BotTool>
     */
    private function allTools(): array
    {
        return [
            $this->searchCatalogTool,
            $this->compareProductsTool,
            $this->checkStockTool,
            $this->checkOrderStatusTool,
            $this->addToCartTool,
            $this->captureLeadTool,
            $this->createSupportTicketTool,
            $this->bookAppointmentTool,
            $this->getShippingInfoTool,
            $this->getProductDetailsTool,
            $this->lookupFaqTool,
            $this->recommendProductsTool,
            $this->trackOrderTool,
            $this->getStoreLocationsTool,
            $this->requestHumanHandoffTool,
        ];
    }
}
