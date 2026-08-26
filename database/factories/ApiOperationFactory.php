<?php

namespace Database\Factories;

use App\Enums\ApiOperationMode;
use App\Models\ApiOperation;
use App\Models\ApiOperationSyncSchedule;
use App\Models\DataSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiOperation>
 */
class ApiOperationFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (ApiOperation $operation): void {
            if (($operation->response_mapping['sync_mode'] ?? null) !== 'full_snapshot') {
                return;
            }

            ApiOperationSyncSchedule::query()->firstOrCreate(
                ['api_operation_id' => $operation->id],
                [
                    'frequency' => 'manual',
                    'strategy' => 'full_snapshot',
                    'is_enabled' => false,
                    'configuration' => [],
                ],
            );
        });
    }

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
            'name' => fake()->words(3, true),
            'type' => 'query',
            'execution_mode' => ApiOperationMode::Read->value,
            'method' => 'GET',
            'path' => '/'.fake()->slug(),
            'request_schema' => [],
            'request_mapping' => [],
            'response_mapping' => [],
            'headers' => [],
            'timeout_ms' => 10000,
            'is_enabled' => true,
        ];
    }
}
