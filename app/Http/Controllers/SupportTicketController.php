<?php

namespace App\Http\Controllers;

use App\Enums\SupportTicketStatus;
use App\Http\Requests\SupportTicketIndexRequest;
use App\Http\Requests\UpdateSupportTicketStatusRequest;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Services\SupportTickets\SupportTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketService $tickets) {}

    public function index(SupportTicketIndexRequest $request, Team $currentTeam): Response
    {
        Gate::authorize('viewAny', SupportTicket::class);

        return Inertia::render('support-tickets/index', $this->tickets->index($currentTeam, $request->validated()));
    }

    public function show(Team $currentTeam, SupportTicket $supportTicket): Response
    {
        Gate::authorize('view', $supportTicket);

        return Inertia::render('support-tickets/show', $this->tickets->detail($currentTeam, $supportTicket));
    }

    public function update(UpdateSupportTicketStatusRequest $request, Team $currentTeam, SupportTicket $supportTicket): RedirectResponse
    {
        Gate::authorize('update', $supportTicket);

        $this->tickets->updateStatus($currentTeam, $supportTicket, SupportTicketStatus::from((string) $request->validated('status')));

        return back()->with('success', 'Support ticket status updated.');
    }
}
