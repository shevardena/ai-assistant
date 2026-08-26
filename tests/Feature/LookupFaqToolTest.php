<?php

use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\BotDataset;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiSearchOrchestrator;
use App\Services\Ai\AiToolSchemaBuilder;
use App\Services\Ai\BotToolRegistry;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\Tools\LookupFaqTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;

/**
 * @return array{0: User, 1: Team, 2: Bot, 3: Dataset, 4: DatasetRecord}
 */
function lookupFaqContext(array $datasetOverrides = []): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $dataset = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'entity_type' => 'knowledge',
        ...$datasetOverrides,
    ]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'is_enabled' => true,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'question',
        'is_displayable' => true,
        'is_searchable' => true,
        'position' => 1,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'answer',
        'is_displayable' => true,
        'is_searchable' => true,
        'position' => 2,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'category',
        'is_displayable' => true,
        'position' => 3,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'internal_note',
        'is_displayable' => false,
        'position' => 4,
    ]);
    $record = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'faq-returns',
        'payload' => [
            'question' => 'What is your returns policy?',
            'answer' => 'Products may be returned within 30 days.',
            'category' => 'Orders',
            'internal_note' => 'Never expose this note.',
        ],
        'searchable_text' => 'What is your returns policy? Products may be returned within 30 days.',
        'is_active' => true,
    ]);

    return [$user, $team, $bot, $dataset, $record];
}

function executeLookupFaq(Bot $bot, array $arguments): ToolResult
{
    return app(LookupFaqTool::class)->execute(
        $bot,
        $arguments,
        ToolExecutionContext::forBot($bot),
    );
}

test('the registry exposes lookup_faq only for an attached ready knowledge dataset', function () {
    [, , $bot] = lookupFaqContext();
    $registry = app(BotToolRegistry::class);

    expect($registry->find($bot, 'lookup_faq'))->toBeInstanceOf(LookupFaqTool::class);

    [, , $botWithoutKnowledge] = lookupFaqContext(['entity_type' => 'product']);

    expect($registry->find($botWithoutKnowledge, 'lookup_faq'))->toBeNull();
});

test('lookup_faq has a strict query-only schema', function () {
    [, , $bot] = lookupFaqContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'lookup_faq');
    $schema = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($schema)->toMatchArray([
        'type' => 'function',
        'name' => 'lookup_faq',
        'strict' => true,
    ])
        ->and($schema['parameters'])->toBe([
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ]);
});

test('lookup_faq returns matching displayable knowledge content', function () {
    [, , $bot] = lookupFaqContext();

    $result = executeLookupFaq($bot, ['query' => 'returns']);

    expect($result->data)->toBe([
        'ok' => true,
        'results' => [[
            'question' => 'What is your returns policy?',
            'answer' => 'Products may be returned within 30 days.',
            'category' => 'Orders',
        ]],
    ])
        ->and($result->data['results'][0])->not->toHaveKey('internal_note')
        ->and($result->data['results'][0])->not->toHaveKey('id')
        ->and($result->data['results'][0])->not->toHaveKey('external_id');
});

test('lookup_faq does not search knowledge from another team or an unattached dataset', function () {
    [, $team, $bot, $dataset] = lookupFaqContext();

    $unattachedDataset = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'entity_type' => 'knowledge',
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $unattachedDataset->id,
        'key' => 'answer',
        'is_displayable' => true,
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $unattachedDataset->id,
        'payload' => ['answer' => 'Unattached answer.'],
        'searchable_text' => 'returns unattached',
        'is_active' => true,
    ]);

    $foreignTeam = Team::factory()->create();
    $foreignDataset = Dataset::factory()->ready()->create([
        'team_id' => $foreignTeam->id,
        'entity_type' => 'knowledge',
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $foreignDataset->id,
        'key' => 'answer',
        'is_displayable' => true,
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $foreignDataset->id,
        'payload' => ['answer' => 'Foreign answer.'],
        'searchable_text' => 'returns foreign',
        'is_active' => true,
    ]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $foreignDataset->id,
        'is_enabled' => true,
    ]);

    $result = executeLookupFaq($bot, ['query' => 'returns']);

    expect($result->data['results'])->toHaveCount(1)
        ->and($result->data['results'][0]['answer'])->toBe('Products may be returned within 30 days.')
        ->and($dataset->team_id)->toBe($team->id);
});

test('lookup_faq ignores disabled attachments and non-ready knowledge datasets', function () {
    [, , $bot, $dataset] = lookupFaqContext();
    $attachment = BotDataset::query()
        ->where('bot_id', $bot->id)
        ->where('dataset_id', $dataset->id)
        ->firstOrFail();

    $attachment->update(['is_enabled' => false]);

    expect(executeLookupFaq($bot, ['query' => 'returns'])->data['results'])->toBe([]);

    $attachment->update(['is_enabled' => true]);
    $dataset->update(['status' => 'preparing']);

    expect(executeLookupFaq($bot, ['query' => 'returns'])->data['results'])->toBe([]);
});

test('lookup_faq excludes inactive records and returns an empty result for no matches', function () {
    [, , $bot, , $record] = lookupFaqContext();
    $record->update(['is_active' => false]);

    expect(executeLookupFaq($bot, ['query' => 'returns'])->data)->toBe([
        'ok' => true,
        'results' => [],
    ])
        ->and(executeLookupFaq($bot, ['query' => 'does not exist'])->data)->toBe([
            'ok' => true,
            'results' => [],
        ]);
});

test('lookup_faq rejects invalid or unexpected arguments', function () {
    [, , $bot] = lookupFaqContext();

    foreach ([
        [],
        ['query' => ''],
        ['query' => str_repeat('x', 1001)],
        ['query' => 'returns', 'unexpected' => true],
    ] as $arguments) {
        expect(executeLookupFaq($bot, $arguments)->data)->toBe([
            'ok' => false,
            'error' => 'invalid_query',
            'message' => 'The FAQ query must be a non-empty string of 1000 characters or fewer.',
        ]);
    }
});

test('the generic runtime dispatches lookup_faq without a tool-specific branch', function () {
    [, , $bot] = lookupFaqContext();
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
                        'call_id' => 'faq-call',
                        'name' => 'lookup_faq',
                        'arguments' => json_encode(['query' => 'returns'], JSON_THROW_ON_ERROR),
                    ]],
                    'output_text' => null,
                    'usage' => null,
                ]
                : [
                    'output' => [],
                    'output_text' => 'The return policy is 30 days.',
                    'usage' => null,
                ];
        }
    };
    app()->instance(AiClient::class, $fake);

    $response = app(AiSearchOrchestrator::class)->run($bot, 'What is your return policy?');
    $functionOutput = collect($fake->payloads[1]['input'])
        ->first(fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output');

    expect($response->answer)->toBe('The return policy is 30 days.')
        ->and($response->toolCallsCount)->toBe(1)
        ->and(json_decode($functionOutput['output'], true, 512, JSON_THROW_ON_ERROR))->toBe([
            'ok' => true,
            'results' => [[
                'question' => 'What is your returns policy?',
                'answer' => 'Products may be returned within 30 days.',
                'category' => 'Orders',
            ]],
        ]);
});
