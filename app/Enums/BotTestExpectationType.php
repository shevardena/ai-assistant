<?php

namespace App\Enums;

enum BotTestExpectationType: string
{
    case ToolCalled = 'tool_called';
    case ToolNotCalled = 'tool_not_called';
    case ResponseContains = 'response_contains';
    case ResponseNotContains = 'response_not_contains';
    case BlockPresent = 'block_present';
    case BlockAbsent = 'block_absent';
    case ActionStatus = 'action_status';
}
