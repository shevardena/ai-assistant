<?php

namespace App\Services\Ai\Tools;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Conversations\ConversationHandoffService;

final class RequestHumanHandoffTool implements BotTool
{
    public function __construct(private readonly ConversationHandoffService $handoffService) {}

    public function name(): string
    {
        return 'request_human_handoff';
    }

    public function description(): string
    {
        return 'Ask a human Team member to join when the customer clearly requests human assistance. Use only for an explicit customer request or a confirmed runtime escalation.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(Bot $bot): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'enum' => ['customer_requested', 'runtime_escalation'],
                ],
            ],
            'required' => ['reason'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
    {
        if ((int) $context->bot->id !== (int) $bot->id
            || (int) $context->team->id !== (int) $bot->team_id
            || ! $context->conversation instanceof Conversation) {
            return ToolResult::failure(
                'invalid_context',
                'Human handoff is not available in this context.',
            );
        }

        if (array_diff(array_keys($arguments), ['reason']) !== []
            || ! is_string($arguments['reason'] ?? null)
            || ! in_array($arguments['reason'], ['customer_requested', 'runtime_escalation'], true)) {
            return ToolResult::failure(
                'invalid_arguments',
                'Use a supported human handoff reason.',
            );
        }

        if ($context->isTest()) {
            return ToolResult::success([
                'ok' => true,
                'test_mode' => true,
                'handoff_status' => 'ai',
                'message' => 'Test mode simulated human handoff.',
            ]);
        }

        $requested = $this->handoffService->request(
            $context->team,
            $context->conversation,
            $arguments['reason'],
        );

        if (! $requested) {
            return ToolResult::success([
                'ok' => false,
                'handoff_status' => 'ai',
                'message' => 'Human handoff is unavailable in preview.',
            ]);
        }

        return ToolResult::success([
            'ok' => true,
            'handoff_status' => 'requested',
            'message' => ConversationHandoffService::REQUESTED_MESSAGE,
        ]);
    }
}
