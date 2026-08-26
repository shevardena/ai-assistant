<?php

namespace App\Services\Conversations\Blocks;

final readonly class ProductCardsBlock implements ConversationBlock
{
    /**
     * @param  list<array<string, mixed>>  $cards
     */
    public function __construct(public array $cards) {}

    public function type(): string
    {
        return ConversationBlockType::ProductCards->value;
    }

    /**
     * @return array{type: 'product_cards', data: array{cards: list<array<string, mixed>>}}
     */
    public function toArray(): array
    {
        return [
            'type' => 'product_cards',
            'data' => [
                'cards' => $this->cards,
            ],
        ];
    }
}
