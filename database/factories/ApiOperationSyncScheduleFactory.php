<?php

namespace Database\Factories;

use App\Enums\ApiOperationSyncFrequency;
use App\Enums\ApiOperationSyncStrategy;
use App\Models\ApiOperation;
use App\Models\ApiOperationSyncSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApiOperationSyncSchedule> */
class ApiOperationSyncScheduleFactory extends Factory
{
    protected $model = ApiOperationSyncSchedule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'api_operation_id' => ApiOperation::factory(),
            'frequency' => ApiOperationSyncFrequency::Manual->value,
            'strategy' => ApiOperationSyncStrategy::FullSnapshot->value,
            'is_enabled' => false,
            'configuration' => [],
        ];
    }
}
