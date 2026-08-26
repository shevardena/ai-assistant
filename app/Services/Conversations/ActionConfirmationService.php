<?php

namespace App\Services\Conversations;

use App\Models\Bot;
use App\Services\Ai\BotToolRegistry;
use App\Services\Ai\Tools\Contracts\ConfirmableBotTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Ai\WriteActionManager;

final class ActionConfirmationService
{
    public function __construct(
        private readonly BotToolRegistry $toolRegistry,
        private readonly WriteActionManager $actionManager,
    ) {}

    public function confirm(Bot $bot, ToolExecutionContext $context, string $actionReference): ToolResult
    {
        $run = $this->actionManager->scopedRun($bot, $context, $actionReference);

        if ($run === null) {
            return ToolResult::failure(
                'action_not_available',
                'This action is not available in the current conversation.',
            );
        }

        $tool = $this->toolRegistry->findForAction($bot, $run->tool_name);

        if ($tool instanceof ConfirmableBotTool) {
            return $tool->confirm($bot, $context, $actionReference);
        }

        return $this->actionManager->confirm($bot, $context, $actionReference);
    }

    public function cancel(Bot $bot, ToolExecutionContext $context, string $actionReference): ToolResult
    {
        return $this->actionManager->cancel($bot, $context, $actionReference);
    }
}
