<?php

namespace Database\Factories;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dataset>
 */
class DatasetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        if (is_array($name)) {
            $name = implode(' ', $name);
        }

        return [
            'team_id' => Team::factory(),
            'data_source_id' => null,
            'name' => $name,
            'slug' => str($name)->slug().'-'.fake()->unique()->numberBetween(1000, 9999),
            'entity_type' => 'generic',
            'retrieval_mode' => 'indexed',
            'primary_key_path' => 'id',
            'status' => DatasetStatus::Preparing->value,
            'settings' => [],
            'schema_version' => 1,
            'last_indexed_at' => null,
        ];
    }

    /**
     * Indicate that the dataset is ready.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DatasetStatus::Ready->value,
            'last_indexed_at' => now(),
        ]);
    }
}
