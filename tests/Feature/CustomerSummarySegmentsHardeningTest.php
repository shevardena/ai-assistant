<?php

use App\Enums\AppointmentStatus;
use App\Enums\CustomerActivityType;
use App\Enums\CustomerStatus;
use App\Enums\LeadStatus;
use App\Enums\SupportTicketStatus;
use App\Enums\TeamRole;
use App\Models\Appointment;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Models\Lead;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Customers\CustomerCustomFieldService;
use App\Services\Customers\CustomerFactService;
use App\Services\Customers\CustomerIdentityService;
use App\Services\Customers\CustomerMergeService;
use App\Services\Customers\CustomerSegmentService;
use App\Services\Customers\CustomerService;
use App\Services\Customers\CustomerSummaryService;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function summarySegmentHardeningContext(TeamRole $role = TeamRole::Owner): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

final class SummaryHardeningClient implements AiClient
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public bool $fail = false;

    public function createResponse(array $payload): array
    {
        $this->payloads[] = $payload;

        if ($this->fail) {
            throw new AiException('Summary provider unavailable.');
        }

        return ['output' => [], 'output_text' => 'Customer summary generated.', 'usage' => null];
    }
}

test('summary is only invoked by the explicit action and preserves old data on failure', function (): void {
    [$user, $team] = summarySegmentHardeningContext();
    $customer = Customer::factory()->create(['team_id' => $team->id, 'email' => 'summary-a@example.com', 'ai_summary' => 'Previous summary', 'ai_summary_generated_at' => '2026-08-25 10:00:00', 'ai_summary_activity_at' => '2026-08-25 10:00:00']);
    Customer::factory()->create(['team_id' => $team->id, 'email' => 'summary-b@example.com']);
    $client = new SummaryHardeningClient;
    app()->instance(AiClient::class, $client);

    $this->actingAs($user)->get(route('customers.index', $team->slug))->assertSuccessful();
    $this->actingAs($user)->get(route('customers.show', [$team->slug, $customer]))->assertSuccessful();
    expect($client->payloads)->toBeEmpty();

    $beforeToolRuns = ToolRun::query()->count();
    $this->actingAs($user)->post(route('customers.summary.generate', [$team->slug, $customer]))->assertRedirect();
    expect($client->payloads)->toHaveCount(1)
        ->and($customer->fresh()->ai_summary)->toBe('Customer summary generated.')
        ->and($customer->fresh()->ai_summary_generated_at)->not->toBeNull()
        ->and(ToolRun::query()->count())->toBe($beforeToolRuns);

    $oldTimestamp = $customer->fresh()->ai_summary_generated_at;
    $client->fail = true;
    $this->actingAs($user)->post(route('customers.summary.generate', [$team->slug, $customer]))->assertSessionHasErrors('summary');
    expect($customer->fresh()->ai_summary)->toBe('Customer summary generated.')
        ->and($customer->fresh()->ai_summary_generated_at->equalTo($oldTimestamp))->toBeTrue()
        ->and(ToolRun::query()->count())->toBe($beforeToolRuns);
});

test('summary payload is restricted to the target customer team and linked data', function (): void {
    [$user, $team] = summarySegmentHardeningContext();
    $foreignTeam = Team::factory()->create();
    $target = Customer::factory()->create(['team_id' => $team->id, 'email' => 'target@example.com']);
    $sameTeam = Customer::factory()->create(['team_id' => $team->id, 'email' => 'same-team@example.com']);
    $foreign = Customer::factory()->create(['team_id' => $foreignTeam->id, 'email' => 'foreign@example.com']);
    $target->notes()->create(['team_id' => $team->id, 'user_id' => $user->id, 'body' => 'Target-only note']);
    $sameTeam->notes()->create(['team_id' => $team->id, 'user_id' => $user->id, 'body' => 'Other customer note']);
    $foreign->notes()->create(['team_id' => $foreignTeam->id, 'user_id' => $user->id, 'body' => 'Foreign customer note']);
    $client = new SummaryHardeningClient;
    app()->instance(AiClient::class, $client);

    app(CustomerSummaryService::class)->generate($team, $target, $user);
    $payload = $client->payloads[0];
    $input = $payload['input'][0]['content'];

    expect($input)->toContain('target@example.com', 'Target-only note')
        ->not->toContain('same-team@example.com', 'Other customer note', 'foreign@example.com', 'Foreign customer note')
        ->and($payload['tools'])->toBe([])
        ->and($payload['tool_choice'])->toBe('none');
});

