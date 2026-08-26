<?php

namespace App\Http\Controllers;

use App\Enums\ConversationStatus;
use App\Http\Requests\StoreConversationNoteRequest;
use App\Http\Requests\StoreConversationTagRequest;
use App\Http\Requests\UpdateConversationAssignmentRequest;
use App\Http\Requests\UpdateConversationStatusRequest;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\ConversationTag;
use App\Models\Team;
use App\Models\User;
use App\Services\Conversations\ConversationOperationsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ConversationOperationsController extends Controller
{
    public function __construct(private readonly ConversationOperationsService $operations) {}

    public function status(UpdateConversationStatusRequest $request, Team $currentTeam, Conversation $conversation): RedirectResponse
    {
        $this->operations->updateStatus($currentTeam, $conversation, $this->user($request), ConversationStatus::from((string) $request->validated('status')));

        return back();
    }

    public function assignment(UpdateConversationAssignmentRequest $request, Team $currentTeam, Conversation $conversation): RedirectResponse
    {
        $userId = $request->validated('assigned_to_user_id');
        $assignee = null;

        if ($userId !== null) {
            $assignee = User::query()->whereKey($userId)->first();
            abort_unless($assignee instanceof User, 422);
        }

        $this->operations->assign($currentTeam, $conversation, $this->user($request), $assignee);

        return back();
    }

    public function note(StoreConversationNoteRequest $request, Team $currentTeam, Conversation $conversation): RedirectResponse
    {
        $this->operations->createNote($currentTeam, $conversation, $this->user($request), $request->validated('body'));

        return back();
    }

    public function deleteNote(Request $request, Team $currentTeam, Conversation $conversation, ConversationNote $note): RedirectResponse
    {
        $this->operations->deleteNote($currentTeam, $conversation, $note, $this->user($request));

        return back();
    }

    public function createTag(StoreConversationTagRequest $request, Team $currentTeam): RedirectResponse
    {
        $this->operations->createTag($currentTeam, $this->user($request), $request->validated('name'));

        return back();
    }

    public function attachTag(Request $request, Team $currentTeam, Conversation $conversation, string $tag): RedirectResponse
    {
        $this->operations->attachTag(
            $currentTeam,
            $conversation,
            ConversationTag::query()->where('public_id', $tag)->firstOrFail(),
            $this->user($request),
        );

        return back();
    }

    public function detachTag(Request $request, Team $currentTeam, Conversation $conversation, string $tag): RedirectResponse
    {
        $this->operations->detachTag(
            $currentTeam,
            $conversation,
            ConversationTag::query()->where('public_id', $tag)->firstOrFail(),
            $this->user($request),
        );

        return back();
    }

    private function user(Request $request): User
    {
        abort_unless($request->user() instanceof User, 401);

        return $request->user();
    }
}
