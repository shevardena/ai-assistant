<?php

use App\Enums\ApiOperationMode;
use App\Enums\TeamRole;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\BotCardTemplate;
use App\Models\BotDataset;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Models\DataSource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

function botCardTemplateContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $dataset = Dataset::factory()->ready()->create(['team_id' => $team->id]);
    BotDataset::factory()->create(['bot_id' => $bot->id, 'dataset_id' => $dataset->id]);
    $title = DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'name',
        'is_displayable' => true,
    ]);
    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'internal_note',
        'is_displayable' => false,
    ]);
    DatasetRecord::factory()->create([
        'dataset_id' => $dataset->id,
        'external_id' => 'sample-product',
        'payload' => ['name' => 'Galaxy A55', 'internal_note' => 'Do not expose'],
        'searchable_text' => 'Galaxy A55',
        'is_active' => true,
    ]);

    return [$user, $team, $bot, $dataset, $title];
}

test('bot design exposes only attached dataset displayable fields and saves a template', function () {
    [$user, $team, $bot, $dataset, $title] = botCardTemplateContext();

    $this->actingAs($user)
        ->get(route('bots.design.edit', ['current_team' => $team->slug, 'bot' => $bot]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('bots/design')
            ->has('datasets', 1)
            ->where('datasets.0.fields.0.id', $title->id)
            ->where('datasets.0.sample.id', 'sample-product')
            ->where('datasets.0.sample.values.name', 'Galaxy A55')
            ->where('datasets.0.sample.values', fn (Collection $values): bool => ! $values->has('internal_note')));

    $this->actingAs($user)
        ->patch(route('bots.design.update', ['current_team' => $team->slug, 'bot' => $bot]), [
            'appearance' => [
                'widget_title' => 'Shop assistant',
                'input_placeholder' => 'Find a product',
                'primary_color' => '#112233',
                'accent_color' => '#fefefe',
                'background_color' => '#ffffff',
                'text_color' => '#171717',
                'send_button_color' => '#334455',
                'user_message_color' => '#556677',
                'user_message_text_color' => '#ffffff',
                'launcher_position' => 'bottom-left',
            ],
            'dataset_id' => $dataset->id,
            'mapping' => ['title' => $title->id],
            'button_label' => 'See product',
            'card_style' => [
                'background_color' => '#f8fafc',
                'text_color' => '#111827',
                'muted_text_color' => '#64748b',
                'button_color' => '#2563eb',
                'button_text_color' => '#ffffff',
            ],
        ])
        ->assertRedirect();

    expect(BotCardTemplate::query()->where('bot_id', $bot->id)->where('dataset_id', $dataset->id)->value('mapping'))
        ->toBe(['title' => $title->id])
        ->and(BotCardTemplate::query()->where('bot_id', $bot->id)->where('dataset_id', $dataset->id)->value('layout'))
        ->toMatchArray([
            'button_label' => 'See product',
            'card_style' => [
                'background_color' => '#f8fafc',
                'text_color' => '#111827',
                'muted_text_color' => '#64748b',
                'button_color' => '#2563eb',
                'button_text_color' => '#ffffff',
            ],
        ])
        ->and($bot->fresh()->appearance)->toMatchArray(['launcher_position' => 'bottom-left']);
});

test('bot design exposes attached live operations', function () {
    [$user, $team, $bot] = botCardTemplateContext();
    $dataSource = DataSource::factory()->ready()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
    ]);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $dataSource->id,
        'execution_mode' => ApiOperationMode::Read->value,
        'response_mapping' => ['output' => ['name' => ['path' => 'name']]],
    ]);
    BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'search_catalog',
    ]);

    $this->actingAs($user)
        ->get(route('bots.design.edit', ['current_team' => $team->slug, 'bot' => $bot]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('bots/design')
            ->where('liveOperations.0.id', $operation->id)
            ->where('liveOperations.0.name', $operation->name));
});

test('bot design rejects a cross-dataset field mapping', function () {
    [$user, $team, $bot, $dataset] = botCardTemplateContext();
    $otherTeam = Team::factory()->create();
    $otherDataset = Dataset::factory()->ready()->create(['team_id' => $otherTeam->id]);
    $foreignField = DatasetField::factory()->create(['dataset_id' => $otherDataset->id]);

    $this->actingAs($user)
        ->from(route('bots.design.edit', ['current_team' => $team->slug, 'bot' => $bot]))
        ->patch(route('bots.design.update', ['current_team' => $team->slug, 'bot' => $bot]), [
            'appearance' => [
                'primary_color' => '#112233',
                'accent_color' => '#fefefe',
                'launcher_position' => 'bottom-right',
            ],
            'dataset_id' => $dataset->id,
            'mapping' => ['title' => $foreignField->id],
        ])
        ->assertSessionHasErrors('mapping');

    expect(BotCardTemplate::query()->where('bot_id', $bot->id)->exists())->toBeFalse();
});

test('bot design stores public assistant identity and a validated avatar', function () {
    [$user, $team, $bot] = botCardTemplateContext();
    Storage::fake('public');

    $this->actingAs($user)
        ->patch(route('bots.design.update', ['current_team' => $team->slug, 'bot' => $bot]), [
            'appearance' => [
                'widget_title' => 'GoParts',
                'assistant_display_name' => 'Ana',
                'assistant_subtitle' => 'AI Shopping Assistant',
                'primary_color' => '#112233',
                'accent_color' => '#fefefe',
                'launcher_position' => 'bottom-right',
            ],
            'welcome_message' => 'Hi! I can help you find a product.',
            'assistant_avatar' => UploadedFile::fake()->image('ana.png'),
        ])
        ->assertRedirect();

    $appearance = $bot->fresh()->appearance;

    expect($appearance)->toMatchArray([
        'assistant_display_name' => 'Ana',
        'assistant_subtitle' => 'AI Shopping Assistant',
    ])
        ->and($bot->fresh()->welcome_message)
        ->toBe('Hi! I can help you find a product.');

    Storage::disk('public')->assertExists($appearance['assistant_avatar_path']);
});