test('summary stale state follows newer meaningful customer activity and clears after regeneration', function (): void {
    [, $team] = summarySegmentHardeningContext();
    $customer = Customer::factory()->create(['team_id' => $team->id, 'ai_summary' => 'Summary', 'ai_summary_generated_at' => '2026-08-25 10:00:00', 'ai_summary_activity_at' => '2026-08-25 10:00:00', 'last_activity_at' => '2026-08-25 11:00:00']);
    $service = app(CustomerSummaryService::class);

    expect($service->isStale($customer))->toBeTrue();
    $customer->update(['ai_summary_generated_at' => '2026-08-25 12:00:00', 'ai_summary_activity_at' => '2026-08-25 12:00:00', 'last_activity_at' => '2026-08-25 12:00:00']);
    expect($service->isStale($customer->fresh()))->toBeFalse();
});

test('CRM mutations do not create Bot ToolRuns', function (): void {
    [$user, $team] = summarySegmentHardeningContext();
    $source = Customer::factory()->create(['team_id' => $team->id]);
    $destination = Customer::factory()->create(['team_id' => $team->id]);
    $identity = app(CustomerIdentityService::class)->add($team, $source, ['type' => 'email', 'value' => 'toolrun-check@example.com'], $user);
    $before = ToolRun::query()->count();

    app(CustomerIdentityService::class)->setPrimary($team, $source, $identity, $user);
    $field = app(CustomerCustomFieldService::class)->create($team, ['key' => 'toolrun_check', 'label' => 'ToolRun check', 'type' => 'text'], $user);
    app(CustomerCustomFieldService::class)->saveValues($team, $source, [$field->key => 'value'], $user);
    app(CustomerFactService::class)->save($team, $source, ['key' => 'toolrun_check', 'value' => 'value'], $user);
    $segment = app(CustomerSegmentService::class)->create($team, ['name' => 'ToolRun check', 'filter_definition' => ['filters' => []]], $user);
    app(CustomerSegmentService::class)->update($team, $segment, ['filter_definition' => ['filters' => [['field' => 'status', 'operator' => 'equals', 'value' => CustomerStatus::New->value]]]]);
    app(CustomerMergeService::class)->merge($team, $source, $destination, $user);

    expect(ToolRun::query()->count())->toBe($before);
});

