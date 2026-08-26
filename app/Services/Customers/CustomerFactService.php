<?php

namespace App\Services\Customers;

use App\Enums\CustomerActivityType;
use App\Enums\CustomerFactSource;
use App\Models\Customer;
use App\Models\CustomerFact;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CustomerFactService
{
    public function __construct(private readonly CustomerActivityService $activities) {}

    /** @return list<CustomerFact> */
    public function list(Team $team, Customer $customer): array
    {
        return $team->customerFacts()->where('customer_id', $customer->getKey())->orderBy('key')->get()->all();
    }

    /** @param array<string, mixed> $data */
    public function save(Team $team, Customer $customer, array $data, ?User $actor = null): CustomerFact
    {
        $customer = $team->customers()->whereKey($customer->getKey())->firstOrFail();
        $key = Str::lower(trim((string) ($data['key'] ?? '')));
        $value = trim((string) ($data['value'] ?? ''));
        $source = CustomerFactSource::tryFrom((string) ($data['source'] ?? CustomerFactSource::Manual->value));

        if (preg_match('/^[a-z][a-z0-9_]{1,79}$/', $key) !== 1) {
            throw ValidationException::withMessages(['key' => 'Fact keys must start with a letter and contain only lowercase letters, numbers, and underscores.']);
        }

        if ($value === '' || mb_strlen($value) > 2000) {
            throw ValidationException::withMessages(['value' => 'Enter a fact value up to 2,000 characters.']);
        }

        if (! $source instanceof CustomerFactSource) {
            throw ValidationException::withMessages(['source' => 'Select a supported fact source.']);
        }

        $confidence = $data['confidence'] ?? null;

        if ($confidence !== null && (! is_numeric($confidence) || (float) $confidence < 0 || (float) $confidence > 1)) {
            throw ValidationException::withMessages(['confidence' => 'Confidence must be between 0 and 1.']);
        }

        $fact = $customer->facts()->updateOrCreate(
            ['key' => $key],
            [
                'team_id' => $team->id,
                'value' => $value,
                'value_type' => Str::limit(trim((string) ($data['value_type'] ?? 'text')), 30, ''),
                'source' => $source->value,
                'confidence' => $confidence,
                'last_confirmed_at' => now(),
                'created_by_user_id' => $actor?->id,
            ],
        );

        $this->activities->record($team, $customer, CustomerActivityType::FactChanged, 'Customer fact updated', $key.': '.$value, $actor, $fact);

        return $fact->fresh() ?? $fact;
    }

    public function delete(Team $team, Customer $customer, CustomerFact $fact, ?User $actor = null): void
    {
        $customer = $team->customers()->whereKey($customer->getKey())->firstOrFail();
        $fact = $team->customerFacts()->whereKey($fact->getKey())->where('customer_id', $customer->getKey())->firstOrFail();
        $fact->delete();
        $this->activities->record($team, $customer, CustomerActivityType::FactChanged, 'Customer fact removed', $fact->key, $actor);
    }
}
