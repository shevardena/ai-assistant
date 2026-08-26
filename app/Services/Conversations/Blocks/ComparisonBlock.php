<?php

namespace App\Services\Conversations\Blocks;

final readonly class ComparisonBlock implements ConversationBlock
{
    public const MAX_ITEMS = 5;

    public const MAX_FIELDS = 24;

    public const MAX_REFERENCE_LENGTH = 255;

    public const MAX_LABEL_LENGTH = 255;

    public const MAX_VALUE_STRING_LENGTH = 2000;

    /**
     * @param  list<array{product_reference: string, label: string}>  $items
     * @param  list<array{key: string, label: string, values: list<int|float|string|bool|null>}>  $fields
     */
    public function __construct(
        public array $items,
        public array $fields,
    ) {}

    public function type(): string
    {
        return ConversationBlockType::Comparison->value;
    }

    /**
     * @return array{type: 'comparison', data: array{items: list<array{product_reference: string, label: string}>, fields: list<array{key: string, label: string, values: list<int|float|string|bool|null>}>}}
     */
    public function toArray(): array
    {
        return [
            'type' => ConversationBlockType::Comparison->value,
            'data' => [
                'items' => $this->items,
                'fields' => $this->fields,
            ],
        ];
    }
}
