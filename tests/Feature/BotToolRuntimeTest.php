<?php

use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\BotDataset;
use App\Models\Dataset;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiSearchOrchestrator;
use App\Services\Ai\AiToolSchemaBuilder;
use App\Services\Ai\BotToolRegistry;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\Tools\ToolResult;

function botToolRegistryContext(bool $attachDataset = true): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    if ($attachDataset) {
        $dataset = Dataset::factory()->ready()->create(['team_id' => $team->id]);
        BotDataset::factory()->create([
            'bot_id' => $bot->id,
            'dataset_id' => $dataset->id,
            'is_enabled' => true,
        ]);
    }

    return [$user, $team, $bot];
}

test('the registry resolves the dataset-backed tools for an eligible bot', function () {
    [, , $bot] = botToolRegistryContext();
    $registry = app(BotToolRegistry::class);

    expect($registry->forBot($bot))->toHaveCount(3)
        ->and($registry->find($bot, 'search_catalog'))->not->toBeNull()
        ->and($registry->find($bot, 'get_product_details'))->not->toBeNull()
        ->and($registry->find($bot, 'request_human_handoff'))->not->toBeNull();

    [, , $botWithoutDataset] = botToolRegistryContext(false);

    expect($registry->forBot($botWithoutDataset))->toHaveCount(1)
        ->and($registry->find($botWithoutDataset, 'search_catalog'))->toBeNull()
        ->and($registry->find($botWithoutDataset, 'request_human_handoff'))->not->toBeNull();
});

test('the registered tool produces a strict schema without internal implementation details', function () {
    [, , $bot] = botToolRegistryContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'search_catalog');
    $definition = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($definition)->toMatchArray([
        'type' => 'function',
        'name' => 'search_catalog',
        'strict' => true,
    ])
        ->and($definition['parameters']['additionalProperties'])->toBeFalse()
        ->and(json_encode($definition, JSON_THROW_ON_ERROR))
        ->not->toContain('source_path')
        ->not->toContain('credentials')
        ->not->toContain('endpoint');
});

test('unknown model tool calls are rejected through generic registry dispatch', function () {
    [, , $bot] = botToolRegistryContext();
    $fake = new class implements AiClient
    {
        /** @var list<array<string, mixed>> */
        public array $payloads = [];

        public function createResponse(array $payload): array
        {
            $this->payloads[] = $payload;

            return count($this->payloads) === 1
                ? [
                    'output' => [[
                        'type' => 'function_call',
                        'call_id' => 'unknown-call',
                        'name' => 'lookup_faq',
                        'arguments' => '{}',
                    ]],
                    'output_text' => null,
                    'usage' => null,
                ]
                : [
                    'output' => [],
                    'output_text' => 'I cannot use that tool.',
                    'usage' => null,
                ];
        }
    };
    app()->instance(AiClient::class, $fake);

    $response = app(AiSearchOrchestrator::class)->run($bot, 'Show details.');

    expect($response->answer)->toBe('I cannot use that tool.')
        ->and($response->toolCallsCount)->toBe(1)
        ->and($fake->payloads[1]['input'])->toContain([
            'type' => 'function_call_output',
            'call_id' => 'unknown-call',
            'output' => json_encode([
                'ok' => false,
                'error' => 'unsupported_tool',
                'message' => 'The requested tool is not available.',
            ], JSON_THROW_ON_ERROR),
        ]);
});

test('tool results keep model data separate from internal metadata', function () {
    $result = ToolResult::success(
        ['ok' => true, 'search' => ['count' => 1]],
        ['card_source' => ['dataset_id' => 5, 'record_ids' => [9]]],
    );

    expect($result->modelData())->toBe([
        'ok' => true,
        'search' => ['count' => 1],
    ])
        ->and($result->metadata)->toBe([
            'card_source' => ['dataset_id' => 5, 'record_ids' => [9]],
        ]);
});
