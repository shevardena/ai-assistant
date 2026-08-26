<?php

namespace App\Http\Controllers;

use App\Enums\LeadStatus;
use App\Http\Requests\LeadIndexRequest;
use App\Http\Requests\UpdateLeadStatusRequest;
use App\Models\Lead;
use App\Models\Team;
use App\Services\Leads\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function __construct(private readonly LeadService $leads) {}

    public function index(LeadIndexRequest $request, Team $currentTeam): Response
    {
        Gate::authorize('viewAny', Lead::class);

        return Inertia::render('leads/index', $this->leads->index(
            $currentTeam,
            $request->validated(),
        ));
    }

    public function show(Team $currentTeam, Lead $lead): Response
    {
        Gate::authorize('view', $lead);

        return Inertia::render('leads/show', $this->leads->detail($currentTeam, $lead));
    }

    public function update(
        UpdateLeadStatusRequest $request,
        Team $currentTeam,
        Lead $lead,
    ): RedirectResponse {
        Gate::authorize('update', $lead);

        $this->leads->updateStatus(
            $currentTeam,
            $lead,
            LeadStatus::from((string) $request->validated('status')),
        );

        return back()->with('success', 'Lead status updated.');
    }
}
