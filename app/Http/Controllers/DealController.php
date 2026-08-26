<?php

namespace App\Http\Controllers;

use App\Http\Requests\DealIndexRequest;
use App\Http\Requests\DealStageRequest;
use App\Http\Requests\LostDealRequest;
use App\Http\Requests\StoreDealRequest;
use App\Http\Requests\UpdateDealRequest;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Team;
use App\Services\Deals\DealService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DealController extends Controller
{
    public function __construct(private readonly DealService $deals) {}

    public function index(DealIndexRequest $request, Team $currentTeam): Response
    {
        Gate::authorize('viewAny', Deal::class);

        return Inertia::render('deals/index', $this->deals->index($currentTeam, $request->validated(), (string) $request->validated('view', 'board')));
    }

    public function create(Team $currentTeam): Response
    {
        Gate::authorize('create', Deal::class);

        return Inertia::render('deals/create', $this->deals->formOptions($currentTeam));
    }

    public function store(StoreDealRequest $request, Team $currentTeam): RedirectResponse
    {
        Gate::authorize('create', Deal::class);
        $deal = $this->deals->create($currentTeam, $request->validated(), $request->user());

        return redirect()->route('deals.show', [$currentTeam->slug, $deal]);
    }

    public function show(Team $currentTeam, Deal $deal): Response
    {
        Gate::authorize('view', $deal);

        return Inertia::render('deals/show', $this->deals->detail($currentTeam, $deal));
    }

    public function update(UpdateDealRequest $request, Team $currentTeam, Deal $deal): RedirectResponse
    {
        Gate::authorize('update', $deal);
        $this->deals->update($currentTeam, $deal, $request->validated(), $request->user());

        return back()->with('success', 'Deal updated.');
    }

    public function stage(DealStageRequest $request, Team $currentTeam, Deal $deal): RedirectResponse
    {
        Gate::authorize('update', $deal);
        $stage = $currentTeam->pipelineStages()->whereKey((int) $request->validated('stage_id'))->firstOrFail();
        $this->deals->moveStage($currentTeam, $deal, $stage, $request->user());

        return back()->with('success', 'Deal stage updated.');
    }

    public function won(Team $currentTeam, Deal $deal): RedirectResponse
    {
        Gate::authorize('update', $deal);
        $this->deals->markWon($currentTeam, $deal, request()->user());

        return back()->with('success', 'Deal marked won.');
    }

    public function lost(LostDealRequest $request, Team $currentTeam, Deal $deal): RedirectResponse
    {
        Gate::authorize('update', $deal);
        $this->deals->markLost($currentTeam, $deal, $request->validated('lost_reason'), $request->user());

        return back()->with('success', 'Deal marked lost.');
    }

    public function reopen(DealStageRequest $request, Team $currentTeam, Deal $deal): RedirectResponse
    {
        Gate::authorize('update', $deal);
        $stage = PipelineStage::query()->whereKey((int) $request->validated('stage_id'))->firstOrFail();
        $this->deals->reopen($currentTeam, $deal, $stage, $request->user());

        return back()->with('success', 'Deal reopened.');
    }

    public function createFromLead(StoreDealRequest $request, Team $currentTeam, Lead $lead): RedirectResponse
    {
        Gate::authorize('create', Deal::class);
        $deal = $this->deals->createFromLead($currentTeam, $lead, $request->validated(), $request->user());

        return redirect()->route('deals.show', [$currentTeam->slug, $deal]);
    }
}
