<?php

use App\Enums\CustomerStatus;
use App\Enums\TeamRole;
use App\Models\Appointment;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\CustomerTag;
use App\Models\Lead;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\User;
use App\Services\Customers\CustomerIdentityResolutionService;
use App\Services\Customers\CustomerService;
use Carbon\CarbonImmutable;

function customerTeamContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

test('identity resolution is exact, normalized, and team scoped', function (): void {
    [, $team] = customerTeamContext();
    $customer = Customer::factory()->create(['team_id' => $team->id, 'email' => 'John@Example.com', 'phone' => '+995 555 123 456']);
    $otherTeam = Team::factory()->create();
    $other = Customer::factory()->create(['team_id' => $otherTeam->id, 'email' => 'john@example.com']);
    $service = app(CustomerIdentityResolutionService::class);

    expect($service->resolve($team, ['email' => ' john@example.com '])->customer?->is($customer))->toBeTrue()
        ->and($service->resolve($team, ['phone' => '995-555-123-456'])->customer?->is($customer))->toBeTrue()
        ->and(Customer::query()->where('team_id', $team->id)->count())->toBe(1)
        ->and($service->resolve($otherTeam, ['email' => 'JOHN@example.com'])->customer?->is($other))->toBeTrue()
        ->and(Customer::query()->where('normalized_email', 'john@example.com')->count())->toBe(2);
});

test('name alone never merges and insufficient anonymous identity remains unresolved', function (): void {
    [, $team] = customerTeamContext();
    $existing = Customer::factory()->create(['team_id' => $team->id, 'display_name' => 'Jane Doe', 'email' => 'jane@example.com']);
    $service = app(CustomerIdentityResolutionService::class);

    expect($service->resolve($team, ['name' => 'Jane Doe'])->customer)->toBeNull()
        ->and($service->resolve($team, [])->customer)->toBeNull()
        ->and($service->resolve($team, ['name' => 'Jane Doe'], true)->customer?->is($existing))->toBeFalse()
        ->and(Customer::query()->where('team_id', $team->id)->count())->toBe(2);
});

test('conflicting email and phone identities do not merge', function (): void {
    [, $team] = customerTeamContext();
    Customer::factory()->create(['team_id' => $team->id, 'email' => 'one@example.com', 'phone' => null]);
    Customer::factory()->create(['team_id' => $team->id, 'email' => null, 'phone' => '+995 555 000 111']);
    $resolution = app(CustomerIdentityResolutionService::class)->resolve($team, ['email' => 'one@example.com', 'phone' => '+995 555 000 111']);

    expect($resolution->customer)->toBeNull()->and($resolution->conflict)->toBeTrue();
});

test('customer management is team scoped and validates owners and tags', function (): void {
    [$user, $team] = customerTeamContext();
    $tag = CustomerTag::factory()->create(['team_id' => $team->id, 'name' => 'VIP']);
    $customer = Customer::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)->patch(route('customers.update', [$team->slug, $customer]), [
        'first_name' => 'Updated', 'last_name' => 'Customer', 'email' => $customer->email,
        'phone' => $customer->phone, 'company' => 'Acme', 'status' => CustomerStatus::Qualified->value,
        'owner_id' => $user->id, 'tags' => [$tag->id],
    ])->assertRedirect();

    expect($customer->fresh()->status)->toBe(CustomerStatus::Qualified)
        ->and($customer->fresh()->tags)->toHaveCount(1);
});

test('foreign customer is not reachable through the current team route', function (): void {
    [$user, $team] = customerTeamContext();
    $foreignTeam = Team::factory()->create();
    $foreignCustomer = Customer::factory()->create(['team_id' => $foreignTeam->id]);

    $this->actingAs($user)->get(route('customers.show', [$team->slug, $foreignCustomer]))->assertNotFound();
});

test('notes record the team customer and author without creating tool runs', function (): void {
    [$user, $team] = customerTeamContext();
    $customer = Customer::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)->post(route('customers.notes.store', [$team->slug, $customer]), ['body' => 'Prefers email.'])->assertRedirect();

    expect($customer->fresh()->notes()->first()->user_id)->toBe($user->id)
        ->and($customer->fresh()->last_activity_at)->not->toBeNull();
});

test('customer relationships remain nullable for existing records', function (): void {
    $team = Team::factory()->create();
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Nullable relationships bot']);

    $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'customer_id' => null]);
    $lead = Lead::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'customer_id' => null]);
    $appointment = Appointment::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'customer_id' => null]);
    $ticket = SupportTicket::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'customer_id' => null]);

    expect($conversation->customer_id)->toBeNull()
        ->and($lead->customer_id)->toBeNull()
        ->and($appointment->customer_id)->toBeNull()
        ->and($ticket->customer_id)->toBeNull();
});

