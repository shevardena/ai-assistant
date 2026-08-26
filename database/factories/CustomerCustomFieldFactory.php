<?php

namespace Database\Factories;

use App\Models\CustomerCustomField;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerCustomField>
 */
class CustomerCustomFieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'key' => fake()->unique()->slug(2), 'label' => fake()->words(2, true), 'type' => 'text', 'required' => false, 'active' => true, 'sort_order' => 0, 'options' => null];
    }
}
