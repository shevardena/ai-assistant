<?php

namespace App\Services\Customers;

use App\Enums\CustomerActivityType;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerMergeService
{
    public function __construct(private readonly CustomerActivityService $activities) {}

    /** @return array<string, mixed> */
    public function preview(Team $team, Customer $source, Customer $destination): array
    {
        [$source, $destination] = $this->scopedPair($team, $source, $destination);
        $identityConflicts = $this->identityConflicts($source, $destination);
        $customConflicts = $this->customConflicts($source, $destination);
        $factConflicts = $this->factConflicts($source, $destination);

        return [
            'source' => $this->snapshot($source),
            'destination' => $this->snapshot($destination),
            'conflicts' => ['identities' => $identityConflicts, 'customFields' => $customConflicts, 'facts' => $factConflicts],
            'blocked' => $identityConflicts !== [],
        ];
    }

    public function merge(Team $team, Customer $source, Customer $destination, ?User $actor = null): Customer
    {
        [$source, $destination] = $this->scopedPair($team, $source, $destination);
        $preview = $this->preview($team, $source, $destination);

        if ($preview['blocked']) {
            throw ValidationException::withMessages(['source' => 'Resolve identity conflicts before merging these Customers.']);
        }

        return DB::transaction(function () use ($team, $source, $destination, $actor): Customer {
            $source = Customer::query()->lockForUpdate()->findOrFail($source->getKey());
            $destination = Customer::query()->lockForUpdate()->findOrFail($destination->getKey());

            if ($source->merged_into_customer_id !== null || $destination->merged_into_customer_id !== null || $source->is($destination)) {
                throw ValidationException::withMessages(['source' => 'This Customer merge is no longer valid.']);
            }

            foreach (['conversations', 'leads', 'appointments', 'supportTickets', 'notes', 'activities', 'deals', 'tasks'] as $relation) {
                $source->{$relation}()->update(['customer_id' => $destination->id]);
            }

            $this->moveCustomValues($source, $destination);
            $this->moveFacts($source, $destination);
            $this->moveIdentities($source, $destination);
            $destination->tags()->syncWithoutDetaching($source->tags()->pluck('customer_tags.id')->all());
            $source->tags()->detach();

            Customer::withoutEvents(fn (): bool => $source->update(['merged_into_customer_id' => $destination->id, 'merged_at' => now(), 'status' => CustomerStatus::Inactive->value]));
            $destination->update(['last_activity_at' => now()]);
            $this->activities->record($team, $destination, CustomerActivityType::Merged, 'Customers merged', 'Merged Customer #'.$source->id.' into this profile.', $actor, $source, null, ['source_customer_id' => $source->id]);

            return $destination->fresh() ?? $destination;
        });
    }

    /** @return array{0: Customer, 1: Customer} */
    private function scopedPair(Team $team, Customer $source, Customer $destination): array
    {
        $source = $team->customers()->whereKey($source->getKey())->firstOrFail();
        $destination = $team->customers()->whereKey($destination->getKey())->firstOrFail();

        if ($source->is($destination)) {
            throw ValidationException::withMessages(['destination' => 'A Customer cannot be merged into itself.']);
        }

        if ($source->merged_into_customer_id !== null || $destination->merged_into_customer_id !== null) {
            throw ValidationException::withMessages(['source' => 'Merged Customers cannot be merged again.']);
        }

        return [$source->load(['identities', 'tags', 'facts', 'customFieldValues.field']), $destination->load(['identities', 'tags', 'facts', 'customFieldValues.field'])];
    }

    /** @return array<string, mixed> */
    private function snapshot(Customer $customer): array
    {
        return ['id' => $customer->id, 'name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->phone, 'ownerId' => $customer->owner_id, 'status' => $customer->status->value, 'tags' => $customer->tags->pluck('name')->values()->all(), 'identities' => $customer->identities->map(fn ($identity): array => ['type' => $identity->type, 'value' => $identity->value, 'primary' => $identity->is_primary])->values()->all(), 'notesCount' => $customer->notes()->count(), 'conversationsCount' => $customer->conversations()->count(), 'leadsCount' => $customer->leads()->count(), 'appointmentsCount' => $customer->appointments()->count(), 'supportTicketsCount' => $customer->supportTickets()->count()];
    }

    /** @return list<array<string, mixed>> */
    private function identityConflicts(Customer $source, Customer $destination): array
    {
        $destinationKeys = $destination->identities->mapWithKeys(fn ($identity): array => [$identity->type.'|'.$identity->normalized_value => true]);

        return $source->identities->filter(fn ($identity): bool => $destinationKeys->has($identity->type.'|'.$identity->normalized_value))->map(fn ($identity): array => ['type' => $identity->type, 'value' => $identity->value])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function customConflicts(Customer $source, Customer $destination): array
    {
        $destinationKeys = $destination->customFieldValues->keyBy('customer_custom_field_id');

        return $source->customFieldValues->filter(fn ($value): bool => $destinationKeys->has($value->customer_custom_field_id))->map(fn ($value): array => ['field' => $value->field?->label, 'source' => $this->value($value), 'destination' => $this->value($destinationKeys->get($value->customer_custom_field_id))])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function factConflicts(Customer $source, Customer $destination): array
    {
        $destinationKeys = $destination->facts->keyBy('key');

        return $source->facts->filter(fn ($fact): bool => $destinationKeys->has($fact->key))->map(fn ($fact): array => ['key' => $fact->key, 'source' => $fact->value, 'destination' => $destinationKeys->get($fact->key)->value])->values()->all();
    }

    private function moveCustomValues(Customer $source, Customer $destination): void
    {
        foreach ($source->customFieldValues()->get() as $value) {
            if (! $destination->customFieldValues()->where('customer_custom_field_id', $value->customer_custom_field_id)->exists()) {
                $value->update(['customer_id' => $destination->id]);
            }
        }
    }

    private function moveFacts(Customer $source, Customer $destination): void
    {
        foreach ($source->facts()->get() as $fact) {
            if (! $destination->facts()->where('key', $fact->key)->exists()) {
                $fact->update(['customer_id' => $destination->id]);
            }
        }
    }

    private function moveIdentities(Customer $source, Customer $destination): void
    {
        CustomerIdentity::query()
            ->where('customer_id', $source->id)
            ->update(['customer_id' => $destination->id, 'is_primary' => false]);
    }

    private function value(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        foreach (['value_text', 'value_number', 'value_boolean', 'value_date', 'value_datetime', 'value_json'] as $column) {
            if ($value->{$column} !== null) {
                return $value->{$column};
            }
        }

        return null;
    }
}