test('customer owner and tags are team scoped and can be cleared', function (): void {
    [$user, $team] = customerTeamContext();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $tag = CustomerTag::factory()->create(['team_id' => $team->id, 'name' => 'VIP']);
    $foreignTeam = Team::factory()->create();
    $foreignUser = User::factory()->create();
    $foreignTeam->members()->attach($foreignUser, ['role' => TeamRole::Owner->value]);
    $foreignTag = CustomerTag::factory()->create(['team_id' => $foreignTeam->id, 'name' => 'Foreign']);

    $this->actingAs($user)->patch(route('customers.update', [$team->slug, $customer]), [
        'status' => CustomerStatus::Active->value,
        'owner_id' => $user->id,
        'tags' => [$tag->id],
    ])->assertRedirect();

    expect($customer->fresh()->owner_id)->toBe($user->id)
        ->and($customer->fresh()->tags->pluck('id')->all())->toBe([$tag->id]);

    $this->actingAs($user)->patch(route('customers.update', [$team->slug, $customer]), [
        'status' => CustomerStatus::Active->value,
        'owner_id' => null,
        'tags' => [],
    ])->assertRedirect();

    expect($customer->fresh()->owner_id)->toBeNull()
        ->and($customer->fresh()->tags)->toHaveCount(0);

    $this->actingAs($user)->patch(route('customers.update', [$team->slug, $customer]), [
        'status' => CustomerStatus::Active->value,
        'owner_id' => $foreignUser->id,
        'tags' => [$foreignTag->id],
    ])->assertSessionHasErrors(['owner_id']);
});

test('customer list tag filtering is team scoped', function (): void {
    [, $team] = customerTeamContext();
    $tag = CustomerTag::factory()->create(['team_id' => $team->id]);
    $tagged = Customer::factory()->create(['team_id' => $team->id]);
    $untagged = Customer::factory()->create(['team_id' => $team->id]);
    $tagged->tags()->attach($tag);
    CustomerTag::factory()->create(['team_id' => Team::factory()->create()->id]);

    $customers = app(CustomerService::class)->index($team, ['tag' => $tag->id])['customers'];

    expect($customers->getCollection()->pluck('id')->all())->toBe([$tagged->id])
        ->and($customers->getCollection()->pluck('id')->all())->not->toContain($untagged->id);
});

test('customer isolation covers foreign edits and notes', function (): void {
    [$user, $team] = customerTeamContext();
    $foreignTeam = Team::factory()->create();
    $foreignCustomer = Customer::factory()->create(['team_id' => $foreignTeam->id]);

    $this->actingAs($user)->patch(route('customers.update', [$team->slug, $foreignCustomer]), [
        'status' => CustomerStatus::Qualified->value,
    ])->assertNotFound();

    $this->actingAs($user)->post(route('customers.notes.store', [$team->slug, $foreignCustomer]), [
        'body' => 'Should not be written.',
    ])->assertNotFound();

    expect(CustomerNote::query()->where('customer_id', $foreignCustomer->id)->count())->toBe(0);
});

test('customer timeline contains only linked activity in deterministic order', function (): void {
    [, $team] = customerTeamContext();
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Timeline bot']);
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $foreignCustomer = Customer::factory()->create(['team_id' => Team::factory()->create()->id]);
    $base = CarbonImmutable::parse('2026-08-25 10:00:00');

    Conversation::factory()->create(['bot_id' => $bot->id, 'customer_id' => $customer->id, 'created_at' => $base->subMinutes(4)]);
    Lead::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'customer_id' => $customer->id, 'name' => 'Qualified lead', 'created_at' => $base->subMinutes(3)]);
    Appointment::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'customer_id' => $customer->id, 'created_at' => $base->subMinutes(2)]);
    SupportTicket::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'customer_id' => $customer->id, 'subject' => 'Printer issue', 'created_at' => $base->subMinute()]);
    CustomerNote::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'body' => 'Internal only.', 'created_at' => $base]);
    Lead::factory()->create(['team_id' => $foreignCustomer->team_id, 'customer_id' => $foreignCustomer->id, 'name' => 'Foreign lead']);

    $timeline = app(CustomerService::class)->detail($team, $customer)['customer']['timeline'];

    expect(collect($timeline)->pluck('type')->all())->toBe(['note', 'ticket', 'appointment', 'lead', 'conversation'])
        ->and(collect($timeline)->pluck('description')->all())->not->toContain('Foreign lead')
        ->and(collect($timeline)->first())->not->toHaveKeys(['safe_arguments', 'safe_result', 'payload']);
});
