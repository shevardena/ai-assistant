<?php

use App\Enums\CustomerCustomFieldType;
use App\Enums\TeamRole;
use App\Models\Customer;
use App\Models\CustomerCustomField;
use App\Models\Team;
use App\Models\User;
use App\Services\Customers\CustomerCustomFieldService;
use App\Services\Customers\CustomerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

function customFieldHardeningContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

test('all supported custom field types accept valid values and reject invalid values server side', function (): void {
    [, $team] = customFieldHardeningContext();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $service = app(CustomerCustomFieldService::class);
    $cases = [
        ['type' => CustomerCustomFieldType::Text->value, 'valid' => 'Acme', 'invalid' => 123],
        ['type' => CustomerCustomFieldType::Textarea->value, 'valid' => 'Long internal context', 'invalid' => ['not' => 'text']],
        ['type' => CustomerCustomFieldType::Number->value, 'valid' => '42.5', 'invalid' => 'forty-two'],
        ['type' => CustomerCustomFieldType::Boolean->value, 'valid' => 'true', 'invalid' => 'maybe'],
        ['type' => CustomerCustomFieldType::Date->value, 'valid' => '2026-08-25', 'invalid' => 'tomorrow'],
        ['type' => CustomerCustomFieldType::Datetime->value, 'valid' => '2026-08-25T10:30', 'invalid' => 'tomorrow'],
        ['type' => CustomerCustomFieldType::Select->value, 'valid' => 'pro', 'invalid' => 'enterprise', 'options' => ['free', 'pro']],
        ['type' => CustomerCustomFieldType::MultiSelect->value, 'valid' => ['email', 'phone'], 'invalid' => ['email', 'carrier_pigeon'], 'options' => ['email', 'phone']],
    ];

    foreach ($cases as $index => $case) {
        $field = $service->create($team, ['key' => 'field_'.$index, 'label' => 'Field '.$index, 'type' => $case['type'], 'options' => $case['options'] ?? []]);
        $service->saveValues($team, $customer, [$field->key => $case['valid']]);

        expect($customer->customFieldValues()->where('customer_custom_field_id', $field->id)->exists())->toBeTrue();
        expect(fn (): array => $service->saveValues($team, $customer, [$field->key => $case['invalid']]))->toThrow(ValidationException::class);
    }
});

test('required custom fields apply on server-side customer create and edit flows', function (): void {
    [, $team] = customFieldHardeningContext();
    $field = app(CustomerCustomFieldService::class)->create($team, ['key' => 'customer_tier', 'label' => 'Customer tier', 'type' => 'select', 'options' => ['standard', 'premium'], 'required' => true]);
    $service = app(CustomerService::class);

    expect(fn (): Customer => $service->create($team, ['first_name' => 'Required', 'last_name' => 'Customer', 'status' => 'new', 'custom_fields' => []]))
        ->toThrow(ValidationException::class);

    $customer = $service->create($team, ['first_name' => 'Required', 'last_name' => 'Customer', 'status' => 'new', 'custom_fields' => [$field->key => 'premium']]);
    expect($customer->customFieldValues()->where('customer_custom_field_id', $field->id)->exists())->toBeTrue();
    expect(fn (): Customer => $service->update($team, $customer, ['status' => 'active', 'custom_fields' => []]))->toThrow(ValidationException::class);
});

test('custom field definitions and values are isolated by team and deactivation preserves history', function (): void {
    [, $team] = customFieldHardeningContext();
    $foreignTeam = Team::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $foreignCustomer = Customer::factory()->create(['team_id' => $foreignTeam->id]);
    $service = app(CustomerCustomFieldService::class);
    $field = $service->create($team, ['key' => 'interest', 'label' => 'Interest', 'type' => 'text']);
    $foreignField = $service->create($foreignTeam, ['key' => 'interest', 'label' => 'Foreign interest', 'type' => 'text']);
    $service->saveValues($team, $customer, [$field->key => 'CRM']);

    expect(fn (): array => $service->saveValues($team, $customer, [$foreignField->id => 'invalid']))->toThrow(ValidationException::class)
        ->and(fn (): CustomerCustomField => $service->update($team, $foreignField, ['label' => 'Nope', 'type' => 'text']))->toThrow(ModelNotFoundException::class)
        ->and(fn (): array => $service->saveValues($team, $foreignCustomer, [$field->key => 'invalid']))->toThrow(ModelNotFoundException::class);

    $service->setActive($team, $field, false);
    expect($customer->customFieldValues()->where('customer_custom_field_id', $field->id)->value('value_text'))->toBe('CRM')
        ->and($service->fields($team, true))->toBeEmpty();

    $service->setActive($team, $field, true);
    expect($service->displayValues($team, $customer)[$field->key]['value'])->toBe('CRM');
});

test('custom field update preserves the immutable key while allowing label changes', function (): void {
    [, $team] = customFieldHardeningContext();
    $service = app(CustomerCustomFieldService::class);
    $field = $service->create($team, ['key' => 'preferred_plan', 'label' => 'Plan', 'type' => 'text']);

    $updated = $service->update($team, $field, ['label' => 'Preferred plan', 'type' => 'text']);

    expect($updated->key)->toBe('preferred_plan')->and($updated->label)->toBe('Preferred plan');
});
