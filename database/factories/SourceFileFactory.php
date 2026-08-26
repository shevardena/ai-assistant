<?php

namespace Database\Factories;

use App\Models\DataSource;
use App\Models\SourceFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceFile>
 */
class SourceFileFactory extends Factory
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
            'uploaded_by' => User::factory(),
            'disk' => 'local',
            'path' => 'source-files/'.fake()->uuid().'.csv',
            'original_name' => fake()->word().'.csv',
            'mime_type' => 'text/csv',
            'size_bytes' => fake()->numberBetween(1024, 1024 * 1024),
            'checksum' => fake()->sha256(),
            'status' => 'uploaded',
            'metadata' => [],
        ];
    }
}
