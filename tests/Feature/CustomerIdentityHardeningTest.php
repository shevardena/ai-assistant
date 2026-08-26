<?php

use App\Enums\TeamRole;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Team;
use App\Models\User;
use App\Services\Customers\CustomerIdentityConflict;
use App\Services\Customers\CustomerIdentityResolutionService;
use App\Services\Customers\CustomerIdentityService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function identityHardeningContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

test('multiple identities normalize, remain team scoped, and resolve through secondary values', function (): void {
    [, $team] = identityHardeningContext();
    $customer = Customer::factory()->create(['team_id' => $team->id, 'email' => 'John@Example.COM', 'phone' => '+995 (555) 123-456']);
    $service = app(CustomerIdentityService::class);

    $secondaryEmail = $service->add($team, $customer, ['type' => 'email', 'value' => 'john.personal@example.com']);
    $secondaryPhone = $service->add($team, $customer, ['type' => 'phone', 'value' => '995-555-999-000']);
    $channel = $service->add($team, $customer, ['type' => 'channel_user', 'value' => 'tg-user', 'provider' => 'telegram', 'provider_external_id' => 'tg-user']);
    $resolver = app(CustomerIdentityResolutionService::class);

    expect($customer->fresh()->identities)->toHaveCount(5)
        ->and($customer->fresh()->identities->where('type', 'phone')->pluck('normalized_value')->all())->toContain('995555123456', '995555999000')
        ->and($secondaryEmail->normalized_value)->toBe('john.personal@example.com')
        ->and($channel->provider)->toBe('telegram')
        ->and($resolver->resolve($team, ['email' => 'JOHN.PERSONAL@example.com'])->customer?->is($customer))->toBeTrue()
        ->and($resolver->resolve($team, ['phone' => '(995) 555 999 000'])->customer?->is($customer))->toBeTrue()
        ->and($resolver->resolve($team, ['type' => 'channel_user', 'provider' => 'telegram', 'provider_external_id' => 'TG-USER'])->customer?->is($customer))->toBeTrue()
        ->and($resolver->resolve($team, ['name' => $customer->name])->customer)->toBeNull();
});

test('strong identity duplicates are blocked within a team but reusable across teams', function (): void {
    [, $team] = identityHardeningContext();
    $otherTeam = Team::factory()->create();
    $first = Customer::factory()->create(['team_id' => $team->id, 'email' => 'john@example.com']);
    $second = Customer::factory()->create(['team_id' => $team->id, 'email' => null, 'normalized_email' => null]);
    $foreign = Customer::factory()->create(['team_id' => $otherTeam->id, 'email' => 'john@example.com']);

    expect(fn (): CustomerIdentity => app(CustomerIdentityService::class)->add($team, $second, ['type' => 'email', 'value' => 'JOHN@example.com']))
        ->toThrow(CustomerIdentityConflict::class)
        ->and($foreign->fresh()->email)->toBe('john@example.com')
        ->and($first->fresh()->email)->toBe('john@example.com')
        ->and(CustomerIdentity::query()->where('normalized_value', 'john@example.com')->count())->toBe(2);
});

test('primary identity switching synchronizes canonical fields and protects primary removal', function (): void {
    [, $team] = identityHardeningContext();
    $customer = Customer::factory()->create(['team_id' => $team->id, 'email' => 'john.old@example.com', 'phone' => '+995 555 111 111']);
    $service = app(CustomerIdentityService::class);
    $newEmail = $service->add($team, $customer, ['type' => 'email', 'value' => 'john.new@example.com']);
    $newPhone = $service->add($team, $customer, ['type' => 'phone', 'value' => '+995 555 222 222']);

    $service->setPrimary($team, $customer, $newEmail);
    $service->setPrimary($team, $customer, $newPhone);

    expect($customer->fresh()->email)->toBe('john.new@example.com')
        ->and($customer->fresh()->phone)->toBe('+995 555 222 222')
        ->and($customer->fresh()->identities()->where('normalized_value', 'john.old@example.com')->first()?->is_primary)->toBeFalse()
        ->and($customer->fresh()->identities()->where('type', 'email')->where('is_primary', true)->count())->toBe(1)
        ->and($customer->fresh()->identities()->where('type', 'phone')->where('is_primary', true)->count())->toBe(1)
        ->and(fn () => $service->remove($team, $customer, $newEmail))->toThrow(ValidationException::class);
});

test('identity backfill adds identities without changing customer ids or merging records', function (): void {
    [, $team] = identityHardeningContext();
    $customers = Customer::withoutEvents(fn (): array => [
        Customer::factory()->create(['team_id' => $team->id, 'email' => 'a@example.com', 'phone' => null, 'normalized_phone' => null]),
        Customer::factory()->create(['team_id' => $team->id, 'email' => 'b@example.com', 'phone' => null, 'normalized_phone' => null]),
    ]);
    $ids = array_map(static fn (Customer $customer): int => $customer->id, $customers);
    $migration = require base_path('database/migrations/2026_08_24_224039_backfill_customer_primary_identities.php');

    $migration->up();

    expect(Customer::query()->whereKey($ids)->pluck('id')->all())->toBe($ids)
        ->and(CustomerIdentity::query()->whereIn('customer_id', $ids)->pluck('normalized_value')->sort()->values()->all())->toBe(['a@example.com', 'b@example.com'])
        ->and(Customer::query()->where('merged_into_customer_id', null)->whereKey($ids)->count())->toBe(2);
});

test('identity backfill deterministically skips an unassignable historical conflict without merging customers', function (): void {
    [, $team] = identityHardeningContext();
    $customers = Customer::withoutEvents(fn (): array => [
        Customer::factory()->create(['team_id' => $team->id, 'email' => 'conflict@example.com', 'normalized_email' => null, 'phone' => null, 'normalized_phone' => null]),
        Customer::factory()->create(['team_id' => $team->id, 'email' => 'conflict@example.com', 'normalized_email' => null, 'phone' => null, 'normalized_phone' => null]),
    ]);
    $migration = require base_path('database/migrations/2026_08_24_224039_backfill_customer_primary_identities.php');

    $migration->up();

    expect(CustomerIdentity::query()->where('normalized_value', 'conflict@example.com')->count())->toBe(1)
        ->and(CustomerIdentity::query()->where('normalized_value', 'conflict@example.com')->value('customer_id'))->toBe($customers[0]->id)
        ->and(Customer::query()->whereKey([$customers[0]->id, $customers[1]->id])->whereNull('merged_into_customer_id')->count())->toBe(2);
});

test('identity service and database reject a duplicate normalized identity', function (): void {
    [, $team] = identityHardeningContext();
    $first = Customer::factory()->create(['team_id' => $team->id, 'email' => 'duplicate@example.com']);
    $second = Customer::factory()->create(['team_id' => $team->id, 'email' => null, 'normalized_email' => null]);

    $duplicateThrown = false;

    try {
        DB::transaction(static fn (): CustomerIdentity => CustomerIdentity::query()->create(['team_id' => $team->id, 'customer_id' => $second->id, 'type' => 'email', 'value' => 'DUPLICATE@example.com', 'normalized_value' => 'duplicate@example.com']));
    } catch (QueryException) {
        $duplicateThrown = true;
    }

    expect($duplicateThrown)->toBeTrue()
        ->and(DB::table('customer_identities')->where('customer_id', $first->id)->where('normalized_value', 'duplicate@example.com')->exists())->toBeTrue();
});
