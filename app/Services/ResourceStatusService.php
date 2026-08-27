<?php

namespace App\Services;

use App\Enums\BotStatus;
use App\Enums\DatasetStatus;
use App\Enums\DataSourceStatus;
use App\Models\Bot;
use App\Models\Dataset;
use App\Models\DataSource;
use App\Services\Api\LiveOperationCapabilityService;
use Carbon\CarbonInterface;

class ResourceStatusService
{
    public function __construct(
        private readonly LiveOperationCapabilityService $liveOperations,
    ) {}

    /**
     * Mark a source as actively importing or synchronizing.
     */
    public function markDataSourceSyncing(DataSource $dataSource): void
    {
        $dataSource->forceFill([
            'status' => DataSourceStatus::Syncing->value,
        ])->save();
    }

    /**
     * Mark a source as usable after an upload or successful authoritative sync.
     *
     * A timestamp is supplied only for a successful import. Uploading a file
     * makes a file source available, but is not itself a synchronization.
     */
    public function markDataSourceReady(
        DataSource $dataSource,
        ?CarbonInterface $lastSyncedAt = null,
    ): void {
        $attributes = [
            'status' => DataSourceStatus::Ready->value,
        ];

        if ($lastSyncedAt instanceof CarbonInterface) {
            $attributes['last_synced_at'] = $lastSyncedAt;
        }

        $dataSource->forceFill($attributes)->save();
    }

    /**
     * Mark the latest source operation as failed without changing the last
     * successful synchronization timestamp.
     */
    public function markDataSourceError(DataSource $dataSource): void
    {
        $dataSource->forceFill([
            'status' => DataSourceStatus::Error->value,
        ])->save();
    }

    /**
     * Mark a dataset as actively processing an authoritative import.
     */
    public function markDatasetProcessing(Dataset $dataset): void
    {
        $dataset->forceFill([
            'status' => DatasetStatus::Processing->value,
        ])->save();
        $this->refreshBotsForDataset($dataset);
    }

    /**
     * Mark a dataset as usable after its PostgreSQL snapshot is committed.
     */
    public function markDatasetReady(Dataset $dataset): void
    {
        $dataset->forceFill([
            'status' => DatasetStatus::Ready->value,
        ])->save();
        $this->refreshBotsForDataset($dataset);
    }

    /**
     * Mark a dataset as unusable after its latest authoritative import fails.
     */
    public function markDatasetError(Dataset $dataset): void
    {
        $dataset->forceFill([
            'status' => DatasetStatus::Error->value,
        ])->save();
        $this->refreshBotsForDataset($dataset);
    }

    /**
     * Recalculate a bot from its usable local datasets or live catalog operation.
     */
    public function refreshBotStatus(Bot $bot): void
    {
        $hasUsableDataset = $bot->botDatasets()
            ->where('is_enabled', true)
            ->whereHas('dataset', fn ($query) => $query
                ->where('datasets.team_id', $bot->team_id)
                ->where('datasets.status', DatasetStatus::Ready->value))
            ->exists();
        $hasUsableLiveCatalog = $this->liveOperations->has($bot, 'search_catalog');

        $bot->forceFill([
            'status' => $hasUsableDataset || $hasUsableLiveCatalog
                ? BotStatus::Ready->value
                : BotStatus::Draft->value,
        ])->save();
    }

    /**
     * Recalculate every bot attached to a dataset in the same tenant.
     */
    public function refreshBotsForDataset(Dataset $dataset): void
    {
        $bots = $dataset->bots()
            ->where('bots.team_id', $dataset->team_id)
            ->get();

        foreach ($bots as $bot) {
            $this->refreshBotStatus($bot);
        }
    }
}
