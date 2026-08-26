<?php

namespace App\Services\Conversations;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiSearchResponse;

class ConversationReply
{
    public function __construct(
        public readonly Conversation $conversation,
        public readonly Message $userMessage,
        public readonly Message $assistantMessage,
        public readonly AiSearchResponse $aiResponse,
        /** @var list<array<string, int|float|string|null>> */
        public readonly array $cards,
        /** @var list<array<string, mixed>> */
        public readonly array $blocks = [],
        /** @var array<string, mixed>|null */
        public readonly ?array $formBlock = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $appointmentBlock = null,
    ) {}
}
