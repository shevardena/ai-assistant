<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConversationReplyRequest;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\User;
use App\Services\Conversations\ConversationHandoffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ConversationHandoffController extends Controller
{
    public function __construct(private readonly ConversationHandoffService $handoffService) {}

    public function takeOver(Request $request, Team $currentTeam, Conversation $conversation): RedirectResponse
    {
        abort_unless($request->user() instanceof User, 401);
        Gate::forUser($request->user())->authorize('handoff', $conversation);
        $this->handoffService->takeOver($currentTeam, $conversation, $request->user());

        return back();
    }

    public function reply(ConversationReplyRequest $request, Team $currentTeam, Conversation $conversation): RedirectResponse
    {
        abort_unless($request->user() instanceof User, 401);
        Gate::forUser($request->user())->authorize('reply', $conversation);
        $this->handoffService->reply(
            $currentTeam,
            $conversation,
            $request->user(),
            (string) $request->validated('message'),
        );

        return back();
    }

    public function returnToAi(Request $request, Team $currentTeam, Conversation $conversation): RedirectResponse
    {
        abort_unless($request->user() instanceof User, 401);
        Gate::forUser($request->user())->authorize('handoff', $conversation);
        $this->handoffService->returnToAi($currentTeam, $conversation, $request->user());

        return back();
    }
}
