<?php

use App\Enums\CustomerActivityType;
use App\Enums\CustomerFactSource;
use App\Enums\TeamRole;
use App\Models\Customer;
use App\Models\CustomerFact;
use App\Models\Team;
use App\Models\User;
use App\Services\Customers\CustomerFactService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

function customerFactsHardeningContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

test('facts create, edit, delete, preserve source metadata, and remain team scoped', function (): void {
    [$user, $team] = customerFactsHardeningContext();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $service = app(CustomerFactService::class);

    $fact = $service->save($team, $customer, ['key' => 'lifecycle_stage', 'value' => 'qualified', 'value_type' => 'text', 'source' => CustomerFactSource::Imported->value, 'confidence' => 0.875], $user);
    $edited = $service->save($team, $customer, ['key' => 'lifecycle_stage', 'value' => 'customer', 'value_type' => 'text', 'source' => CustomerFactSource::Conversation->value, 'confidence' => 0.95], $user);

    expect($edited->id)->toBe($fact->id)
        ->and($edited->key)->toBe('lifecycle_stage')
        ->and($edited->value)->toBe('customer')
        ->and($edited->source)->toBe(CustomerFactSource::Conversation->value)
        ->and((float) $edited->confidence)->toBe(0.95)
        ->and($customer->fresh()->activities()->where('type', CustomerActivityType::FactChanged->value)->count())->toBe(2);

    $foreignTeam = Team::factory()->create();
    $foreignCustomer = Customer::factory()->create(['team_id' => $foreignTeam->id]);
    $foreignFact = CustomerFact::factory()->create(['team_id' => $foreignTeam->id, 'customer_id' => $foreignCustomer->id, 'key' => 'lifecycle_stage']);

    expect(function () use ($service, $team, $customer, $foreignFact, $user): void {
        $service->delete($team, $customer, $foreignFact, $user);
    })->toThrow(ModelNotFoundException::class);

    $service->delete($team, $customer, $edited, $user);

    expect(CustomerFact::query()->whereKey($edited->id)->exists())->toBeFalse()
        ->and(CustomerFact::query()->whereKey($foreignFact->id)->exists())->toBeTrue();
});

test('fact keys, values, source, and confidence are validated by the service', function (): void {
    [, $team] = customerFactsHardeningContext();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $service = app(CustomerFactService::class);

    expect(fn (): CustomerFact => $service->save($team, $customer, ['key' => 'bad key', 'value' => 'value']))->toThrow(ValidationException::class)
        ->and(fn (): CustomerFact => $service->save($team, $customer, ['key' => 'valid_key', 'value' => 'value', 'source' => 'forged']))->toThrow(ValidationException::class)
        ->and(fn (): CustomerFact => $service->save($team, $customer, ['key' => 'valid_key', 'value' => str_repeat('x', 2001)]))->toThrow(ValidationException::class)
        ->and(fn (): CustomerFact => $service->save($team, $customer, ['key' => 'valid_key', 'value' => 'value', 'confidence' => 1.1]))->toThrow(ValidationException::class);
});

test('customer facts are behind authenticated CRM routes and not part of public customer serialization', function (): void {
    [, $team] = customerFactsHardeningContext();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    CustomerFact::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'value' => 'private fact']);

    $response = $this->get(route('customers.show', [$team->slug, $customer]));

    $response->assertRedirect();
    expect($response->getContent())->not->toContain('private fact');
});
