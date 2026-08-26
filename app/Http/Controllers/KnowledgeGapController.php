<?php

namespace App\Http\Controllers;

use App\Enums\KnowledgeGapStatus;
use App\Http\Requests\KnowledgeGapIndexRequest;
use App\Http\Requests\UpdateKnowledgeGapStatusRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\KnowledgeGaps\KnowledgeGapService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeGapController extends Controller
{
    public function __construct(private readonly KnowledgeGapService $knowledgeGaps) {}

    public function index(KnowledgeGapIndexRequest $request, Team $currentTeam): Response
    {
        return Inertia::render('knowledge-gaps/index', $this->knowledgeGaps->index(
            $currentTeam,
            $request->validated(),
        ));
    }

    public function update(
        UpdateKnowledgeGapStatusRequest $request,
        Team $currentTeam,
        string $groupReference,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $this->knowledgeGaps->updateStatus(
            $currentTeam,
            $groupReference,
            KnowledgeGapStatus::from((string) $request->validated('status')),
            $user,
        );

        return back()->with('success', 'Knowledge gap status updated.');
    }
}
