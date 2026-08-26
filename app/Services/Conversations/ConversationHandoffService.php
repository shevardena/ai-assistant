<?php

namespace App\Services\Conversations;

use App\Enums\ConversationHandoffStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Services\Channels\ChannelDeliveryService;
use App\Services\Teams\TeamNotificationService;
use App\Services\Workflows\WorkflowTriggerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ConversationHandoffService
{
    public const REQUESTED_MESSAGE = "I've asked a member of the team to join the conversation.";

    public const HUMAN_MESSAGE = 'A member of the team has joined the conversation.';

    public const RETURNED_MESSAGE = "You're back with the AI assistant.";

    public const WAITING_MESSAGE = 'A member of the team is reviewing this conversation and will reply here.';

    public const HUMAN_REPLY_ACKNOWLEDGEMENT = 'Your message has been sent to the team.';

    public function __construct(
        private readonly TeamNotificationService $notifications,
        private readonly ChannelDeliveryService $delivery,
    ) {}

    /**
     * Request a handoff for a customer conversation.
     *
     * Preview conversations are intentionally excluded from operational handoff.
     */
    public function request(Team $team, Conversation $conversation, string $reason, ?string $originWorkflowRunId = null, int $workflowDepth = 0): bool
    {
        $conversation = $this->scopedConversation($team, $conversation);

        if ($this->isPreview($conversation)) {
            return false;
        }

        if (! in_array($reason, ['customer_requested', 'runtime_escalation', 'manual'], true)) {
            throw new HttpException(422, 'The handoff reason is not supported.');
        }

        if ($conversation->handoff_status !== ConversationHandoffStatus::Ai) {
            return true;
        }

        $conversation->update([
            'handoff_status' => ConversationHandoffStatus::Requested,
            'handoff_reason' => $reason,
            'handoff_requested_at' => now(),
        ]);
        $this->systemMessage($conversation, self::REQUESTED_MESSAGE, 'requested');
        $this->notifications->notifyHandoffRequested($conversation->fresh() ?? $conversation);
        app(WorkflowTriggerService::class)->humanHandoffRequested(
            $conversation->fresh() ?? $conversation,
            $reason,
            $originWorkflowRunId,
            $workflowDepth,
        );

        return true;
    }

    /**
     * Move a requested conversation into human control.
     */
    public function takeOver(Team $team, Conversation $conversation, User $user): Conversation
    {
        $conversation = $this->scopedConversation($team, $conversation);

        abort_unless($team->members()->whereKey($user->id)->exists(), 403);

        if ($conversation->handoff_status !== ConversationHandoffStatus::Requested) {
            throw new HttpException(409, 'This conversation is not waiting for a takeover.');
        }

        $previousAssigneeId = $conversation->assigned_to_user_id;

        $conversation->update([
            'handoff_status' => ConversationHandoffStatus::Human,
            'handoff_started_at' => now(),
            'handoff_user_id' => $user->id,
            'assigned_to_user_id' => $user->id,
        ]);
        $this->systemMessage($conversation, self::HUMAN_MESSAGE, 'taken_over');

        $conversation = $conversation->fresh();

        if ((int) $previousAssigneeId !== (int) $user->id) {
            $this->notifications->notifyConversationAssigned($conversation, $user);
        }

        return $conversation;
    }

    /**
     * Return a requested or human-controlled conversation to AI control.
     */
    public function returnToAi(Team $team, Conversation $conversation, User $user): Conversation
    {
        $conversation = $this->scopedConversation($team, $conversation);

        abort_unless($team->members()->whereKey($user->id)->exists(), 403);

        if (! in_array($conversation->handoff_status, [
            ConversationHandoffStatus::Requested,
            ConversationHandoffStatus::Human,
        ], true)) {
            throw new HttpException(409, 'This conversation is already controlled by AI.');
        }

        $conversation->update([
            'handoff_status' => ConversationHandoffStatus::Ai,
        ]);
        $this->systemMessage($conversation, self::RETURNED_MESSAGE, 'returned_to_ai');

        return $conversation->fresh();
    }

    /**
     * Persist a plain-text Team reply on a human-controlled conversation.
     */
    public function reply(Team $team, Conversation $conversation, User $user, string $content): Message
    {
        $conversation = $this->scopedConversation($team, $conversation);

        abort_unless($team->members()->whereKey($user->id)->exists(), 403);

        if ($conversation->handoff_status !== ConversationHandoffStatus::Human) {
            throw new HttpException(409, 'Take over the conversation before sending a reply.');
        }

        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'type' => 'text',
            'content' => $content,
            'metadata' => [
                'source' => 'human_agent',
            ],
        ]);
        $conversation->update(['last_message_at' => now()]);

        $delivery = $this->delivery->sendAssistantMessage($conversation, $message);

        if (! $delivery->successful) {
            throw new HttpException(502, 'The message could not be delivered to the customer.');
        }

        return $message;
    }

    /**
     * Return a transient acknowledgement for a customer message while AI is bypassed.
     */
    public function waitingMessage(Conversation $conversation): Message
    {
        $message = new Message([
            'role' => 'system',
            'type' => 'text',
            'content' => $conversation->handoff_status === ConversationHandoffStatus::Human
                ? self::HUMAN_REPLY_ACKNOWLEDGEMENT
                : self::WAITING_MESSAGE,
            'metadata' => [
                'source' => 'handoff',
            ],
        ]);
        $message->exists = false;

        return $message;
    }

    /**
     * Find the persisted handoff event generated for this user message.
     */
    public function eventMessage(Conversation $conversation, Message $userMessage): ?Message
    {
        return $conversation->messages()
            ->where('role', 'system')
            ->where('created_at', '>=', $userMessage->created_at)
            ->whereJsonContains('metadata->source', 'handoff')
            ->latest('id')
            ->first();
    }

    private function scopedConversation(Team $team, Conversation $conversation): Conversation
    {
        $scoped = Conversation::query()
            ->whereKey($conversation->getKey())
            ->whereIn('bot_id', $team->bots()->select('id'))
            ->first();

        if (! $scoped instanceof Conversation) {
            throw (new ModelNotFoundException)->setModel(Conversation::class, [$conversation->getKey()]);
        }

        return $scoped;
    }

    private function isPreview(Conversation $conversation): bool
    {
        return data_get($conversation->metadata, 'source') === 'dashboard_preview';
    }

    private function systemMessage(Conversation $conversation, string $content, string $event): Message
    {
        $message = $conversation->messages()->create([
            'role' => 'system',
            'type' => 'text',
            'content' => $content,
            'metadata' => [
                'source' => 'handoff',
                'event' => $event,
            ],
        ]);
        $conversation->update(['last_message_at' => now()]);

        return $message;
    }
}
