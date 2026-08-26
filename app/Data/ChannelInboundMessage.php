<?php

namespace App\Data;

use App\Enums\ConversationChannel;

final readonly class ChannelInboundMessage
{
    /**
     * @param  list<array<string, mixed>>  $attachments
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ConversationChannel $channel,
        public string $externalConversationId,
        public string $externalUserId,
        public string $text,
        public array $attachments = [],
        public array $metadata = [],
        public ?string $externalMessageId = null,
    ) {}

    /**
     * Build the normalized message used by the current Website widget.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function fromWebsite(
        string $externalConversationId,
        string $externalUserId,
        string $text,
        array $metadata = [],
    ): self {
        return new self(
            channel: ConversationChannel::Website,
            externalConversationId: $externalConversationId,
            externalUserId: $externalUserId,
            text: $text,
            metadata: $metadata,
        );
    }
}
