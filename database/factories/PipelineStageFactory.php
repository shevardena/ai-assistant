<?php

namespace Database\Factories;

use App\Enums\PipelineStageSemanticType;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineStage>
 */
class PipelineStageFactory extends Factory
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
            'pipeline_id' => Pipeline::factory(),
            'name' => fake()->unique()->word(),
            'sort_order' => fake()->numberBetween(1, 10),
            'probability' => 50,
            'semantic_type' => PipelineStageSemanticType::Open,
        ];
    }
}
