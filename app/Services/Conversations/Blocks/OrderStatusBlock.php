<?php

namespace App\Services\Conversations\Blocks;

final readonly class OrderStatusBlock implements ConversationBlock
{
    public const MAX_FIELDS = 16;

    public const MAX_STATUS_LENGTH = 120;

    public const MAX_KEY_LENGTH = 255;

    public const MAX_LABEL_LENGTH = 255;

    public const MAX_VALUE_STRING_LENGTH = 2000;

    /**
     * @param  list<array{key: string, label: string, value: int|float|string|bool|null}>  $fields
     */
    public function __construct(
        public ?string $status,
        public array $fields,
    ) {}

    public function type(): string
    {
        return ConversationBlockType::OrderStatus->value;
    }

    /**
     * @return array{type: 'order_status', data: array{status?: string, fields: list<array{key: string, label: string, value: int|float|string|bool|null}>}}
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->status !== null) {
            $data['status'] = $this->status;
        }

        $data['fields'] = $this->fields;

        return [
            'type' => ConversationBlockType::OrderStatus->value,
            'data' => $data,
        ];
    }
}
