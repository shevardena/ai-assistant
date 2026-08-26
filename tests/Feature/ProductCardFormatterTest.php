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
use App\Services\Cards\ProductCardFormatter;

function productCardContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $dataset = Dataset::factory()->ready()->create(['team_id' => $team->id]);
    BotDataset::factory()->create(['bot_id' => $bot->id, 'dataset_id' => $dataset->id]);

    $fields = collect([
        ['key' => 'name', 'label' => 'Name', 'data_type' => 'string', 'semantic_type' => 'name'],
        ['key' => 'brand', 'label' => 'Brand', 'data_type' => 'string', 'semantic_type' => 'brand'],
        ['key' => 'price', 'label' => 'Price', 'data_type' => 'decimal', 'semantic_type' => 'price'],
        ['key' => 'old_price', 'label' => 'Old price', 'data_type' => 'decimal', 'semantic_type' => 'price'],
        ['key' => 'url', 'label' => 'URL', 'data_type' => 'url', 'semantic_type' => 'url'],
        ['key' => 'image', 'label' => 'Image', 'data_type' => 'url', 'semantic_type' => 'image'],
        ['key' => 'secret', 'label' => 'Secret', 'data_type' => 'string', 'is_displayable' => false],
    ])->map(fn (array $field, int $position): DatasetField => DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'position' => $position,
        ...$field,
    ]));

    $template = BotCardTemplate::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'is_default' => true,
        'layout' => [
            'card_style' => [
                'background_color' => '#f8fafc',
                'text_color' => '#111827',
                'muted_text_color' => '#64748b',
                'button_color' => '#2563eb',
                'button_text_color' => '#ffffff',
            ],
        ],
        'mapping' => [
            'title' => $fields->firstWhere('key', 'name')->id,
            'subtitle' => $fields->firstWhere('key', 'brand')->id,
            'price' => $fields->firstWhere('key', 'price')->id,
            'old_price' => $fields->firstWhere('key', 'old_price')->id,
            'url' => $fields->firstWhere('key', 'url')->id,
            'image' => $fields->firstWhere('key', 'image')->id,
            'button_label' => $fields->firstWhere('key', 'brand')->id,
            'secret' => $fields->firstWhere('key', 'secret')->id,
        ],
    ]);

    return [$user, $team, $bot, $dataset, $template, $fields];
}

test('formats a deterministic safe product card from displayable field IDs', function () {
    [, , $bot, $dataset, $template, $fields] = productCardContext();
    $record = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'sku-1',
        'payload' => [
            'name' => 'Laptop',
            'brand' => 'Mamos',
            'price' => 20,
            'old_price' => 25,
            'url' => 'https://example.com/products/1',
            'image' => 'javascript:alert(1)',
            'secret' => 'do not expose',
        ],
    ]);

    $card = app(ProductCardFormatter::class)->format($bot, $dataset, $record);

    expect($card)->not->toBeNull()
        ->and($card->toArray())->toMatchArray([
            'id' => 'sku-1',
            'title' => 'Laptop',
            'subtitle' => 'Mamos',
            'price' => 20,
            'old_price' => 25,
            'url' => 'https://example.com/products/1',
            'image' => null,
            'styles' => [
                'background_color' => '#f8fafc',
                'text_color' => '#111827',
                'muted_text_color' => '#64748b',
                'button_color' => '#2563eb',
                'button_text_color' => '#ffffff',
            ],
        ])
        ->and($card->toArray())->not->toHaveKey('secret')
        ->and($template->fresh()->mapping['title'])->toBe($fields->firstWhere('key', 'name')->id);
});

test('formats a product card with automatic defaults before a design is saved', function () {
    [, , $bot, $dataset, $template] = productCardContext();
    $template->delete();
    $record = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'sku-auto',
        'payload' => [
            'name' => 'Automatic product',
            'brand' => 'Mamos',
            'price' => 20,
            'old_price' => 25,
            'url' => 'https://example.com/products/auto',
            'image' => 'https://example.com/products/auto.jpg',
        ],
    ]);

    $card = app(ProductCardFormatter::class)->format($bot, $dataset, $record);

    expect($card?->toArray())->toMatchArray([
        'id' => 'sku-auto',
        'title' => 'Automatic product',
        'subtitle' => 'Mamos',
        'price' => 20,
        'old_price' => 25,
        'url' => 'https://example.com/products/auto',
        'image' => 'https://example.com/products/auto.jpg',
        'button_label' => 'View product',
    ])
        ->and(BotCardTemplate::query()->where('bot_id', $bot->id)->exists())->toBeFalse();
});

test('does not show an old price unless it is greater than the current price', function () {
    [, , $bot, $dataset] = productCardContext();
    $record = DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'payload' => ['name' => 'Laptop', 'price' => 20, 'old_price' => 20],
    ]);

    $card = app(ProductCardFormatter::class)->format($bot, $dataset, $record);

    expect($card?->oldPrice)->toBeNull();
});

test('caps cards and preserves unique encounter order across search sources', function () {
    config()->set('widget.max_result_cards', 6);
    [, , $bot, $dataset] = productCardContext();
    $records = collect(range(1, 7))->map(fn (int $number): DatasetRecord => DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'sku-'.$number,
        'payload' => ['name' => 'Product '.$number],
    ]));

    $cards = app(ProductCardFormatter::class)->formatSearchSources($bot, [[
        'dataset_id' => $dataset->id,
        'record_ids' => [$records[0]->id, $records[1]->id, $records[0]->id, ...$records->slice(2)->pluck('id')->all()],
    ]]);

    expect($cards)->toHaveCount(6)
        ->and(collect($cards)->pluck('id')->all())->toBe([
            'sku-1', 'sku-2', 'sku-3', 'sku-4', 'sku-5', 'sku-6',
        ]);
});

test('rejects records and datasets outside the bot team', function () {
    [, , $bot, $dataset] = productCardContext();
    $otherTeam = Team::factory()->create();
    $otherDataset = Dataset::factory()->ready()->create(['team_id' => $otherTeam->id]);
    $record = DatasetRecord::factory()->create(['dataset_id' => $otherDataset->id]);

    expect(app(ProductCardFormatter::class)->format($bot, $otherDataset, $record))->toBeNull()
        ->and(app(ProductCardFormatter::class)->format($bot, $dataset, $record))->toBeNull();
});
