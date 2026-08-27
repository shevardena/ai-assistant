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
use App\Services\Ai\BotCapabilityService;
use Inertia\Testing\AssertableInertia as Assert;

function capabilityContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

function capabilityByKey(array $groups, string $key): array
{
    foreach ($groups as $group) {
        foreach ($group['capabilities'] as $capability) {
            if ($capability['key'] === $key) {
                return $capability;
            }
        }
    }

    throw new RuntimeException("Capability [$key] not found.");
}

function createCapabilityCatalog(Bot $bot, bool $displayable = true): Dataset
{
    $dataset = Dataset::factory()->ready()->create([
        'team_id' => $bot->team_id,
        'entity_type' => 'catalog',
        'name' => 'Products',
        'slug' => 'products',
    ]);

    DatasetField::factory()->create([
        'dataset_id' => $dataset->id,
        'key' => 'sku',
        'is_displayable' => $displayable,
    ]);
    BotDataset::factory()->create([
        'bot_id' => $bot->id,
        'dataset_id' => $dataset->id,
        'is_enabled' => true,
    ]);

    return $dataset;
}

function attachCapabilityOperation(Bot $bot, string $toolName, string $mode, array $settings = [], array $schema = []): BotApiOperation
{
    $dataSource = DataSource::factory()->ready()->create([
        'team_id' => $bot->team_id,
        'type' => 'rest_api',
        'name' => 'Commerce API',
    ]);
    $operation = ApiOperation::factory()->create([
        'data_source_id' => $dataSource->id,
        'execution_mode' => $mode,
        'request_schema' => $schema,
        'is_enabled' => true,
    ]);

    return BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => $toolName,
        'is_enabled' => true,
        'settings' => $settings,
    ]);
}

