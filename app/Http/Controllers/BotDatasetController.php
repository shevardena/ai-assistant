<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBotDatasetsRequest;
use App\Models\Bot;
use App\Models\Team;
use App\Services\ResourceStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class BotDatasetController extends Controller
{
    public function __construct(private readonly ResourceStatusService $resourceStatusService) {}

    public function update(
        UpdateBotDatasetsRequest $request,
        Team $currentTeam,
        Bot $bot,
    ): RedirectResponse {
        Gate::authorize('updateContent', $bot);

        $datasetIds = $request->datasetIds();

        DB::transaction(function () use ($bot, $datasetIds): void {
            $existingAttachments = $bot->botDatasets()
                ->get()
                ->keyBy('dataset_id');

            $bot->botDatasets()
                ->whereNotIn('dataset_id', $datasetIds)
                ->delete();

            foreach ($datasetIds as $datasetId) {
                if ($existingAttachments->has($datasetId)) {
                    $existingAttachments->get($datasetId)->update(['is_enabled' => true]);

                    continue;
                }

                $bot->botDatasets()->create([
                    'dataset_id' => $datasetId,
                    'priority' => 0,
                    'is_enabled' => true,
                    'settings' => [],
                ]);
            }
        });
        $this->resourceStatusService->refreshBotStatus($bot);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot datasets updated.')]);

        return to_route('bots.show', [
            'current_team' => $currentTeam->slug,
            'bot' => $bot,
        ]);
    }
}
