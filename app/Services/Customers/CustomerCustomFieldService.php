<?php

namespace App\Services\Customers;

use App\Enums\CustomerActivityType;
use App\Enums\CustomerCustomFieldType;
use App\Models\Customer;
use App\Models\CustomerCustomField;
use App\Models\CustomerCustomFieldValue;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CustomerCustomFieldService
{
    public function __construct(private readonly CustomerActivityService $activities) {}

    /** @return list<CustomerCustomField> */
    public function fields(Team $team, bool $activeOnly = false): array
    {
        $query = $team->customerCustomFields()->orderBy('sort_order')->orderBy('id');

        if ($activeOnly) {
            $query->where('active', true);
        }

        return $query->get()->all();
    }

    /** @param array<string, mixed> $data */
    public function create(Team $team, array $data, ?User $actor = null): CustomerCustomField
    {
        $validated = $this->definition($team, $data);
        $field = $team->customerCustomFields()->create($validated);

        return $field;
    }

    /** @param array<string, mixed> $data */
    public function update(Team $team, CustomerCustomField $field, array $data): CustomerCustomField
    {
        $field = $team->customerCustomFields()->whereKey($field->getKey())->firstOrFail();
        $field->update($this->definition($team, $data, $field));

        return $field->fresh() ?? $field;
    }

    public function setActive(Team $team, CustomerCustomField $field, bool $active): CustomerCustomField
    {
        $field = $team->customerCustomFields()->whereKey($field->getKey())->firstOrFail();
        $field->update(['active' => $active]);

        return $field->fresh() ?? $field;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<CustomerCustomFieldValue>
     */
    public function saveValues(Team $team, Customer $customer, array $values, ?User $actor = null): array
    {
        $customer = $team->customers()->whereKey($customer->getKey())->firstOrFail();
        $fields = collect($this->fields($team, true))->keyBy('key');
        $provided = [];

        foreach ($values as $key => $value) {
            $field = is_numeric($key)
                ? $fields->first(fn (CustomerCustomField $item): bool => $item->id === (int) $key)
                : $fields->get((string) $key);

            if (! $field instanceof CustomerCustomField) {
                throw ValidationException::withMessages(['custom_fields' => 'One or more custom fields do not belong to this Team.']);
            }

            $provided[$field->key] = $this->normalizeValue($field, $value);
        }

        foreach ($fields as $field) {
            if ($field->required && (! array_key_exists($field->key, $provided) || $this->isEmpty($provided[$field->key]))) {
                throw ValidationException::withMessages(['custom_fields.'.$field->key => $field->label.' is required.']);
            }
        }

        foreach ($provided as $key => $value) {
            $field = $fields->get($key);
            $query = $customer->customFieldValues()->where('customer_custom_field_id', $field->id);

            if ($this->isEmpty($value)) {
                $query->delete();

                continue;
            }

            $valueData = $this->typedColumns($field, $value);
            $stored = $customer->customFieldValues()->updateOrCreate(
                ['customer_custom_field_id' => $field->id],
                ['team_id' => $team->id, ...$valueData],
            );
            $this->activities->record($team, $customer, CustomerActivityType::CustomFieldChanged, 'Custom field updated', $field->label, $actor, $stored);
        }

        return $customer->customFieldValues()->with('field')->get()->all();
    }

    /** @return array<string, mixed> */
    public function displayValues(Team $team, Customer $customer): array
    {
        $fields = collect($this->fields($team))->keyBy('id');

        return $customer->customFieldValues()->with('field')->get()->mapWithKeys(function (CustomerCustomFieldValue $value) use ($fields): array {
            $field = $fields->get($value->customer_custom_field_id);

            if (! $field instanceof CustomerCustomField) {
                return [];
            }

            return [$field->key => ['fieldId' => $field->id, 'key' => $field->key, 'label' => $field->label, 'type' => $field->type, 'value' => $this->readValue($value, $field)]];
        })->all();
    }

    /** @param array<string, mixed> $data */
    private function definition(Team $team, array $data, ?CustomerCustomField $ignore = null): array
    {
        $key = Str::lower(trim((string) ($data['key'] ?? $ignore?->key ?? '')));
        $type = CustomerCustomFieldType::tryFrom((string) ($data['type'] ?? $ignore?->type ?? ''));
        $label = trim((string) ($data['label'] ?? ''));
        $options = array_values(array_unique(array_filter(array_map(static fn (mixed $option): string => trim((string) $option), is_array($data['options'] ?? null) ? $data['options'] : []))));

        if (preg_match('/^[a-z][a-z0-9_]{1,79}$/', $key) !== 1) {
            throw ValidationException::withMessages(['key' => 'Keys must start with a letter and contain only lowercase letters, numbers, and underscores.']);
        }

        if ($label === '' || mb_strlen($label) > 160) {
            throw ValidationException::withMessages(['label' => 'Enter a valid field label.']);
        }

        if (! $type instanceof CustomerCustomFieldType) {
            throw ValidationException::withMessages(['type' => 'Select a supported custom field type.']);
        }

        if ($ignore !== null && $ignore->type !== $type->value && $ignore->values()->exists()) {
            throw ValidationException::withMessages(['type' => 'A field with stored values cannot change type.']);
        }

        if ($type->isChoice() && $options === []) {
            throw ValidationException::withMessages(['options' => 'Select fields require at least one option.']);
        }

        $duplicate = $team->customerCustomFields()->where('key', $key)->when($ignore, fn (Builder $query): Builder => $query->whereKeyNot($ignore->getKey()))->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['key' => 'That custom field key is already in use.']);
        }

        return ['key' => $key, 'label' => $label, 'type' => $type->value, 'required' => (bool) ($data['required'] ?? false), 'active' => (bool) ($data['active'] ?? true), 'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)), 'options' => $type->isChoice() ? $options : null];
    }

    private function normalizeValue(CustomerCustomField $field, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $type = CustomerCustomFieldType::from($field->type);

        return match ($type) {
            CustomerCustomFieldType::Text, CustomerCustomFieldType::Textarea => $this->textValue($value, $field),
            CustomerCustomFieldType::Select => $this->select($value, $field),
            CustomerCustomFieldType::Number => is_numeric($value) ? (float) $value : throw ValidationException::withMessages(['custom_fields.'.$field->key => 'Enter a valid number.']),
            CustomerCustomFieldType::Boolean => is_bool($value) ? $value : (in_array($value, [0, 1, '0', '1', 'true', 'false'], true) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : throw ValidationException::withMessages(['custom_fields.'.$field->key => 'Enter a valid boolean.'])),
            CustomerCustomFieldType::Date => $this->date($value, $field),
            CustomerCustomFieldType::Datetime => $this->datetime($value, $field),
            CustomerCustomFieldType::MultiSelect => $this->multiSelect($value, $field),
        };
    }

    /** @return array<string, mixed> */
    private function typedColumns(CustomerCustomField $field, mixed $value): array
    {
        $empty = ['value_text' => null, 'value_number' => null, 'value_boolean' => null, 'value_date' => null, 'value_datetime' => null, 'value_json' => null];

        return match (CustomerCustomFieldType::from($field->type)) {
            CustomerCustomFieldType::Text, CustomerCustomFieldType::Textarea, CustomerCustomFieldType::Select => [...$empty, 'value_text' => $value],
            CustomerCustomFieldType::Number => [...$empty, 'value_number' => $value],
            CustomerCustomFieldType::Boolean => [...$empty, 'value_boolean' => $value],
            CustomerCustomFieldType::Date => [...$empty, 'value_date' => $value],
            CustomerCustomFieldType::Datetime => [...$empty, 'value_datetime' => $value],
            CustomerCustomFieldType::MultiSelect => [...$empty, 'value_json' => $value],
        };
    }

    private function readValue(CustomerCustomFieldValue $value, CustomerCustomField $field): mixed
    {
        return match (CustomerCustomFieldType::from($field->type)) {
            CustomerCustomFieldType::Text, CustomerCustomFieldType::Textarea, CustomerCustomFieldType::Select => $value->value_text,
            CustomerCustomFieldType::Number => $value->value_number,
            CustomerCustomFieldType::Boolean => $value->value_boolean,
            CustomerCustomFieldType::Date => $value->value_date?->format('Y-m-d'),
            CustomerCustomFieldType::Datetime => $value->value_datetime?->toAtomString(),
            CustomerCustomFieldType::MultiSelect => $value->value_json ?? [],
        };
    }

    private function date(mixed $value, CustomerCustomField $field): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages(['custom_fields.'.$field->key => 'Enter a valid date.']);
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['custom_fields.'.$field->key => 'Enter a valid date.']);
        }

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['custom_fields.'.$field->key => 'Enter a valid date.']);
        }

        return $date->format('Y-m-d');
    }

    private function datetime(mixed $value, CustomerCustomField $field): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages(['custom_fields.'.$field->key => 'Enter a valid date and time.']);
        }

        foreach (['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s', 'Y-m-d H:i:s'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }

            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        throw ValidationException::withMessages(['custom_fields.'.$field->key => 'Enter a valid date and time.']);
    }

    private function textValue(mixed $value, CustomerCustomField $field): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages(['custom_fields.'.$field->key => 'Enter text for this field.']);
        }

        return Str::limit(trim($value), 5000, '');
    }

    /** @return list<string> */
    private function multiSelect(mixed $value, CustomerCustomField $field): array
    {
        $options = is_array($field->options) ? $field->options : [];
        $values = is_array($value) ? array_values(array_unique(array_map(static fn (mixed $item): string => trim((string) $item), $value))) : [];

        if (array_diff($values, $options) !== []) {
            throw ValidationException::withMessages(['custom_fields.'.$field->key => 'Choose only configured options.']);
        }

        return $values;
    }

    private function select(mixed $value, CustomerCustomField $field): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value !== '' && ! in_array($value, is_array($field->options) ? $field->options : [], true)) {
            throw ValidationException::withMessages(['custom_fields.'.$field->key => 'Choose only a configured option.']);
        }

        return $value === '' ? null : $value;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