test('segments whitelist filters, isolate references, and return correct database results', function (): void {
    [$owner, $team] = summarySegmentHardeningContext();
    $foreignTeam = Team::factory()->create();
    $foreignOwner = User::factory()->create();
    $foreignTeam->members()->attach($foreignOwner, ['role' => TeamRole::Owner->value]);
    $tag = CustomerTag::factory()->create(['team_id' => $team->id, 'name' => 'VIP']);
    $foreignTag = CustomerTag::factory()->create(['team_id' => $foreignTeam->id, 'name' => 'Foreign']);
    $foreignField = app(CustomerCustomFieldService::class)->create($foreignTeam, ['key' => 'foreign_only', 'label' => 'Foreign only', 'type' => 'text']);
    $field = app(CustomerCustomFieldService::class)->create($team, ['key' => 'score', 'label' => 'Score', 'type' => 'number']);
    $dateField = app(CustomerCustomFieldService::class)->create($team, ['key' => 'renewal_date', 'label' => 'Renewal date', 'type' => 'date']);
    $match = Customer::factory()->create(['team_id' => $team->id, 'status' => CustomerStatus::Qualified->value, 'owner_id' => $owner->id, 'phone' => null, 'normalized_phone' => null, 'last_activity_at' => '2026-08-25 11:00:00']);
    $other = Customer::factory()->create(['team_id' => $team->id, 'status' => CustomerStatus::New->value, 'owner_id' => $owner->id, 'last_activity_at' => '2026-08-20 11:00:00']);
    $foreign = Customer::factory()->create(['team_id' => $foreignTeam->id, 'status' => CustomerStatus::Qualified->value, 'owner_id' => $foreignOwner->id]);
    $match->tags()->attach($tag);
    app(CustomerCustomFieldService::class)->saveValues($team, $match, [$field->key => 42, $dateField->key => '2026-09-01']);
    app(CustomerCustomFieldService::class)->saveValues($team, $other, [$field->key => 10, $dateField->key => '2026-08-01']);
    $service = app(CustomerSegmentService::class);

    $definition = ['filters' => [
        ['field' => 'status', 'operator' => 'equals', 'value' => 'qualified'],
        ['field' => 'owner_id', 'operator' => 'equals', 'value' => $owner->id],
        ['field' => 'tag', 'operator' => 'equals', 'value' => $tag->id],
        ['field' => 'last_activity_at', 'operator' => 'after', 'value' => '2026-08-25 00:00:00'],
        ['field' => 'phone_exists', 'operator' => 'equals', 'value' => false],
        ['field' => 'custom_field', 'key' => 'score', 'operator' => 'gte', 'value' => 40],
        ['field' => 'custom_field', 'key' => 'renewal_date', 'operator' => 'gt', 'value' => '2026-08-15'],
    ]];

    expect($service->query($team, $service->normalizeDefinition($team, $definition))->pluck('id')->all())->toBe([$match->id]);
    $idDefinition = $service->normalizeDefinition($team, ['filters' => [['field' => 'custom_field', 'id' => $field->id, 'operator' => 'gte', 'value' => 40]]]);
    expect($idDefinition['filters'][0]['key'])->toBe('score')
        ->and($service->query($team, $idDefinition)->pluck('id')->all())->toBe([$match->id]);
    expect($foreign->id)->not->toBe($match->id);
    expect(fn (): array => $service->normalizeDefinition($team, ['filters' => [['field' => 'unknown', 'operator' => 'equals', 'value' => 'x']]]))->toThrow(ValidationException::class)
        ->and(fn (): array => $service->normalizeDefinition($team, ['filters' => [['field' => 'status', 'operator' => 'whereRaw', 'value' => '1=1']]]))->toThrow(ValidationException::class)
        ->and(fn (): array => $service->normalizeDefinition($team, ['filters' => [['field' => 'status', 'operator' => 'equals', 'value' => ['sql' => '1=1']]]]))->toThrow(ValidationException::class)
        ->and(fn (): array => $service->normalizeDefinition($team, ['filters' => [['field' => 'tag', 'operator' => 'equals', 'value' => $foreignTag->id]]]))->toThrow(ValidationException::class)
        ->and(fn (): array => $service->normalizeDefinition($team, ['filters' => [['field' => 'owner_id', 'operator' => 'equals', 'value' => $foreignOwner->id]]]))->toThrow(ValidationException::class)
        ->and(fn (): array => $service->normalizeDefinition($team, ['filters' => [['field' => 'custom_field', 'id' => $foreignField->id, 'operator' => 'equals', 'value' => 'x']]]))->toThrow(ValidationException::class);

    $segment = $service->create($team, ['name' => 'Qualified VIP', 'filter_definition' => $definition]);
    expect($service->index($team)[0]->getAttribute('matching_count'))->toBe(1)
        ->and($service->query($team, $segment->filter_definition)->paginate(1)->total())->toBe(1);
});

