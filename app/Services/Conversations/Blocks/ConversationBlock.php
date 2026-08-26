<?php

namespace App\Services\Conversations\Blocks;

interface ConversationBlock
{
    public function type(): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
