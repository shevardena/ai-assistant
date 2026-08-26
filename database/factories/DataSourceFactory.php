<?php

namespace Database\Factories;

use App\Enums\DataSourceStatus;
use App\Models\DataSource;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSource>
 */
class DataSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(2, true),
            'type' => 'file',
            'status' => DataSourceStatus::Pending->value,
            'config' => [],
            'last_synced_at' => null,
        ];
    }

    /**
     * Indicate that the data source is ready.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DataSourceStatus::Ready->value,
            'last_synced_at' => now(),
        ]);
    }
}