test('customer profile metrics and timeline remain team scoped and include CRM activity types', function (): void {
    [$user, $team] = summarySegmentHardeningContext();
    $foreignTeam = Team::factory()->create();
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id]);
    $toolRun = ToolRun::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id]);
    $foreignToolRun = ToolRun::factory()->create(['team_id' => $foreignTeam->id, 'bot_id' => $foreignBot->id]);
    $customer = Customer::factory()->create(['team_id' => $team->id, 'email' => 'metrics@example.com', 'phone' => null, 'normalized_phone' => null]);
    $foreign = Customer::factory()->create(['team_id' => $foreignTeam->id]);
    Conversation::factory()->create(['bot_id' => $bot->id, 'customer_id' => $customer->id]);
    Conversation::factory()->create(['bot_id' => $foreignBot->id, 'customer_id' => $foreign->id]);
    Lead::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'tool_run_id' => $toolRun->id, 'customer_id' => $customer->id, 'status' => LeadStatus::Qualified->value]);
    Appointment::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'tool_run_id' => $toolRun->id, 'customer_id' => $customer->id, 'status' => AppointmentStatus::Scheduled->value, 'starts_at' => now()->addDay()]);
    SupportTicket::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'tool_run_id' => $toolRun->id, 'customer_id' => $customer->id, 'status' => SupportTicketStatus::Open->value]);
    SupportTicket::factory()->create(['team_id' => $foreignTeam->id, 'bot_id' => $foreignBot->id, 'tool_run_id' => $foreignToolRun->id, 'customer_id' => $foreign->id, 'status' => SupportTicketStatus::Open->value]);
    $tag = CustomerTag::factory()->create(['team_id' => $team->id]);
    $field = app(CustomerCustomFieldService::class)->create($team, ['key' => 'segment', 'label' => 'Segment', 'type' => 'text']);
    app(CustomerCustomFieldService::class)->saveValues($team, $customer, [$field->key => 'VIP'], $user);
    app(CustomerFactService::class)->save($team, $customer, ['key' => 'lifecycle', 'value' => 'active'], $user);
    app(CustomerIdentityService::class)->add($team, $customer, ['type' => 'email', 'value' => 'secondary-metrics@example.com'], $user);
    app(CustomerService::class)->syncTags($team, $customer, [$tag->id]);
    app(CustomerService::class)->update($team, $customer, ['email' => $customer->email, 'phone' => null, 'status' => CustomerStatus::Active->value, 'owner_id' => $user->id, 'tags' => [$tag->id], 'custom_fields' => [$field->key => 'VIP']]);
    $detail = app(CustomerService::class)->detail($team, $customer)['customer'];
    $types = collect($detail['timeline'])->pluck('type');

    expect($detail['counts'])->toMatchArray(['conversations' => 1, 'leads' => 1, 'appointments' => 1, 'supportTickets' => 1, 'openTickets' => 1, 'upcomingAppointments' => 1])
        ->and($types)->toContain(CustomerActivityType::IdentityAdded->value, CustomerActivityType::FactChanged->value, CustomerActivityType::CustomFieldChanged->value, CustomerActivityType::TagChanged->value, CustomerActivityType::StatusChanged->value, CustomerActivityType::OwnerChanged->value)
        ->and(collect($detail['timeline'])->pluck('description')->implode(' '))->not->toContain('safe_arguments', 'payload');
});

test('V2 route rendering serializes realistic props and mutations require manage permission', function (): void {
    [$owner, $team] = summarySegmentHardeningContext();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $destination = Customer::factory()->create(['team_id' => $team->id]);
    $this->actingAs($owner)->get(route('customers.index', $team->slug))->assertInertia(fn (Assert $page): Assert => $page->component('customers/index')->has('customers.data'));
    $this->actingAs($owner)->get(route('customers.show', [$team->slug, $customer]))->assertInertia(fn (Assert $page): Assert => $page->component('customers/show')->where('customer.id', $customer->id)->has('customer.identities'));
    $this->actingAs($owner)->get(route('customer-fields.index', $team->slug))->assertInertia(fn (Assert $page): Assert => $page->component('customers/fields')->has('fields'));
    $this->actingAs($owner)->get(route('customer-segments.index', $team->slug))->assertInertia(fn (Assert $page): Assert => $page->component('customers/segments')->has('segments'));
    $this->actingAs($owner)->get(route('customers.merge.preview', [$team->slug, $customer, $destination]))->assertInertia(fn (Assert $page): Assert => $page->component('customers/merge')->has('preview.conflicts'));

    [$analyst, $analystTeam] = summarySegmentHardeningContext(TeamRole::Analyst);
    $analystCustomer = Customer::factory()->create(['team_id' => $analystTeam->id]);
    $analystDestination = Customer::factory()->create(['team_id' => $analystTeam->id]);
    foreach ([
        ['method' => 'post', 'route' => 'customers.identities.store', 'args' => [$analystTeam->slug, $analystCustomer], 'data' => ['type' => 'email', 'value' => 'blocked@example.com']],
        ['method' => 'post', 'route' => 'customers.facts.store', 'args' => [$analystTeam->slug, $analystCustomer], 'data' => ['key' => 'blocked', 'value' => 'blocked']],
        ['method' => 'post', 'route' => 'customers.summary.generate', 'args' => [$analystTeam->slug, $analystCustomer], 'data' => []],
        ['method' => 'post', 'route' => 'customer-fields.store', 'args' => [$analystTeam->slug], 'data' => ['key' => 'blocked', 'label' => 'Blocked', 'type' => 'text']],
        ['method' => 'post', 'route' => 'customer-segments.store', 'args' => [$analystTeam->slug], 'data' => ['name' => 'Blocked', 'filter_definition' => ['filters' => []]]],
        ['method' => 'post', 'route' => 'customers.merge', 'args' => [$analystTeam->slug, $analystCustomer], 'data' => ['destination_id' => $analystDestination->id]],
    ] as $mutation) {
        $this->actingAs($analyst)->{$mutation['method']}(route($mutation['route'], $mutation['args']), $mutation['data'])->assertForbidden();
    }
});
