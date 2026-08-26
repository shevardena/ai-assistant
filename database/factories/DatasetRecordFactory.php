<?php

namespace Database\Factories;

use App\Models\Dataset;
use App\Models\DatasetRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatasetRecord>
 */
class DatasetRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dataset_id' => Dataset::factory(),
            'external_id' => fake()->unique()->uuid(),
            'payload' => [
                'name' => fake()->words(3, true),
                'description' => fake()->sentence(),
            ],
            'searchable_text' => fake()->sentence(),
            'checksum' => fake()->sha256(),
            'is_active' => true,
            'source_updated_at' => now(),
        ];
    }
}
