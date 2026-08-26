<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderPipelineStagesRequest;
use App\Http\Requests\StorePipelineRequest;
use App\Http\Requests\StorePipelineStageRequest;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Team;
use App\Services\Deals\PipelineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PipelineController extends Controller
{
    public function __construct(private readonly PipelineService $pipelines) {}

    public function index(Team $currentTeam): Response
    {
        Gate::authorize('viewAny', Deal::class);
        $this->pipelines->ensureDefault($currentTeam);

        return Inertia::render('deals/pipelines', ['pipelines' => $currentTeam->pipelines()->with('stages')->orderByDesc('is_default')->orderBy('name')->get()->values()]);
    }

    public function store(StorePipelineRequest $request, Team $currentTeam): RedirectResponse
    {
        Gate::authorize('create', Deal::class);
        $this->pipelines->create($currentTeam, $request->validated());

        return back()->with('success', 'Pipeline created.');
    }

    public function update(StorePipelineRequest $request, Team $currentTeam, Pipeline $pipeline): RedirectResponse
    {
        Gate::authorize('create', Deal::class);
        $this->pipelines->update($currentTeam, $pipeline, $request->validated());

        return back()->with('success', 'Pipeline updated.');
    }

    public function default(Team $currentTeam, Pipeline $pipeline): RedirectResponse
    {
        Gate::authorize('create', Deal::class);
        $this->pipelines->setDefault($currentTeam, $pipeline);

        return back()->with('success', 'Default pipeline updated.');
    }

    public function destroy(Team $currentTeam, Pipeline $pipeline): RedirectResponse
    {
        Gate::authorize('create', Deal::class);
        $this->pipelines->delete($currentTeam, $pipeline);

        return back()->with('success', 'Pipeline deleted.');
    }

    public function stageStore(StorePipelineStageRequest $request, Team $currentTeam, Pipeline $pipeline): RedirectResponse
    {
        Gate::authorize('create', Deal::class);
        $this->pipelines->createStage($currentTeam, $pipeline, $request->validated());

        return back()->with('success', 'Stage created.');
    }

    public function stageUpdate(StorePipelineStageRequest $request, Team $currentTeam, PipelineStage $stage): RedirectResponse
    {
        Gate::authorize('create', Deal::class);
        $this->pipelines->updateStage($currentTeam, $stage, $request->validated());

        return back()->with('success', 'Stage updated.');
    }

    public function reorder(ReorderPipelineStagesRequest $request, Team $currentTeam, Pipeline $pipeline): RedirectResponse
    {
        Gate::authorize('create', Deal::class);
        $this->pipelines->reorderStages($currentTeam, $pipeline, $request->validated('stage_ids'));

        return back()->with('success', 'Stages reordered.');
    }

    public function stageDestroy(Team $currentTeam, PipelineStage $stage): RedirectResponse
    {
        Gate::authorize('create', Deal::class);
        $this->pipelines->deleteStage($currentTeam, $stage);

        return back()->with('success', 'Stage deleted.');
    }
}
