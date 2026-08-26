<?php

use App\Enums\CustomerActivityType;
use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerFact;
use App\Models\CustomerIdentity;
use App\Models\CustomerTag;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Customers\CustomerCustomFieldService;
use App\Services\Customers\CustomerIdentityResolutionService;
use App\Services\Customers\CustomerIdentityService;
use App\Services\Customers\CustomerMergeService;
use App\Services\Customers\CustomerSegmentService;
use App\Services\Customers\CustomerSummaryService;
use Illuminate\Validation\ValidationException;

function customerV2Context(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

test('customer identities are team scoped and support secondary and primary values', function (): void {
    [, $team] = customerV2Context();
    $customer = Customer::factory()->create(['team_id' => $team->id, 'email' => 'primary@example.com', 'phone' => null, 'normalized_phone' => null]);
    $service = app(CustomerIdentityService::class);

    $secondary = $service->add($team, $customer, ['type' => 'email', 'value' => 'secondary@example.com'], null);
    $service->setPrimary($team, $customer, $secondary);
    $channel = $service->add($team, $customer, ['type' => 'channel_user', 'value' => 'tg-123', 'provider' => 'telegram', 'provider_external_id' => 'tg-123'], null);

    expect($customer->fresh()->email)->toBe('secondary@example.com')
        ->and(CustomerIdentity::query()->where('customer_id', $customer->id)->count())->toBe(3)
        ->and($channel->provider)->toBe('telegram')
        ->and(app(CustomerIdentityResolutionService::class)->resolve($team, ['type' => 'channel_user', 'provider' => 'telegram', 'provider_external_id' => 'tg-123'])->customer?->is($customer))->toBeTrue();
});

test('custom fields facts and segments use typed values and safe team filters', function (): void {
    [, $team] = customerV2Context();
    $customer = Customer::factory()->create(['team_id' => $team->id, 'status' => 'qualified']);
    $other = Customer::factory()->create(['team_id' => $team->id, 'status' => 'new']);
    $field = app(CustomerCustomFieldService::class)->create($team, ['key' => 'lifecycle_score', 'label' => 'Lifecycle score', 'type' => 'number']);
    app(CustomerCustomFieldService::class)->saveValues($team, $customer, ['lifecycle_score' => '42']);
    app(CustomerCustomFieldService::class)->saveValues($team, $other, ['lifecycle_score' => '10']);
    CustomerFact::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'key' => 'plan', 'value' => 'pro']);
    $segment = app(CustomerSegmentService::class)->create($team, ['name' => 'Qualified', 'filter_definition' => ['filters' => [['field' => 'status', 'operator' => 'equals', 'value' => 'qualified']]]]);

    expect($field->type)->toBe('number')
        ->and($customer->customFieldValues()->first()->value_number)->toBe('42.000000')
        ->and(app(CustomerSegmentService::class)->query($team, $segment->filter_definition)->pluck('id')->all())->toBe([$customer->id]);

    expect(fn (): array => app(CustomerSegmentService::class)->normalizeDefinition($team, ['filters' => [['field' => 'email', 'operator' => 'equals', 'value' => 'x']]]))->toThrow(ValidationException::class);
});

test('customer merge is transactional, reassigns linked data, preserves destination conflicts, and redirects the source', function (): void {
    [$user, $team] = customerV2Context();
    $source = Customer::factory()->create(['team_id' => $team->id, 'email' => 'source@example.com']);
    $destination = Customer::factory()->create(['team_id' => $team->id, 'email' => 'destination@example.com']);
    $tag = CustomerTag::factory()->create(['team_id' => $team->id, 'name' => 'VIP']);
    $source->tags()->attach($tag);
    CustomerFact::factory()->create(['team_id' => $team->id, 'customer_id' => $source->id, 'key' => 'plan', 'value' => 'pro']);
    CustomerFact::factory()->create(['team_id' => $team->id, 'customer_id' => $destination->id, 'key' => 'plan', 'value' => 'enterprise']);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    Conversation::factory()->create(['bot_id' => $bot->id, 'customer_id' => $source->id]);
    $source->notes()->create(['team_id' => $team->id, 'user_id' => $user->id, 'body' => 'Keep this note']);

    $merged = app(CustomerMergeService::class)->merge($team, $source, $destination, $user);

    expect($merged->is($destination))->toBeTrue()
        ->and($source->fresh()->merged_into_customer_id)->toBe($destination->id)
        ->and($destination->fresh()->conversations()->count())->toBe(1)
        ->and($destination->fresh()->notes()->where('body', 'Keep this note')->exists())->toBeTrue()
        ->and($destination->fresh()->tags()->whereKey($tag->id)->exists())->toBeTrue()
        ->and($destination->fresh()->facts()->where('key', 'plan')->value('value'))->toBe('enterprise')
        ->and($destination->fresh()->activities()->where('type', CustomerActivityType::Merged->value)->exists())->toBeTrue();

    $this->actingAs($user)->get(route('customers.show', [$team->slug, $source]))->assertRedirect(route('customers.show', [$team->slug, $destination]));
});

test('customer summaries are explicit, target scoped, and do not create tool runs', function (): void {
    [, $team] = customerV2Context();
    $customer = Customer::factory()->create(['team_id' => $team->id, 'email' => 'summary@example.com']);
    $other = Customer::factory()->create(['team_id' => $team->id, 'email' => 'other@example.com']);
    $client = new class implements AiClient
    {
        /** @var list<array<string, mixed>> */
        public array $payloads = [];

        public function createResponse(array $payload): array
        {
            $this->payloads[] = $payload;

            return ['output' => [], 'output_text' => 'A concise customer summary.', 'usage' => null];
        }
    };
    app()->instance(AiClient::class, $client);

    $before = ToolRun::query()->count();
    app(CustomerSummaryService::class)->generate($team, $customer);
    $input = $client->payloads[0]['input'][0]['content'];

    expect($customer->fresh()->ai_summary)->toBe('A concise customer summary.')
        ->and($customer->fresh()->ai_summary_generated_at)->not->toBeNull()
        ->and($before)->toBe(ToolRun::query()->count())
        ->and($input)->toContain('summary@example.com')
        ->and($input)->not->toContain('other@example.com')
        ->and($customer->fresh()->activities()->where('type', CustomerActivityType::SummaryGenerated->value)->exists())->toBeTrue();
});
