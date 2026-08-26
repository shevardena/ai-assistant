<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConversationInboxRequest;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\User;
use App\Services\Conversations\ConversationInboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ConversationInboxController extends Controller
{
    public function __construct(private readonly ConversationInboxService $inbox) {}

    public function index(ConversationInboxRequest $request, Team $currentTeam): Response
    {
        Gate::authorize('viewAny', Conversation::class);
        abort_unless($request->user() instanceof User, 401);

        return Inertia::render('conversations/index', $this->inbox->index(
            $currentTeam,
            $request->validated(),
            $request->user(),
        ));
    }

    public function show(Request $request, Team $currentTeam, Conversation $conversation): Response
    {
        Gate::authorize('view', $conversation);
        abort_unless($request->user() instanceof User, 401);

        return Inertia::render('conversations/show', $this->inbox->detail($currentTeam, $conversation, $request->user()));
    }
}