test('the capabilities page is tenant-isolated and returns grouped customer-safe data', function () {
    [$user, $team] = capabilityContext();
    $otherTeam = Team::factory()->create();
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $otherBot = Bot::factory()->create(['team_id' => $otherTeam->id]);

    $this->actingAs($user)
        ->get(route('bots.capabilities.show', ['current_team' => $team->slug, 'bot' => $bot]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bots/capabilities')
            ->has('groups', 3)
            ->where('bot.id', $bot->id)
            ->missing('groups.0.capabilities.0.details.dataset_id')
            ->missing('groups.1.capabilities.0.details.endpoint')
            ->missing('groups.1.capabilities.0.details.credentials'),
        );

    $this->actingAs($user)
        ->get(route('bots.capabilities.show', ['current_team' => $team->slug, 'bot' => $otherBot]))
        ->assertForbidden();
});

test('catalog and knowledge datasets produce ready data capabilities', function () {
    [$user, $team] = capabilityContext();
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    createCapabilityCatalog($bot);
    $faq = Dataset::factory()->ready()->create([
        'team_id' => $team->id,
        'entity_type' => 'faq',
        'name' => 'Help Center',
        'slug' => 'help-center',
    ]);
    DatasetField::factory()->create(['dataset_id' => $faq->id]);
    BotDataset::factory()->create(['bot_id' => $bot->id, 'dataset_id' => $faq->id]);

    $groups = app(BotCapabilityService::class)->forBot($bot);

    expect(capabilityByKey($groups, 'search_catalog')['status'])->toBe('ready')
        ->and(capabilityByKey($groups, 'get_product_details')['status'])->toBe('ready')
        ->and(capabilityByKey($groups, 'lookup_faq')['status'])->toBe('ready')
        ->and(capabilityByKey($groups, 'search_catalog')['details']['datasets'])->toBe([
            ['name' => 'Products', 'slug' => 'products'],
        ]);
});

test('missing dataset prerequisites are reported as unavailable', function () {
    [, $team] = capabilityContext();
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    $groups = app(BotCapabilityService::class)->forBot($bot);

    expect(capabilityByKey($groups, 'search_catalog')['status'])->toBe('unavailable')
        ->and(capabilityByKey($groups, 'lookup_faq')['status'])->toBe('unavailable');
});

test('a valid live catalog operation satisfies catalog readiness without a dataset', function () {
    [, $team] = capabilityContext();
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $attachment = attachCapabilityOperation(
        $bot,
        'search_catalog',
        ApiOperationMode::Read->value,
        [],
        ['properties' => ['q' => ['type' => 'string']]],
    );
    $attachment->apiOperation->update([
        'response_mapping' => [
            'output' => [
                'title' => ['path' => 'name'],
            ],
        ],
    ]);

    $capability = capabilityByKey(app(BotCapabilityService::class)->forBot($bot), 'search_catalog');

    expect($capability['status'])->toBe('ready')
        ->and($capability['details']['live'])->toBeTrue();
});

test('valid read operations are ready while disabled and write-mode stock attachments need configuration', function () {
    [, $team] = capabilityContext();
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    createCapabilityCatalog($bot);
    $settings = [
        'input_mapping' => [
            'product_reference' => [
                'source' => 'dataset_field',
                'dataset_field' => 'sku',
                'operation_argument' => 'sku',
            ],
        ],
    ];
    $attachment = attachCapabilityOperation(
        $bot,
        'check_stock',
        ApiOperationMode::Read->value,
        $settings,
        ['properties' => ['sku' => ['type' => 'string']], 'required' => ['sku']],
    );

    expect(capabilityByKey(app(BotCapabilityService::class)->forBot($bot), 'check_stock')['status'])->toBe('ready');

    $attachment->update(['is_enabled' => false]);
    expect(capabilityByKey(app(BotCapabilityService::class)->forBot($bot), 'check_stock')['status'])->toBe('disabled');

    $attachment->update(['is_enabled' => true]);
    $attachment->apiOperation->update(['execution_mode' => ApiOperationMode::Write->value]);
    expect(capabilityByKey(app(BotCapabilityService::class)->forBot($bot), 'check_stock')['status'])->toBe('needs_configuration');

    $attachment->apiOperation->update(['execution_mode' => ApiOperationMode::Read->value]);
    $attachment->update(['settings' => []]);
    expect(capabilityByKey(app(BotCapabilityService::class)->forBot($bot), 'check_stock')['status'])->toBe('needs_configuration');
});

test('valid write operations are ready and marked as confirmation-required', function () {
    [, $team] = capabilityContext();
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    attachCapabilityOperation(
        $bot,
        'capture_lead',
        ApiOperationMode::Write->value,
        [
            'input_mapping' => [
                'email' => [
                    'source' => 'model_input',
                    'model_input' => 'email',
                    'operation_argument' => 'email',
                ],
            ],
        ],
        [
            'properties' => ['email' => ['type' => 'string']],
            'required' => ['email'],
        ],
    );

    $captureLead = capabilityByKey(app(BotCapabilityService::class)->forBot($bot), 'capture_lead');

    expect($captureLead['status'])->toBe('ready')
        ->and($captureLead['requiresConfirmation'])->toBeTrue()
        ->and($captureLead['kind'])->toBe('action');

    $bot->botApiOperations()->firstOrFail()->apiOperation->update([
        'execution_mode' => ApiOperationMode::Read->value,
    ]);

    expect(capabilityByKey(app(BotCapabilityService::class)->forBot($bot), 'capture_lead')['status'])->toBe('needs_configuration');
});

test('support-only bots can expose order status without catalog data', function () {
    [, $team] = capabilityContext();
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    attachCapabilityOperation(
        $bot,
        'check_order_status',
        ApiOperationMode::Read->value,
        [
            'input_mapping' => [
                'order_reference' => [
                    'source' => 'model_input',
                    'model_input' => 'order_reference',
                    'operation_argument' => 'order_reference',
                ],
            ],
        ],
        [
            'properties' => ['order_reference' => ['type' => 'string']],
            'required' => ['order_reference'],
        ],
    );

    $groups = app(BotCapabilityService::class)->forBot($bot);

    expect(capabilityByKey($groups, 'check_order_status')['status'])->toBe('ready')
        ->and(capabilityByKey($groups, 'search_catalog')['status'])->toBe('unavailable');
});
