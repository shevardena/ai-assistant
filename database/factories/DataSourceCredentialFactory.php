<?php

namespace Database\Factories;

use App\Models\DataSource;
use App\Models\DataSourceCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSourceCredential>
 */
class DataSourceCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'data_source_id' => DataSource::factory(),
            'key' => fake()->unique()->slug(2),
            'encrypted_value' => fake()->sha256(),
        ];
    }
}
