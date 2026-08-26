<?php

namespace App\Services\Customers;

use App\Enums\CustomerCustomFieldType;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class CustomerSegmentService
{
    /** @return list<CustomerSegment> */
    public function index(Team $team): array
    {
        return $team->customerSegments()->latest('updated_at')->get()->each(function (CustomerSegment $segment) use ($team): void {
            $segment->setAttribute('matching_count', $this->query($team, $segment->filter_definition)->count());
        })->all();
    }

    /** @param array<string, mixed> $data */
    public function create(Team $team, array $data, ?User $actor = null): CustomerSegment
    {
        $definition = $this->normalizeDefinition($team, $data['filter_definition'] ?? []);

        return $team->customerSegments()->create(['name' => trim((string) $data['name']), 'description' => $data['description'] ?? null, 'filter_definition' => $definition, 'created_by_user_id' => $actor?->id]);
    }

    /** @param array<string, mixed> $data */
    public function update(Team $team, CustomerSegment $segment, array $data): CustomerSegment
    {
        $segment = $team->customerSegments()->whereKey($segment->getKey())->firstOrFail();
        $definition = $this->normalizeDefinition($team, $data['filter_definition'] ?? $segment->filter_definition);
        $segment->update(['name' => trim((string) ($data['name'] ?? $segment->name)), 'description' => $data['description'] ?? $segment->description, 'filter_definition' => $definition]);

        return $segment->fresh() ?? $segment;
    }

    public function delete(Team $team, CustomerSegment $segment): void
    {
        $team->customerSegments()->whereKey($segment->getKey())->firstOrFail()->delete();
    }

    /** @return Builder<Customer> */
    public function query(Team $team, array $definition): Builder
    {
        $query = $team->customers()->getQuery()->whereNull('merged_into_customer_id');

        foreach ((array) ($definition['filters'] ?? []) as $filter) {
            $this->apply($query, $team, $filter);
        }

        return $query;
    }

    /** @return array{filters: list<array<string, mixed>>} */
    public function normalizeDefinition(Team $team, mixed $definition): array
    {
        if (! is_array($definition) || ! is_array($definition['filters'] ?? null) || count($definition['filters']) > 20) {
            throw ValidationException::withMessages(['filter_definition' => 'Provide a bounded list of supported filters.']);
        }

        $filters = [];

        foreach ($definition['filters'] as $filter) {
            if (! is_array($filter)) {
                throw ValidationException::withMessages(['filter_definition' => 'Each segment filter must be structured data.']);
            }

            $field = (string) ($filter['field'] ?? '');
            $operator = (string) ($filter['operator'] ?? '');
            $value = $filter['value'] ?? null;
            $allowed = $this->allowedOperators($field);

            if (! in_array($operator, $allowed, true)) {
                throw ValidationException::withMessages(['filter_definition' => 'That field/operator combination is not supported.']);
            }

            if (in_array($field, ['status', 'owner_id', 'tag', 'source'], true)) {
                $this->validateReference($team, $field, $value, $operator);
            }

            if (in_array($field, ['last_activity_at', 'created_at'], true)) {
                try {
                    CarbonImmutable::parse((string) $value);
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['filter_definition' => 'Date filters require a valid date.']);
                }
            }

            $normalized = ['field' => $field, 'operator' => $operator, 'value' => $value];

            if ($field === 'custom_field') {
                $key = (string) ($filter['key'] ?? '');
                $customField = array_key_exists('id', $filter)
                    ? $team->customerCustomFields()->whereKey((int) $filter['id'])->first()
                    : $team->customerCustomFields()->where('key', $key)->first();

                if ($customField === null) {
                    throw ValidationException::withMessages(['filter_definition' => 'The custom field must belong to this Team.']);
                }

                if (! in_array($operator, $this->customOperators($customField->type), true)) {
                    throw ValidationException::withMessages(['filter_definition' => 'That operator is not valid for this custom field type.']);
                }

                $normalized['key'] = $customField->key;
            }

            $filters[] = $normalized;
        }

        return ['filters' => $filters];
    }

    /** @param array<string, mixed> $filter */
    private function apply(Builder $query, Team $team, array $filter): void
    {
        $field = (string) ($filter['field'] ?? '');
        $operator = (string) ($filter['operator'] ?? '');
        $value = $filter['value'] ?? null;

        if ($field === 'status' || $field === 'source') {
            $this->scalar($query, $field, $operator, $value);
        } elseif ($field === 'owner_id') {
            $this->scalar($query, 'owner_id', $operator, is_array($value) ? array_map('intval', $value) : (int) $value);
        } elseif ($field === 'tag') {
            $tagIds = is_array($value) ? array_map('intval', $value) : [(int) $value];
            $method = in_array($operator, ['not_equals', 'not_in'], true) ? 'whereDoesntHave' : 'whereHas';
            $query->{$method}('tags', fn (Builder $tags): Builder => $tags->where('customer_tags.team_id', $team->id)->whereIn('customer_tags.id', $tagIds));
        } elseif ($field === 'email_exists' || $field === 'phone_exists') {
            $query->{((bool) $value) ? 'whereNotNull' : 'whereNull'}($field === 'email_exists' ? 'email' : 'phone');
        } elseif ($field === 'last_activity_at' || $field === 'created_at') {
            $query->where($field, $operator === 'equals' ? '=' : ($operator === 'before' ? '<' : '>'), CarbonImmutable::parse((string) $value));
        } elseif ($field === 'has_open_ticket') {
            $query->{((bool) $value) ? 'whereHas' : 'whereDoesntHave'}('supportTickets', fn (Builder $tickets): Builder => $tickets->whereIn('status', ['open', 'in_progress']));
        } elseif ($field === 'has_upcoming_appointment') {
            $query->{((bool) $value) ? 'whereHas' : 'whereDoesntHave'}('appointments', fn (Builder $appointments): Builder => $appointments->where('starts_at', '>=', now())->where('status', 'scheduled'));
        } elseif ($field === 'custom_field') {
            $customField = $team->customerCustomFields()->where('key', (string) ($filter['key'] ?? ''))->firstOrFail();
            $query->{in_array($operator, ['not_equals', 'not_in'], true) ? 'whereDoesntHave' : 'whereHas'}('customFieldValues', function (Builder $values) use ($customField, $operator, $value): void {
                $values->where('customer_custom_field_id', $customField->id);
                $column = match ($customField->type) {
                    'number' => 'value_number',
                    'date' => 'value_date',
                    'datetime' => 'value_datetime',
                    default => 'value_text',
                };
                $isMultiSelect = $customField->type === CustomerCustomFieldType::MultiSelect->value;
                $sqlOperator = match ($operator) {
                    'equals' => '=', 'not_equals' => '=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', default => 'in'
                };
                if ($isMultiSelect && in_array($operator, ['equals', 'not_equals'], true)) {
                    $values->whereJsonContains('value_json', $value);
                } elseif ($isMultiSelect && $sqlOperator === 'in') {
                    $values->where(function (Builder $multiSelect) use ($value): void {
                        foreach ((array) $value as $option) {
                            $multiSelect->orWhereJsonContains('value_json', $option);
                        }
                    });
                } elseif ($sqlOperator === 'in') {
                    $values->whereIn($column, (array) $value);
                } else {
                    $values->where($column, $sqlOperator, $value);
                }
            });
        }
    }

    private function scalar(Builder $query, string $field, string $operator, mixed $value): void
    {
        if ($operator === 'in') {
            $query->whereIn($field, (array) $value);
        } elseif ($operator === 'not_in') {
            $query->whereNotIn($field, (array) $value);
        } else {
            $query->where($field, $operator === 'not_equals' ? '!=' : '=', $value);
        }
    }

    /** @return list<string> */
    private function allowedOperators(string $field): array
    {
        return match ($field) {
            'status', 'owner_id', 'tag', 'source' => ['equals', 'not_equals', 'in', 'not_in'],
            'last_activity_at', 'created_at' => ['before', 'after', 'equals'],
            'email_exists', 'phone_exists', 'has_open_ticket', 'has_upcoming_appointment' => ['equals'],
            'custom_field' => ['equals', 'not_equals', 'in', 'gt', 'gte', 'lt', 'lte'],
            default => [],
        };
    }

    /** @return list<string> */
    private function customOperators(string $type): array
    {
        return match (CustomerCustomFieldType::tryFrom($type)) {
            CustomerCustomFieldType::Number => ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte'],
            CustomerCustomFieldType::Date, CustomerCustomFieldType::Datetime => ['equals', 'gt', 'gte', 'lt', 'lte'],
            CustomerCustomFieldType::Select, CustomerCustomFieldType::MultiSelect => ['equals', 'not_equals', 'in'],
            default => ['equals', 'not_equals'],
        };
    }

    private function validateReference(Team $team, string $field, mixed $value, string $operator): void
    {
        $values = in_array($operator, ['in', 'not_in'], true) ? (array) $value : [$value];

        if (collect($values)->contains(fn (mixed $reference): bool => ! is_scalar($reference))) {
            throw ValidationException::withMessages(['filter_definition' => 'Reference filters require scalar values.']);
        }

        if ($field === 'status' && array_diff($values, array_map(fn (CustomerStatus $status): string => $status->value, CustomerStatus::cases())) !== []) {
            throw ValidationException::withMessages(['filter_definition' => 'That status is not supported.']);
        }
        if ($field === 'owner_id' && $team->members()->whereKey(array_map('intval', $values))->count() !== count($values)) {
            throw ValidationException::withMessages(['filter_definition' => 'The segment owner must belong to this Team.']);
        }
        if ($field === 'tag' && $team->customerTags()->whereKey(array_map('intval', $values))->count() !== count($values)) {
            throw ValidationException::withMessages(['filter_definition' => 'The segment tag must belong to this Team.']);
        }
    }
}
