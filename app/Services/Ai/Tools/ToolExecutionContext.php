<?php

namespace App\Services\Ai\Tools;

use App\Enums\RuntimeMode;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Models\WidgetVisitor;

final readonly class ToolExecutionContext
{
    /**
     * @param  array<string, mixed>  $runtimeContext
     */
    public function __construct(
        public Bot $bot,
        public Team $team,
        public ?Conversation $conversation = null,
        public ?Message $userMessage = null,
        public ?WidgetVisitor $visitor = null,
        public array $runtimeContext = [],
        public RuntimeMode $mode = RuntimeMode::Normal,
    ) {}

    /**
     * @param  array<string, mixed>  $runtimeContext
     */
    public static function forBot(
        Bot $bot,
        ?Conversation $conversation = null,
        ?Message $userMessage = null,
        ?WidgetVisitor $visitor = null,
        array $runtimeContext = [],
        RuntimeMode $mode = RuntimeMode::Normal,
    ): self {
        return new self(
            bot: $bot,
            team: $bot->team,
            conversation: $conversation,
            userMessage: $userMessage,
            visitor: $visitor ?? $conversation?->visitor,
            runtimeContext: $runtimeContext,
            mode: $mode,
        );
    }

    public function isTest(): bool
    {
        return $this->mode === RuntimeMode::Test;
    }
}
