<?php

namespace App\Services\Ai\Tools\Contracts;

use App\Models\Bot;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;

interface BotTool
{
    public function name(): string;

    public function description(): string;

    /**
     * Return the strict model-facing parameters schema.
     *
     * @return array<string, mixed>
     */
    public function schema(Bot $bot): array;

    /**
     * Execute the tool through Laravel-owned authorization and services.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult;
}
