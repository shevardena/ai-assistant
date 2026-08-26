<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerCustomField;
use App\Models\CustomerCustomFieldValue;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerCustomFieldValue>
 */
class CustomerCustomFieldValueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'customer_id' => Customer::factory(), 'customer_custom_field_id' => CustomerCustomField::factory(), 'value_text' => fake()->word(), 'value_number' => null, 'value_boolean' => null, 'value_date' => null, 'value_datetime' => null, 'value_json' => null];
    }
}
