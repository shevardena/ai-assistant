<?php

namespace App\Services\Conversations;

use App\Enums\ConversationStatus;
use App\Enums\TeamPermission;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\ConversationTag;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAuthorizationService;
use App\Services\Teams\TeamNotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ConversationOperationsService
{
    public function __construct(
        private readonly TeamAuthorizationService $authorization,
        private readonly TeamNotificationService $notifications,
    ) {}

    public function updateStatus(Team $team, Conversation $conversation, User $actor, ConversationStatus $status): Conversation
    {
        $conversation = $this->authorizeConversation($team, $conversation, $actor);
        $conversation->update(['conversation_status' => $status]);

        return $conversation->fresh();
    }

    public function assign(Team $team, Conversation $conversation, User $actor, ?User $assignee): Conversation
    {
        $conversation = $this->authorizeConversation($team, $conversation, $actor);

        if ($assignee !== null && (! $assignee->belongsToTeam($team)
            || ! $this->authorization->can($assignee, $team, TeamPermission::ConversationsReply))) {
            throw new HttpException(422, 'The selected assignee cannot handle conversations for this team.');
        }

        if ((int) $conversation->assigned_to_user_id === (int) $assignee?->getKey()) {
            return $conversation;
        }

        $conversation->update(['assigned_to_user_id' => $assignee?->getKey()]);
        $conversation = $conversation->fresh();

        if ($assignee !== null) {
            $this->notifications->notifyConversationAssigned($conversation, $assignee);
        }

        return $conversation;
    }

    public function createNote(Team $team, Conversation $conversation, User $actor, string $body): ConversationNote
    {
        $conversation = $this->authorizeConversation($team, $conversation, $actor);

        return $conversation->notes()->create([
            'team_id' => $team->getKey(),
            'user_id' => $actor->getKey(),
            'body' => trim($body),
        ]);
    }

    public function deleteNote(Team $team, Conversation $conversation, ConversationNote $note, User $actor): void
    {
        $conversation = $this->authorizeConversation($team, $conversation, $actor);
        abort_unless((int) $note->conversation_id === (int) $conversation->getKey()
            && (int) $note->team_id === (int) $team->getKey(), 404);

        $role = $this->authorization->role($actor, $team);
        abort_unless((int) $note->user_id === (int) $actor->getKey()
            || $role?->value === 'owner'
            || $role?->value === 'admin', 403);

        $note->delete();
    }

    public function createTag(Team $team, User $actor, string $name): ConversationTag
    {
        $this->authorizeTeam($team, $actor);
        $normalized = Str::squish(trim($name));
        abort_if($normalized === '', 422, 'A tag name is required.');
        $slug = Str::limit(Str::slug($normalized), 80, '');
        abort_if($slug === '', 422, 'A tag name is required.');

        return $team->conversationTags()->firstOrCreate(
            ['slug' => $slug],
            ['name' => Str::limit($normalized, 80, '')],
        );
    }

    public function attachTag(Team $team, Conversation $conversation, ConversationTag $tag, User $actor): void
    {
        $conversation = $this->authorizeConversation($team, $conversation, $actor);
        abort_unless((int) $tag->team_id === (int) $team->getKey(), 404);
        $conversation->tags()->syncWithoutDetaching([$tag->getKey()]);
    }

    public function detachTag(Team $team, Conversation $conversation, ConversationTag $tag, User $actor): void
    {
        $conversation = $this->authorizeConversation($team, $conversation, $actor);
        abort_unless((int) $tag->team_id === (int) $team->getKey(), 404);
        $conversation->tags()->detach($tag->getKey());
    }

    private function authorizeConversation(Team $team, Conversation $conversation, User $actor): Conversation
    {
        $this->authorizeTeam($team, $actor);

        $scoped = Conversation::query()
            ->whereKey($conversation->getKey())
            ->whereIn('bot_id', $team->bots()->select('id'))
            ->first();

        if (! $scoped instanceof Conversation) {
            throw (new ModelNotFoundException)->setModel(Conversation::class, [$conversation->getKey()]);
        }

        return $scoped;
    }

    private function authorizeTeam(Team $team, User $actor): void
    {
        abort_unless($actor->isCurrentTeam($team)
            && $this->authorization->can($actor, $team, TeamPermission::ConversationsManage), 403);
    }
}
