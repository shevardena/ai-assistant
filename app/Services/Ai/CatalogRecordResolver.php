<?php

namespace App\Services\Ai;

use App\Enums\DatasetStatus;
use App\Models\Bot;
use App\Models\Dataset;
use App\Models\DatasetRecord;
use Illuminate\Database\Eloquent\Builder;

class CatalogRecordResolver
{
    /**
     * Resolve one active record through the Bot's authorized Dataset attachments.
     *
     * @return array{dataset: Dataset, record: DatasetRecord}|null
     */
    public function resolve(Bot $bot, string $reference): ?array
    {
        $datasets = $bot->datasets()
            ->wherePivot('is_enabled', true)
            ->where('datasets.team_id', $bot->team_id)
            ->where('datasets.status', DatasetStatus::Ready->value)
            ->catalog()
            ->whereHas('records', fn (Builder $query): Builder => $query
                ->where('is_active', true)
                ->where('external_id', $reference))
            ->with('fields')
            ->orderBy('bot_datasets.priority')
            ->get();

        if ($datasets->count() !== 1) {
            return null;
        }

        $dataset = $datasets->first();

        if (! $dataset instanceof Dataset) {
            return null;
        }

        $record = $dataset->records()
            ->active()
            ->where('external_id', $reference)
            ->first();

        return $record instanceof DatasetRecord
            ? ['dataset' => $dataset, 'record' => $record]
            : null;
    }
}
