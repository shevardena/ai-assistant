<?php

use App\Enums\ApiOperationMode;
use App\Enums\TeamRole;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\BotDataset;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DataSource;
use App\Models\Team;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Ai\BotToolRegistry;
use App\Services\Onboarding\BusinessTemplateRegistry;
use App\Services\Onboarding\BusinessTemplateSetupService;
use App\Services\Onboarding\OnboardingChecklistService;
use Inertia\Testing\AssertableInertia as Assert;

function businessTemplateContext(TeamRole $role = TeamRole::Owner): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

test('the registry exposes the supported templates and trusted capability references', function (): void {
    $registry = app(BusinessTemplateRegistry::class);
    $templates = $registry->all();
    $keys = array_map(static fn ($template): string => $template->key, $templates);
    $knownTools = app(BotToolRegistry::class)->knownToolNames();
    $capabilityKeys = [];

    foreach ($templates as $template) {
        $capabilityKeys = [...$capabilityKeys, ...$template->recommendedCapabilities, ...$template->optionalCapabilities];
    }

    expect($keys)->toBe([
        'ecommerce',
        'car_dealership',
        'real_estate',
        'hotel',
        'clinic',
        'restaurant',
        'saas_support',
    ])
        ->and(array_unique($keys))->toHaveCount(count($keys))
        ->and(array_diff(array_unique($capabilityKeys), $knownTools))->toBe([]);
});

test('authorized members can create a draft Bot from a server-resolved template', function (): void {
    [$user, $team] = businessTemplateContext();
    $foreignTeam = Team::factory()->create();

    $response = $this->actingAs($user)->post(route('onboarding.apply', $team->slug), [
        'template_key' => 'ecommerce',
        'bot_name' => 'Shop Assistant',
        'team_id' => $foreignTeam->id,
        'recommended_capabilities' => ['fabricated_tool'],
    ]);

    $bot = Bot::query()->where('team_id', $team->id)->firstOrFail();

    $response->assertRedirect(route('bots.setup.show', [$team->slug, $bot]));

    expect($bot->name)->toBe('Shop Assistant')
        ->and($bot->business_template)->toBe('ecommerce')
        ->and($bot->status)->toBe('draft')
        ->and($bot->team_id)->toBe($team->id)
        ->and(Dataset::query()->count())->toBe(0)
        ->and(ApiOperation::query()->count())->toBe(0)
        ->and(BotApiOperation::query()->count())->toBe(0)
        ->and(Workflow::query()->count())->toBe(0);
});

test('invalid templates are rejected and foreign teams cannot apply one', function (): void {
    [$user, $team] = businessTemplateContext();
    $foreignTeam = Team::factory()->create();

    $this->actingAs($user)
        ->from(route('onboarding.index', $team->slug))
        ->post(route('onboarding.apply', $team->slug), ['template_key' => 'not-real'])
        ->assertSessionHasErrors('template_key');

    $this->actingAs($user)
        ->post(route('onboarding.apply', $foreignTeam->slug), ['template_key' => 'ecommerce'])
        ->assertForbidden();
});

test('analysts and support agents cannot create Bots from templates', function (TeamRole $role): void {
    [$user, $team] = businessTemplateContext($role);

    $this->actingAs($user)
        ->post(route('onboarding.apply', $team->slug), ['template_key' => 'ecommerce'])
        ->assertForbidden();
})->with([
    'analyst' => TeamRole::Analyst,
    'support agent' => TeamRole::SupportAgent,
]);

test('the onboarding page exposes safe template metadata and the setup page is tenant scoped', function (): void {
    [$user, $team] = businessTemplateContext();
    $bot = Bot::factory()->create([
        'team_id' => $team->id,
        'business_template' => 'ecommerce',
    ]);
    $foreignTeam = Team::factory()->create();
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id, 'business_template' => 'ecommerce']);

    $this->actingAs($user)
        ->get(route('onboarding.index', $team->slug))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/index')
            ->has('templates', 7)
            ->where('templates.0.key', 'ecommerce')
            ->missing('templates.0.credentials')
            ->missing('templates.0.apiSecrets'));

    $this->actingAs($user)
        ->get(route('bots.setup.show', [$team->slug, $bot]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bots/setup')
            ->where('bot.businessTemplate', 'ecommerce')
            ->has('checklist.steps')
            ->missing('checklist.credentials')
            ->missing('checklist.capabilities.0.details.endpoint'));

    $this->actingAs($user)
        ->get(route('bots.setup.show', [$team->slug, $foreignBot]))
        ->assertNotFound();
});

