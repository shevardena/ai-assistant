<?php

namespace Database\Factories;

use App\Models\DataSource;
use App\Models\SourceRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceRun>
 */
class SourceRunFactory extends Factory
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
            'dataset_id' => null,
            'type' => 'import',
            'status' => 'pending',
            'rows_read' => 0,
            'rows_written' => 0,
            'rows_failed' => 0,
            'error' => null,
            'metadata' => [],
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
