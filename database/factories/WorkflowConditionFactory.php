<?php

namespace Database\Factories;

use App\Enums\WorkflowConditionOperator;
use App\Enums\WorkflowConditionType;
use App\Models\Workflow;
use App\Models\WorkflowCondition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowCondition>
 */
class WorkflowConditionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['workflow_id' => Workflow::factory(), 'type' => WorkflowConditionType::BotEquals->value, 'operator' => WorkflowConditionOperator::Equals->value, 'value' => '1', 'position' => 0];
    }
}
