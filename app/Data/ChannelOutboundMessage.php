<?php

namespace App\Data;

use App\Enums\ConversationChannel;

final readonly class ChannelOutboundMessage
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, int|float|string|null>>  $cards
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ConversationChannel $channel,
        public string $text,
        public array $blocks = [],
        public array $cards = [],
        public ?string $externalUserId = null,
        public array $metadata = [],
    ) {}
}
