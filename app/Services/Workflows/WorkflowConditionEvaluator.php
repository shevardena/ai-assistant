<?php

namespace App\Services\Workflows;

use App\Enums\WorkflowConditionOperator;
use App\Enums\WorkflowConditionType;
use App\Models\Workflow;
use App\Models\WorkflowCondition;

final class WorkflowConditionEvaluator
{
    /** @param array<string, mixed> $context */
    public function matches(Workflow $workflow, array $context): bool
    {
        foreach ($workflow->conditions as $condition) {
            if (! $this->matchesCondition($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $context */
    private function matchesCondition(WorkflowCondition $condition, array $context): bool
    {
        $type = WorkflowConditionType::tryFrom((string) $condition->getRawOriginal('type'));
        $operator = WorkflowConditionOperator::tryFrom((string) $condition->getRawOriginal('operator'));
        if (! $type || ! $operator) {
            return false;
        }

        $actual = match ($type) {
            WorkflowConditionType::BotEquals => $context['bot']?->getKey(),
            WorkflowConditionType::SourceEquals => $context['source'] ?? null,
            WorkflowConditionType::LeadStatusEquals => $context['lead']?->getRawOriginal('status'),
            WorkflowConditionType::AppointmentStatusEquals => $context['appointment']?->getRawOriginal('status'),
            WorkflowConditionType::TicketStatusEquals => $context['ticket']?->getRawOriginal('status'),
            WorkflowConditionType::HandoffReasonEquals => $context['reason'] ?? null,
        };
        $expected = $condition->getAttribute('value');

        if ($operator === WorkflowConditionOperator::Equals) {
            return (string) $actual === (string) $expected;
        }

        return (string) $actual !== (string) $expected;
    }
}
