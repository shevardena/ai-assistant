<?php

namespace App\Services\Ai\Tools;

use App\Services\Conversations\Blocks\ConfirmationBlock;
use App\Services\Conversations\Blocks\ConfirmationBlockStatus;

final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     * @param  list<array<string, mixed>>  $blocks
     */
    public function __construct(
        public array $data,
        public array $metadata = [],
        public array $blocks = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function modelData(): array
    {
        return $this->data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     * @param  list<array<string, mixed>>  $blocks
     */
    public static function success(array $data, array $metadata = [], array $blocks = []): self
    {
        return new self($data, $metadata, $blocks);
    }

    public static function failure(string $error, string $message): self
    {
        return new self([
            'ok' => false,
            'error' => $error,
            'message' => $message,
        ]);
    }

    public static function requiresConfirmation(string $actionReference, string $summary): self
    {
        return new self(
            [
                'ok' => false,
                'requires_confirmation' => true,
                'action_reference' => $actionReference,
                'summary' => $summary,
            ],
            [],
            [(new ConfirmationBlock(
                actionReference: $actionReference,
                summary: $summary,
                status: ConfirmationBlockStatus::Pending,
            ))->toArray()],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    public function withBlocks(array $blocks): self
    {
        return new self($this->data, $this->metadata, [...$blocks, ...$this->blocks]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function withConfirmationResult(array $result): self
    {
        $blocks = array_map(function (array $block) use ($result): array {
            if (($block['type'] ?? null) !== 'confirmation'
                || ! is_array($block['data'] ?? null)
                || ($block['data']['status'] ?? null) !== 'completed') {
                return $block;
            }

            $block['data']['result'] = $result;

            return $block;
        }, $this->blocks);

        return new self($this->data, $this->metadata, $blocks);
    }
}
