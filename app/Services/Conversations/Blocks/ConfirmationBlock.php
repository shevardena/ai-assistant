<?php

namespace App\Services\Conversations\Blocks;

final readonly class ConfirmationBlock implements ConversationBlock
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public string $actionReference,
        public string $summary,
        public ConfirmationBlockStatus $status,
        public array $result = [],
    ) {}

    public function type(): string
    {
        return ConversationBlockType::Confirmation->value;
    }

    /**
     * @return array{type: 'confirmation', data: array{action_reference: string, summary: string, status: string, result?: array<string, mixed>}}
     */
    public function toArray(): array
    {
        $data = [
            'action_reference' => $this->actionReference,
            'summary' => $this->summary,
            'status' => $this->status->value,
        ];

        if ($this->result !== []) {
            $data['result'] = $this->result;
        }

        return [
            'type' => ConversationBlockType::Confirmation->value,
            'data' => $data,
        ];
    }
}
