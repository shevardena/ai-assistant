<?php

namespace App\Services\Ai\Tools\Contracts;

use App\Models\Bot;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;

interface ConfirmableBotTool
{
    public function confirm(Bot $bot, ToolExecutionContext $context, string $actionReference): ToolResult;
}