test('checklist completion follows actual Bot setup state', function (): void {
    [, $team] = businessTemplateContext();
    $bot = Bot::factory()->create([
        'team_id' => $team->id,
        'business_template' => 'ecommerce',
        'appearance' => ['primary_color' => '#000000'],
    ]);

    $initial = app(OnboardingChecklistService::class)->forBot(
        $bot,
        app(BusinessTemplateRegistry::class)->get('ecommerce'),
    );

    expect(collect($initial['steps'])->firstWhere('key', 'data')['completed'])->toBeFalse()
        ->and(collect($initial['steps'])->firstWhere('key', 'design')['completed'])->toBeTrue()
        ->and(collect($initial['steps'])->firstWhere('key', 'embed')['completed'])->toBeTrue();

    $bot->testScenarios()->create([
        'team_id' => $team->id,
        'name' => 'Product question',
        'input_message' => 'Show me a product.',
        'expectations' => [],
        'is_enabled' => true,
    ]);

    $complete = app(OnboardingChecklistService::class)->forBot(
        $bot->fresh(),
        app(BusinessTemplateRegistry::class)->get('ecommerce'),
    );

    expect(collect($complete['steps'])->firstWhere('key', 'tests')['completed'])->toBeTrue();
});

test('template setup readiness uses attached team datasets and leaves optional integrations non-blocking', function (): void {
    [, $team] = businessTemplateContext();
    $bot = Bot::factory()->create([
        'team_id' => $team->id,
        'business_template' => 'ecommerce',
    ]);
    $dataset = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'name' => 'Products catalog',
        'slug' => 'products-catalog',
        'entity_type' => 'catalog',
    ]);
    DatasetField::factory()->create(['dataset_id' => $dataset->id]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'is_enabled' => true,
    ]);

    $setup = app(BusinessTemplateSetupService::class)->forBot(
        $bot->fresh(),
        app(BusinessTemplateRegistry::class)->get('ecommerce'),
    );
    $requirements = collect($setup['requirements'])->keyBy('key');

    expect($requirements['products']['status'])->toBe('ready')
        ->and($requirements['orders']['status'])->toBe('not_configured')
        ->and($requirements['cart']['importance'])->toBe('optional')
        ->and($setup['progress']['launchReady'])->toBeTrue()
        ->and($setup['progress']['percentage'])->toBe(100);
});

test('a valid check order operation makes the orders requirement ready without importing order history', function (): void {
    [, $team] = businessTemplateContext();
    $bot = Bot::factory()->create([
        'team_id' => $team->id,
        'business_template' => 'ecommerce',
    ]);
    $dataSource = DataSource::factory()->ready()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://api.example.test'],
    ]);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $dataSource->id,
        'key' => 'check_order_status',
        'name' => 'Check order status',
        'execution_mode' => ApiOperationMode::Read->value,
        'method' => 'GET',
        'path' => '/orders/{order_reference}',
        'request_schema' => [
            'type' => 'object',
            'properties' => ['order_reference' => ['type' => 'string']],
            'required' => ['order_reference'],
            'additionalProperties' => false,
        ],
        'request_mapping' => ['path' => ['order_reference' => 'order_reference']],
    ]);
    BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'check_order_status',
        'settings' => [
            'input_mapping' => [
                'order_reference' => [
                    'source' => 'model_input',
                    'model_input' => 'order_reference',
                    'operation_argument' => 'order_reference',
                ],
            ],
        ],
    ]);

    $setup = app(BusinessTemplateSetupService::class)->forBot(
        $bot->fresh(),
        app(BusinessTemplateRegistry::class)->get('ecommerce'),
    );
    $requirements = collect($setup['requirements'])->keyBy('key');

    expect($requirements['orders']['status'])->toBe('ready')
        ->and($requirements['orders']['setup']['type'])->toBe('configure_live_api')
        ->and($requirements['orders']['setup']['url'])->toContain('data-sources');
});
