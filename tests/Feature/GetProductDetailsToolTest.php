<?php

use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\BotCardTemplate;
use App\Models\BotDataset;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiToolSchemaBuilder;
use App\Services\Ai\BotToolRegistry;
use App\Services\Ai\Tools\GetProductDetailsTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;

function getProductDetailsContext(): array
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
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'is_enabled' => true,
    ]);
    $title = DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'name',
        'label' => 'Name',
        'is_displayable' => true,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'brand',
        'label' => 'Brand',
        'is_displayable' => true,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'internal_note',
        'label' => 'Internal note',
        'is_displayable' => false,
    ]);
    $record = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'sku-1',
        'payload' => [
            'name' => 'Gaming Laptop',
            'brand' => 'Mamos',
            'internal_note' => 'Do not expose',
        ],
        'is_active' => true,
    ]);

    return [$user, $team, $bot, $dataset, $record, $title];
}

function executeProductDetails(Bot $bot, array $arguments): ToolResult
{
    return app(GetProductDetailsTool::class)->execute(
        $bot,
        $arguments,
        ToolExecutionContext::forBot($bot),
    );
}

test('the tool has a strict product reference schema', function () {
    [, , $bot] = getProductDetailsContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'get_product_details');
    $schema = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($schema)->toMatchArray([
        'type' => 'function',
        'name' => 'get_product_details',
        'strict' => true,
    ])
        ->and($schema['parameters']['required'])->toBe(['product_reference'])
        ->and($schema['parameters']['additionalProperties'])->toBeFalse()
        ->and($schema['parameters']['properties'])->toBe([
            'product_reference' => ['type' => 'string'],
        ]);
});

test('it returns only displayable details for an active authorized product', function () {
    [, , $bot] = getProductDetailsContext();

    $result = executeProductDetails($bot, ['product_reference' => 'sku-1']);

    expect($result->data)->toMatchArray([
        'ok' => true,
        'product' => [
            'reference' => 'sku-1',
            'name' => 'Gaming Laptop',
            'brand' => 'Mamos',
        ],
    ])
        ->and($result->data['product'])->not->toHaveKey('internal_note');
});

test('it reuses the existing product card formatter when a card template exists', function () {
    [, , $bot, $dataset, $record, $title] = getProductDetailsContext();
    BotCardTemplate::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'mapping' => ['title' => $title->id],
    ]);

    $result = executeProductDetails($bot, ['product_reference' => $record->external_id]);

    expect($result->metadata)->toMatchArray([
        'card_source' => [
            'dataset_id' => $dataset->id,
            'record_ids' => [$record->id],
        ],
    ]);
});

test('it does not resolve a product from another team or an unattached dataset', function () {
    [, $team, $bot, $dataset] = getProductDetailsContext();
    $foreignTeam = Team::factory()->create();
    $foreignDataset = Dataset::factory()->ready()->create(['team_id' => $foreignTeam->id]);
    $foreignRecord = DatasetRecord::factory()->create([
        'dataset_id' => $foreignDataset->id,
        'external_id' => 'foreign-sku',
    ]);
    $unattachedRecord = DatasetRecord::factory()->create([
        'dataset_id' => Dataset::factory()->ready()->create(['team_id' => $team->id])->id,
        'external_id' => 'unattached-sku',
    ]);

    expect(executeProductDetails($bot, ['product_reference' => $foreignRecord->external_id])->data)
        ->toMatchArray(['ok' => false, 'error' => 'not_found'])
        ->and(executeProductDetails($bot, ['product_reference' => $unattachedRecord->external_id])->data)
        ->toMatchArray(['ok' => false, 'error' => 'not_found'])
        ->and($dataset->team_id)->toBe($team->id);
});

test('it rejects disabled attachments and inactive records', function () {
    [, , $bot, $dataset, $record] = getProductDetailsContext();

    BotDataset::query()
        ->where('bot_id', $bot->id)
        ->where('dataset_id', $dataset->id)
        ->update(['is_enabled' => false]);

    expect(executeProductDetails($bot, ['product_reference' => $record->external_id])->data)
        ->toMatchArray(['ok' => false, 'error' => 'not_found']);

    BotDataset::query()
        ->where('bot_id', $bot->id)
        ->where('dataset_id', $dataset->id)
        ->update(['is_enabled' => true]);
    $record->update(['is_active' => false]);

    expect(executeProductDetails($bot, ['product_reference' => $record->external_id])->data)
        ->toMatchArray(['ok' => false, 'error' => 'not_found']);
});

test('invalid references return a safe not-found result', function () {
    [, , $bot] = getProductDetailsContext();

    foreach ([[], ['product_reference' => ''], ['product_reference' => "\x01bad"]] as $arguments) {
        expect(executeProductDetails($bot, $arguments)->data)
            ->toMatchArray([
                'ok' => false,
                'error' => 'not_found',
                'message' => 'The requested product could not be found.',
            ]);
    }
});
