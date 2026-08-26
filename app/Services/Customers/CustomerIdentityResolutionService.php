<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class CustomerIdentityResolutionService
{
    /**
     * Resolve exact identifiers inside the supplied team only.
     * Names can create a new record when explicitly allowed, but never match one.
     *
     * @param  array{name?: string|null, email?: string|null, phone?: string|null, source?: string|null, company?: string|null, type?: string|null, provider?: string|null, provider_external_id?: string|null}  $identity
     */
    public function resolve(Team $team, array $identity, bool $allowNameOnly = false): CustomerResolution
    {
        $email = Customer::normalizeEmail($identity['email'] ?? null);
        $phone = Customer::normalizePhone($identity['phone'] ?? null);
        $emailCustomer = $email === null ? null : $this->customerForIdentity($team, 'email', $email);
        $phoneCustomer = $phone === null ? null : $this->customerForIdentity($team, 'phone', $phone);
        $channelCustomer = null;

        if (($identity['type'] ?? null) === 'channel_user' && is_string($identity['provider_external_id'] ?? null)) {
            $channelCustomer = $this->customerForIdentity($team, 'channel_user', Str::lower(trim((string) $identity['provider_external_id'])), is_string($identity['provider'] ?? null) ? $identity['provider'] : null);
        }

        $matches = collect([$emailCustomer, $phoneCustomer, $channelCustomer])->filter()->unique(fn (Customer $customer): int => $customer->id);

        if ($matches->count() > 1) {
            $this->recordConflict($team, 'email_phone');

            return new CustomerResolution(null, true, 'email');
        }

        $customer = $matches->first();

        if ($customer !== null) {
            return new CustomerResolution($customer);
        }

        $name = $this->text($identity['name'] ?? null, 255);

        if ($email === null && $phone === null && $channelCustomer === null && (($identity['type'] ?? null) !== 'channel_user' || ! isset($identity['provider_external_id'])) && (! $allowNameOnly || $name === null)) {
            return new CustomerResolution(null);
        }

        try {
            $customer = $team->customers()->create([
                'display_name' => $name,
                'email' => $this->text($identity['email'] ?? null, 320),
                'phone' => $this->text($identity['phone'] ?? null, 64),
                'company' => $this->text($identity['company'] ?? null, 255),
                'source' => $this->text($identity['source'] ?? null, 30),
                'last_activity_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return $this->resolve($team, $identity, false);
        }

        if (($identity['type'] ?? null) === 'channel_user' && is_string($identity['provider_external_id'] ?? null)) {
            CustomerIdentity::query()->create([
                'team_id' => $team->id,
                'customer_id' => $customer->id,
                'type' => 'channel_user',
                'value' => (string) $identity['provider_external_id'],
                'normalized_value' => Str::lower(trim((string) $identity['provider_external_id'])),
                'provider' => is_string($identity['provider'] ?? null) ? Str::limit(trim($identity['provider']), 50, '') : null,
                'provider_external_id' => Str::limit(trim((string) $identity['provider_external_id']), 255, ''),
            ]);
        }

        return new CustomerResolution($customer);
    }

    private function customerForIdentity(Team $team, string $type, string $normalized, ?string $provider = null): ?Customer
    {
        $identity = $team->customerIdentities()->where('type', $type)->where('normalized_value', $normalized)->when($provider !== null, fn ($query) => $query->where('provider', $provider))->with('customer')->first();
        $customer = $identity?->customer;

        if ($customer?->merged_into_customer_id !== null) {
            return $customer->mergedInto;
        }

        return $customer ?? $team->customers()->whereNull('merged_into_customer_id')->when($type === 'email', fn ($query) => $query->where('normalized_email', $normalized))->when($type === 'phone', fn ($query) => $query->where('normalized_phone', $normalized))->first();
    }

    /** @param array{name?: string|null, email?: string|null, phone?: string|null} $identity */
    public function ensureNoConflict(Team $team, array $identity, ?Customer $ignore = null): void
    {
        $email = Customer::normalizeEmail($identity['email'] ?? null);
        $phone = Customer::normalizePhone($identity['phone'] ?? null);

        foreach ([['value' => $email, 'field' => 'email'], ['value' => $phone, 'field' => 'phone']] as $identifier) {
            if ($identifier['value'] === null) {
                continue;
            }

            $query = $team->customers()->where(
                $identifier['field'] === 'email' ? 'normalized_email' : 'normalized_phone',
                $identifier['value'],
            );

            if ($ignore !== null) {
                $query->whereKeyNot($ignore->getKey());
            }

            if ($query->exists()) {
                throw new CustomerIdentityConflict($identifier['field']);
            }
        }
    }

    private function recordConflict(Team $team, string $type): void
    {
        Log::warning('Customer identity conflict detected.', [
            'team_id' => $team->getKey(),
            'conflict_type' => $type,
        ]);
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), $limit, '');
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23505';
    }
}
