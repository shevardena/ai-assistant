<?php

namespace App\Services\Ai;

use App\Models\Bot;
use App\Services\Ai\Tools\Contracts\BotTool;

class AiToolSchemaBuilder
{
    /**
     * Convert a registered BotTool into an OpenAI Responses function definition.
     *
     * @return array<string, mixed>
     */
    public function build(BotTool $tool, Bot $bot): array
    {
        return [
            'type' => 'function',
            'name' => $tool->name(),
            'description' => $tool->description(),
            'strict' => true,
            'parameters' => $tool->schema($bot),
        ];
    }
}
