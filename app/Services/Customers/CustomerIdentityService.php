<?php

namespace App\Services\Customers;

use App\Enums\CustomerActivityType;
use App\Enums\CustomerIdentityType;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CustomerIdentityService
{
    public function __construct(private readonly CustomerActivityService $activities) {}

    /**
     * @return list<CustomerIdentity>
     */
    public function list(Team $team, Customer $customer): array
    {
        return $team->customerIdentities()
            ->where('customer_id', $customer->getKey())
            ->orderByDesc('is_primary')
            ->orderBy('type')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @param  array{type: string, value: string, provider?: string|null, provider_external_id?: string|null, is_primary?: bool, is_verified?: bool}  $data
     */
    public function add(Team $team, Customer $customer, array $data, ?User $actor = null): CustomerIdentity
    {
        $customer = $team->customers()->whereKey($customer->getKey())->firstOrFail();
        $type = CustomerIdentityType::tryFrom((string) ($data['type'] ?? ''));
        $value = trim((string) ($data['value'] ?? ''));
        $provider = $this->text($data['provider'] ?? null, 50);
        $providerExternalId = $this->text($data['provider_external_id'] ?? null, 255);

        if (! $type instanceof CustomerIdentityType || $value === '') {
            throw ValidationException::withMessages(['value' => 'Enter a supported identity.']);
        }

        if ($type === CustomerIdentityType::ChannelUser && ($provider === null || $providerExternalId === null)) {
            throw ValidationException::withMessages(['provider_external_id' => 'Channel identities require a provider and external ID.']);
        }

        $normalized = $this->normalize($type, $value, $providerExternalId);
        $existing = $team->customerIdentities()
            ->where('type', $type->value)
            ->where('normalized_value', $normalized)
            ->when($type === CustomerIdentityType::ChannelUser, fn ($query) => $query->where('provider', $provider))
            ->first();

        if ($existing !== null && (int) $existing->customer_id !== (int) $customer->getKey()) {
            throw new CustomerIdentityConflict($type->value);
        }

        try {
            $identity = $customer->identities()->updateOrCreate(
                ['type' => $type->value, 'normalized_value' => $normalized],
                [
                    'team_id' => $team->getKey(),
                    'value' => Str::limit($value, 320, ''),
                    'provider' => $provider,
                    'provider_external_id' => $providerExternalId,
                    'is_primary' => (bool) ($data['is_primary'] ?? false),
                    'is_verified' => (bool) ($data['is_verified'] ?? false),
                ],
            );
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            throw new CustomerIdentityConflict($type->value);
        }

        if ($identity->is_primary && in_array($type, [CustomerIdentityType::Email, CustomerIdentityType::Phone], true)) {
            $this->setPrimary($team, $customer, $identity, $actor, false);
        }

        $this->activities->record($team, $customer, CustomerActivityType::IdentityAdded, 'Identity added', $this->description($identity), $actor, $identity);

        return $identity->fresh() ?? $identity;
    }

    public function setPrimary(Team $team, Customer $customer, CustomerIdentity $identity, ?User $actor = null, bool $recordActivity = true): CustomerIdentity
    {
        $customer = $team->customers()->whereKey($customer->getKey())->firstOrFail();
        $identity = $team->customerIdentities()->whereKey($identity->getKey())->where('customer_id', $customer->getKey())->firstOrFail();
        $type = CustomerIdentityType::from($identity->type);

        if (! in_array($type, [CustomerIdentityType::Email, CustomerIdentityType::Phone], true)) {
            throw ValidationException::withMessages(['identity' => 'Only email and phone identities can be primary.']);
        }

        $customer->identities()->where('type', $type->value)->whereKeyNot($identity->getKey())->update(['is_primary' => false]);
        $identity->update(['is_primary' => true]);
        $customer->update([$type === CustomerIdentityType::Email ? 'email' : 'phone' => $identity->value]);

        if ($recordActivity) {
            $this->activities->record($team, $customer, CustomerActivityType::IdentityAdded, 'Primary identity changed', $this->description($identity), $actor, $identity);
        }

        return $identity->fresh() ?? $identity;
    }

    public function remove(Team $team, Customer $customer, CustomerIdentity $identity, ?User $actor = null): void
    {
        $customer = $team->customers()->whereKey($customer->getKey())->firstOrFail();
        $identity = $team->customerIdentities()->whereKey($identity->getKey())->where('customer_id', $customer->getKey())->firstOrFail();

        if ($identity->is_primary) {
            throw ValidationException::withMessages(['identity' => 'Set another identity as primary before removing this one.']);
        }

        $description = $this->description($identity);
        $identity->delete();
        $this->activities->record($team, $customer, CustomerActivityType::IdentityRemoved, 'Identity removed', $description, $actor);
    }

    public function normalize(CustomerIdentityType $type, string $value, ?string $providerExternalId = null): string
    {
        return match ($type) {
            CustomerIdentityType::Email => Customer::normalizeEmail($value) ?? '',
            CustomerIdentityType::Phone => Customer::normalizePhone($value) ?? '',
            CustomerIdentityType::ChannelUser => Str::lower(trim($providerExternalId ?? $value)),
        };
    }

    private function description(CustomerIdentity $identity): string
    {
        return ucfirst($identity->type).': '.Str::limit($identity->value, 160, '');
    }

    private function text(mixed $value, int $limit): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $limit, '') : null;
    }
}
