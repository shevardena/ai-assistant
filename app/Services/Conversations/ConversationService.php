<?php

namespace App\Services\Conversations;

use App\Data\ChannelInboundMessage;
use App\Enums\ConversationChannel;
use App\Enums\ConversationHandoffStatus;
use App\Enums\ConversationStatus;
use App\Enums\RuntimeMode;
use App\Models\Bot;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Team;
use App\Models\WidgetVisitor;
use App\Services\Ai\AiSearchOrchestrator;
use App\Services\Ai\AiSearchResponse;
use App\Services\Billing\TeamEntitlementService;
use App\Services\Cards\ProductCardFormatter;
use App\Services\Conversations\Blocks\ConversationBlockNormalizer;
use App\Services\Customers\CustomerIdentityResolutionService;
use App\Services\KnowledgeGaps\KnowledgeGapService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConversationService
{
    public function __construct(
        private readonly AiSearchOrchestrator $orchestrator,
        private readonly ProductCardFormatter $cardFormatter,
        private readonly ConversationBlockNormalizer $blockNormalizer,
        private readonly ConversationFormService $formService,
        private readonly ConversationAppointmentService $appointmentService,
        private readonly KnowledgeGapService $knowledgeGapService,
        private readonly ConversationHandoffService $handoffService,
        private readonly TeamEntitlementService $entitlements,
        private readonly CustomerIdentityResolutionService $customers,
    ) {}

    public function createPreviewConversation(Bot $bot): Conversation
    {
        return $bot->conversations()->create([
            'public_id' => (string) Str::uuid(),
            'channel' => ConversationChannel::Website,
            'status' => 'active',
            'conversation_status' => ConversationStatus::Open,
            'language' => $bot->default_language,
            'metadata' => ['source' => 'dashboard_preview'],
        ]);
    }

    public function createWidgetConversation(Bot $bot, WidgetVisitor $visitor): Conversation
    {
        return DB::transaction(function () use ($bot, $visitor): Conversation {
            $team = $bot->team()->lockForUpdate()->firstOrFail();
            $this->entitlements->ensureCanStartConversation($team);

            $publicId = (string) Str::uuid();

            return $bot->conversations()->create([
                'public_id' => $publicId,
                'visitor_id' => $visitor->id,
                'channel' => ConversationChannel::Website,
                'external_user_reference' => $visitor->public_id,
                'external_conversation_reference' => $publicId,
                'status' => 'active',
                'conversation_status' => ConversationStatus::Open,
                'language' => $bot->default_language,
                'metadata' => ['source' => 'widget'],
            ]);
        });
    }

    public function persistWidgetWelcomeMessage(Bot $bot, Conversation $conversation): void
    {
        abort_unless($conversation->bot_id === $bot->id, 404);

        DB::transaction(function () use ($bot, $conversation): void {
            $lockedConversation = Conversation::query()
                ->whereKey($conversation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedConversation->messages()->exists()) {
                return;
            }

            $lockedConversation->messages()->create([
                'role' => 'assistant',
                'type' => 'text',
                'content' => trim((string) ($bot->welcome_message ?: 'Hello! How can I help?')),
                'metadata' => ['source' => 'widget_welcome'],
            ]);
        });
    }

    public function sendInboundMessage(
        Bot $bot,
        Conversation $conversation,
        ChannelInboundMessage $message,
        RuntimeMode $mode = RuntimeMode::Normal,
    ): ConversationReply {
        abort_unless($conversation->channel === $message->channel, 404);

        if (data_get($conversation->metadata, 'source') !== 'dashboard_preview'
            && in_array($conversation->conversation_status, [ConversationStatus::Resolved, ConversationStatus::Closed], true)) {
            $conversation->update(['conversation_status' => ConversationStatus::Open]);
        }

        return $this->completeMessage(
            $bot,
            $conversation,
            $message->text,
            userMetadata: $message->metadata,
            inboundMessage: $message,
            mode: $mode,
        );
    }

    public function findOrCreateChannelConversation(
        ChannelConnection $connection,
        ChannelInboundMessage $message,
    ): Conversation {
        abort_unless($connection->channel === $message->channel, 404);
        abort_unless($connection->status->value === 'active', 404);

        return DB::transaction(function () use ($connection, $message): Conversation {
            $lockedConnection = ChannelConnection::query()
                ->whereKey($connection->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $conversationQuery = $lockedConnection->bot->conversations()
                ->where('channel_connection_id', $lockedConnection->id)
                ->where('channel', $message->channel->value)
                ->where('status', 'active');

            if ($message->channel === ConversationChannel::Email) {
                $conversationQuery->where('external_user_reference', $message->externalUserId);
                $references = array_values(array_unique(array_filter([
                    $message->externalConversationId,
                    data_get($message->metadata, 'email_message_id'),
                    data_get($message->metadata, 'email_in_reply_to'),
                    ...((array) data_get($message->metadata, 'email_references', [])),
                ], static fn (mixed $reference): bool => is_string($reference) && $reference !== '')));

                $conversationQuery->where(function ($query) use ($references): void {
                    $query->whereIn('external_conversation_reference', $references)
                        ->orWhereHas('messages', function ($messages) use ($references): void {
                            $messages->whereIn('external_message_reference', $references);
                        });
                });
            } else {
                $conversationQuery->where('external_user_reference', $message->externalUserId);
            }

            $conversation = $conversationQuery->latest('id')->first();

            if ($conversation instanceof Conversation) {
                $this->linkChannelCustomer($conversation, $lockedConnection->team, $message);

                return $conversation;
            }

            $team = $lockedConnection->team()->lockForUpdate()->firstOrFail();
            $this->entitlements->ensureCanStartConversation($team);

            $conversation = $lockedConnection->bot->conversations()->create([
                'public_id' => (string) Str::uuid(),
                'channel_connection_id' => $lockedConnection->id,
                'channel' => $message->channel,
                'external_user_reference' => $message->externalUserId,
                'external_conversation_reference' => $message->externalConversationId,
                'customer_id' => $this->channelCustomer($lockedConnection->team, $message)?->id,
                'status' => 'active',
                'conversation_status' => ConversationStatus::Open,
                'language' => $lockedConnection->bot->default_language,
                'metadata' => ['source' => 'customer'],
            ]);

            return $conversation;
        });
    }

    private function linkChannelCustomer(Conversation $conversation, Team $team, ChannelInboundMessage $message): void
    {
        if ($conversation->customer_id !== null) {
            return;
        }

        $customer = $this->channelCustomer($team, $message);

        if ($customer !== null) {
            $conversation->update(['customer_id' => $customer->id, 'last_message_at' => now()]);
            $customer->forceFill(['last_activity_at' => now()])->saveQuietly();
        }
    }

    private function channelCustomer(Team $team, ChannelInboundMessage $message): ?Customer
    {
        $identity = match ($message->channel) {
            ConversationChannel::Email => ['email' => $message->externalUserId, 'source' => 'email'],
            ConversationChannel::Sms, ConversationChannel::WhatsApp => ['phone' => $message->externalUserId, 'source' => $message->channel->value],
            default => ['type' => 'channel_user', 'provider' => $message->channel->value, 'provider_external_id' => $message->externalUserId, 'source' => $message->channel->value],
        };

        return $this->customers->resolve($team, $identity)->customer;
    }

    public function sendMessage(
        Bot $bot,
        Conversation $conversation,
        string $message,
        RuntimeMode $mode = RuntimeMode::Normal,
    ): ConversationReply {
        abort_unless($conversation->bot_id === $bot->id, 404);

        return $this->completeMessage($bot, $conversation, $message, mode: $mode);
    }

    /**
     * Continue a conversation from a validated form submission.
     *
     * @param  array<string, mixed>  $values
     */
    public function submitForm(
        Bot $bot,
        Conversation $conversation,
        string $formReference,
        array $values,
        ?WidgetVisitor $visitor = null,
        RuntimeMode $mode = RuntimeMode::Normal,
    ): ConversationReply {
        $submission = $this->formService->submit(
            $bot,
            $conversation,
            $formReference,
            $values,
            $visitor,
        );

        $reply = $this->completeMessage(
            $bot,
            $conversation,
            $submission->displayMessage,
            ['form_submission' => $submission->values],
            [
                'form_submission' => [
                    'form_reference' => $formReference,
                    'values' => $submission->values,
                ],
            ],
            mode: $mode,
        );

        return new ConversationReply(
            $reply->conversation,
            $reply->userMessage,
            $reply->assistantMessage,
            $reply->aiResponse,
            $reply->cards,
            $reply->blocks,
            $submission->block->toArray(),
        );
    }

    public function submitAppointmentSlot(
        Bot $bot,
        Conversation $conversation,
        string $appointmentReference,
        string $slotReference,
        ?WidgetVisitor $visitor = null,
        RuntimeMode $mode = RuntimeMode::Normal,
    ): ConversationReply {
        $selection = $this->appointmentService->select(
            $bot,
            $conversation,
            $appointmentReference,
            $slotReference,
            $visitor,
        );

        $reply = $this->completeMessage(
            $bot,
            $conversation,
            $selection->displayMessage,
            ['appointment_selection' => $selection->runtimeContext],
            ['appointment_selection' => $selection->runtimeContext],
            mode: $mode,
        );

        return new ConversationReply(
            $reply->conversation,
            $reply->userMessage,
            $reply->assistantMessage,
            $reply->aiResponse,
            $reply->cards,
            $reply->blocks,
            null,
            $selection->block->toArray(),
        );
    }

    /**
     * @param  array<string, mixed>  $runtimeContext
     * @param  array<string, mixed>  $userMetadata
     */
    private function completeMessage(
        Bot $bot,
        Conversation $conversation,
        string $message,
        array $runtimeContext = [],
        array $userMetadata = [],
        ?ChannelInboundMessage $inboundMessage = null,
        RuntimeMode $mode = RuntimeMode::Normal,
    ): ConversationReply {
        abort_unless($conversation->bot_id === $bot->id, 404);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'type' => 'text',
            'content' => $message,
            'metadata' => $userMetadata,
            'channel_connection_id' => $conversation->channel_connection_id,
            'external_message_reference' => $inboundMessage?->externalMessageId,
        ]);

        $conversation->update(['last_message_at' => now()]);

        if ($conversation->fresh()->handoff_status !== ConversationHandoffStatus::Ai) {
            return $this->bypassedReply($conversation, $userMessage);
        }

        $history = $this->history($conversation, $userMessage);
        $response = $this->orchestrator->run(
            $bot,
            $message,
            $history,
            $conversation,
            $userMessage,
            $runtimeContext,
            mode: $mode,
        );

        $conversation = $conversation->fresh();

        if ($conversation->handoff_status !== ConversationHandoffStatus::Ai) {
            $assistantMessage = $this->handoffService->eventMessage($conversation, $userMessage)
                ?? $this->handoffService->waitingMessage($conversation);
            $handoffResponse = new AiSearchResponse(
                answer: (string) $assistantMessage->content,
                toolCallsCount: $response->toolCallsCount,
                searches: [],
                usage: $response->usage,
                toolOutcomes: $response->toolOutcomes,
            );

            return new ConversationReply(
                $conversation,
                $userMessage,
                $assistantMessage,
                $handoffResponse,
                [],
                [],
            );
        }

        $this->knowledgeGapService->recordFromResponse($bot, $conversation, $userMessage, $response);
        $cards = $this->cardFormatter->formatSearchSources($bot, $response->cardSources);
        $blocks = [
            ...$this->blockNormalizer->normalize($response->blocks),
            ...$this->blockNormalizer->fromProductCards($cards),
        ];

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'type' => 'text',
            'content' => $response->answer,
            'metadata' => [
                'tool_calls_count' => $response->toolCallsCount,
                'search_count' => count($response->searches),
                'blocks' => $blocks,
            ],
            'input_tokens' => data_get($response->usage, 'input_tokens'),
            'output_tokens' => data_get($response->usage, 'output_tokens'),
        ]);

        $conversation->update(['last_message_at' => now()]);

        return new ConversationReply($conversation->fresh(), $userMessage, $assistantMessage, $response, $cards, $blocks);
    }

    private function bypassedReply(Conversation $conversation, Message $userMessage): ConversationReply
    {
        $assistantMessage = $this->handoffService->waitingMessage($conversation);
        $response = new AiSearchResponse(
            answer: (string) $assistantMessage->content,
            toolCallsCount: 0,
            searches: [],
            usage: null,
        );

        return new ConversationReply(
            $conversation->fresh(),
            $userMessage,
            $assistantMessage,
            $response,
            [],
            [],
        );
    }

    /**
     * @return Collection<int, Message>
     */
    public function publicMessages(Conversation $conversation, ?int $afterMessageId = null): Collection
    {
        $query = $conversation->messages()
            ->whereIn('role', ['user', 'assistant', 'system'])
            ->select(['id', 'conversation_id', 'role', 'type', 'content', 'metadata', 'created_at']);

        if ($afterMessageId !== null) {
            return $query->where('id', '>', $afterMessageId)->orderBy('id')->get();
        }

        return $query
            ->latest('id')
            ->limit((int) config('widget.history_limit', 20) * 2)
            ->get()
            ->sortBy('id')
            ->values();
    }

    /**
     * Return normalized blocks for a persisted message.
     *
     * @return list<array<string, mixed>>
     */
    public function messageBlocks(Message $message): array
    {
        return $this->blockNormalizer->forMessage($message);
    }

    /**
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    private function history(Conversation $conversation, Message $currentMessage): array
    {
        return array_values($conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->where('id', '!=', $currentMessage->id)
            ->latest('id')
            ->limit((int) config('openai.conversation_history_limit', 20))
            ->get()
            ->sortBy('id')
            ->map(fn (Message $message): array => [
                'role' => $message->role === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $message->content,
            ])
            ->values()
            ->all());
    }
}
