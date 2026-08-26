<?php

use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\BotCardTemplate;
use App\Models\BotDataset;
use App\Models\Conversation;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\Contracts\AiClient;

function productCardResponseContext(bool $withTemplate = true): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $dataset = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'slug' => 'products',
    ]);
    BotDataset::factory()->create(['bot_id' => $bot->id, 'dataset_id' => $dataset->id]);
    $field = DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'name',
        'is_searchable' => true,
        'is_displayable' => true,
    ]);

    if ($withTemplate) {
        BotCardTemplate::factory()->create([
            'bot_id' => $bot->id,
            'dataset_id' => $dataset->id,
            'mapping' => ['title' => $field->id],
        ]);
    }

    return [$user, $team, $bot, $dataset];
}

function productCardResponseFake(array $responses): AiClient
{
    return new class($responses) implements AiClient
    {
        public function __construct(private array $responses) {}

        public function createResponse(array $payload): array
        {
            return array_shift($this->responses) ?? [
                'output' => [],
                'output_text' => 'No response.',
                'usage' => null,
            ];
        }
    };
}

function productCardSearchCall(string $text): array
{
    return [
        'type' => 'function_call',
        'call_id' => fake()->uuid(),
        'name' => 'search_catalog',
        'arguments' => json_encode([
            'dataset' => 'products',
            'text' => $text,
            'filters' => [],
            'sorts' => [],
            'limit' => 10,
        ], JSON_THROW_ON_ERROR),
    ];
}

test('search responses contain deterministic cards and persist the safe snapshot', function () {
    [$user, $team, $bot, $dataset] = productCardResponseContext();

    foreach (range(1, 3) as $number) {
        DatasetRecord::factory()->create([
            'dataset_id' => $dataset->id,
            'external_id' => 'sku-'.$number,
            'payload' => ['name' => 'Product '.$number],
            'searchable_text' => 'Product '.$number,
        ]);
    }

    app()->instance(AiClient::class, productCardResponseFake([
        ['output' => [productCardSearchCall('Product')], 'output_text' => null, 'usage' => null],
        ['output' => [], 'output_text' => 'Here are the products.', 'usage' => null],
    ]));

    $response = $this->actingAs($user)
        ->postJson(route('bots.ai.test', ['current_team' => $team->slug, 'bot' => $bot]), [
            'message' => 'Show products',
        ])
        ->assertOk()
        ->assertJsonPath('blocks.0.type', 'product_cards')
        ->assertJsonPath('cards.0.title', 'Product 3');

    $metadata = Message::query()->where('role', 'assistant')->firstOrFail()->metadata;

    expect($response->json('cards'))->toHaveCount(3)
        ->and($metadata)->not->toHaveKey('cards')
        ->and($metadata['blocks'][0]['type'])->toBe('product_cards')
        ->and($metadata['blocks'][0]['data']['cards'])->toHaveCount(3)
        ->and(Conversation::query()->where('bot_id', $bot->id)->count())->toBe(1);
});

test('direct answers and empty searches never fabricate cards', function () {
    [$user, $team, $bot] = productCardResponseContext();
    app()->instance(AiClient::class, productCardResponseFake([
        ['output' => [], 'output_text' => 'A direct answer.', 'usage' => null],
    ]));

    $this->actingAs($user)
        ->postJson(route('bots.ai.test', ['current_team' => $team->slug, 'bot' => $bot]), [
            'message' => 'Hello',
        ])
        ->assertOk()
        ->assertJsonPath('cards', []);

    app()->instance(AiClient::class, productCardResponseFake([
        ['output' => [productCardSearchCall('does-not-exist')], 'output_text' => null, 'usage' => null],
        ['output' => [], 'output_text' => 'Nothing found.', 'usage' => null],
    ]));

    $this->actingAs($user)
        ->postJson(route('bots.ai.test', ['current_team' => $team->slug, 'bot' => $bot]), [
            'message' => 'Find nothing',
        ])
        ->assertOk()
        ->assertJsonPath('cards', []);
});
