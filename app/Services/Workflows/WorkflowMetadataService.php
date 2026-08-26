<?php

namespace App\Services\Workflows;

use App\Enums\AppointmentStatus;
use App\Enums\LeadStatus;
use App\Enums\SupportTicketStatus;
use App\Enums\TeamPermission;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowConditionOperator;
use App\Enums\WorkflowConditionType;
use App\Enums\WorkflowTriggerType;
use App\Models\Bot;
use App\Models\Team;
use Illuminate\Support\Str;

final class WorkflowMetadataService
{
    /** @return list<array{value: string, label: string}> */
    public function triggerOptions(): array
    {
        return array_map(fn (WorkflowTriggerType $type): array => [
            'value' => $type->value,
            'label' => Str::headline($type->value),
        ], WorkflowTriggerType::cases());
    }

    /** @return array<string, mixed> */
    public function builderMetadata(Team $team, ?WorkflowTriggerType $trigger = null): array
    {
        $trigger ??= WorkflowTriggerType::LeadCaptured;

        $definitions = [];
        foreach (WorkflowTriggerType::cases() as $type) {
            $definitions[$type->value] = $this->definition($team, $type);
        }

        return [
            'triggers' => $this->triggerOptions(),
            ...$this->definition($team, $trigger),
            'definitions' => $definitions,
        ];
    }

    /** @return array<string, mixed> */
    private function definition(Team $team, WorkflowTriggerType $trigger): array
    {
        return [
            'conditions' => array_map(fn (WorkflowConditionType $type): array => ['value' => $type->value, 'label' => Str::headline($type->value), 'options' => $this->conditionOptions($team, $type)], $this->conditionTypesFor($trigger)),
            'operators' => array_map(fn (WorkflowConditionOperator $operator): array => ['value' => $operator->value, 'label' => Str::headline($operator->value)], WorkflowConditionOperator::cases()),
            'actions' => array_map(fn (WorkflowActionType $type): array => ['value' => $type->value, 'label' => Str::headline($type->value), 'permissions' => $type === WorkflowActionType::SendInAppNotification ? $this->notificationPermissions() : [], 'options' => $this->actionOptions($type)], $this->actionTypesFor($trigger)),
        ];
    }

    /** @return list<WorkflowConditionType> */
    public function conditionTypesFor(WorkflowTriggerType $trigger): array
    {
        return match ($trigger) {
            WorkflowTriggerType::LeadCaptured => [WorkflowConditionType::BotEquals, WorkflowConditionType::SourceEquals, WorkflowConditionType::LeadStatusEquals],
            WorkflowTriggerType::AppointmentBooked => [WorkflowConditionType::BotEquals, WorkflowConditionType::AppointmentStatusEquals],
            WorkflowTriggerType::SupportTicketCreated => [WorkflowConditionType::BotEquals, WorkflowConditionType::TicketStatusEquals],
            WorkflowTriggerType::HumanHandoffRequested => [WorkflowConditionType::BotEquals, WorkflowConditionType::HandoffReasonEquals],
        };
    }

    /** @return list<WorkflowActionType> */
    public function actionTypesFor(WorkflowTriggerType $trigger): array
    {
        return match ($trigger) {
            WorkflowTriggerType::LeadCaptured => [WorkflowActionType::UpdateLeadStatus, WorkflowActionType::SendInAppNotification],
            WorkflowTriggerType::AppointmentBooked => [WorkflowActionType::UpdateAppointmentStatus, WorkflowActionType::SendInAppNotification],
            WorkflowTriggerType::SupportTicketCreated => [WorkflowActionType::UpdateSupportTicketStatus, WorkflowActionType::SendInAppNotification],
            WorkflowTriggerType::HumanHandoffRequested => [WorkflowActionType::SendInAppNotification, WorkflowActionType::RequestHumanHandoff],
        };
    }

    /** @return list<array{value: string|int, label: string}> */
    public function conditionOptions(Team $team, WorkflowConditionType $type): array
    {
        return array_values(match ($type) {
            WorkflowConditionType::BotEquals => $team->bots()->select(['id', 'name'])->orderBy('name')->get()->map(fn (Bot $bot): array => ['value' => $bot->id, 'label' => $bot->name])->values()->all(),
            WorkflowConditionType::SourceEquals => array_map(fn (string $value): array => ['value' => $value, 'label' => Str::headline($value)], ['widget', 'conversation', 'api']),
            WorkflowConditionType::LeadStatusEquals => $this->enumOptions(LeadStatus::cases()),
            WorkflowConditionType::AppointmentStatusEquals => $this->enumOptions(AppointmentStatus::cases()),
            WorkflowConditionType::TicketStatusEquals => $this->enumOptions(SupportTicketStatus::cases()),
            WorkflowConditionType::HandoffReasonEquals => array_map(fn (string $value): array => ['value' => $value, 'label' => Str::headline($value)], ['customer_requested', 'runtime_escalation', 'manual']),
        });
    }

    /** @return list<array{value: string, label: string}> */
    public function actionOptions(WorkflowActionType $type): array
    {
        return match ($type) {
            WorkflowActionType::UpdateLeadStatus => $this->enumOptions(LeadStatus::cases()),
            WorkflowActionType::UpdateAppointmentStatus => $this->enumOptions(AppointmentStatus::cases()),
            WorkflowActionType::UpdateSupportTicketStatus => $this->enumOptions(SupportTicketStatus::cases()),
            WorkflowActionType::RequestHumanHandoff => array_map(fn (string $value): array => ['value' => $value, 'label' => Str::headline($value)], ['customer_requested', 'runtime_escalation', 'manual']),
            WorkflowActionType::SendInAppNotification => [],
        };
    }

    /** @return list<array{value: string, label: string}> */
    public function notificationPermissions(): array
    {
        return array_map(fn (TeamPermission $permission): array => ['value' => $permission->value, 'label' => Str::headline(str_replace('.', ' ', $permission->value))], [
            TeamPermission::LeadsView,
            TeamPermission::AppointmentsView,
            TeamPermission::TicketsView,
            TeamPermission::ConversationsHandoff,
            TeamPermission::ActionsView,
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    public function validateDefinition(Team $team, array $definition): array
    {
        $errors = [];
        $trigger = WorkflowTriggerType::tryFrom((string) ($definition['trigger_type'] ?? ''));

        if (! $trigger instanceof WorkflowTriggerType) {
            return ['trigger_type' => 'Choose a supported workflow trigger.'];
        }

        $allowedConditions = $this->conditionTypesFor($trigger);
        foreach ((array) ($definition['conditions'] ?? []) as $index => $condition) {
            $type = WorkflowConditionType::tryFrom((string) ($condition['type'] ?? ''));
            $operator = WorkflowConditionOperator::tryFrom((string) ($condition['operator'] ?? ''));
            $value = $condition['value'] ?? null;

            if (! $type || ! in_array($type, $allowedConditions, true)) {
                $errors["conditions.$index.type"] = 'This condition is not supported for the selected trigger.';

                continue;
            }

            if (! $operator) {
                $errors["conditions.$index.operator"] = 'Choose a supported condition operator.';
            }

            if ($value === null || $value === '') {
                $errors["conditions.$index.value"] = 'Choose a condition value.';

                continue;
            }

            if ($type === WorkflowConditionType::BotEquals && ! $team->bots()->whereKey((int) $value)->exists()) {
                $errors["conditions.$index.value"] = 'The selected Bot does not belong to this Team.';
            }
            if ($type === WorkflowConditionType::SourceEquals && ! in_array((string) $value, ['widget', 'conversation', 'api'], true)) {
                $errors["conditions.$index.value"] = 'The selected source is not supported.';
            }
            if ($type === WorkflowConditionType::LeadStatusEquals && ! LeadStatus::tryFrom((string) $value)) {
                $errors["conditions.$index.value"] = 'The selected lead status is not supported.';
            }
            if ($type === WorkflowConditionType::AppointmentStatusEquals && ! AppointmentStatus::tryFrom((string) $value)) {
                $errors["conditions.$index.value"] = 'The selected appointment status is not supported.';
            }
            if ($type === WorkflowConditionType::TicketStatusEquals && ! SupportTicketStatus::tryFrom((string) $value)) {
                $errors["conditions.$index.value"] = 'The selected ticket status is not supported.';
            }
            if ($type === WorkflowConditionType::HandoffReasonEquals && ! in_array((string) $value, ['customer_requested', 'runtime_escalation', 'manual'], true)) {
                $errors["conditions.$index.value"] = 'The selected handoff reason is not supported.';
            }
        }

        $actions = (array) ($definition['actions'] ?? []);
        if ($actions === []) {
            $errors['actions'] = 'Add at least one workflow action.';
        }

        $allowedActions = $this->actionTypesFor($trigger);
        foreach ($actions as $index => $action) {
            $type = WorkflowActionType::tryFrom((string) ($action['type'] ?? ''));
            $config = is_array($action['config'] ?? null) ? $action['config'] : [];

            if (! $type || ! in_array($type, $allowedActions, true)) {
                $errors["actions.$index.type"] = 'This action is not supported for the selected trigger.';

                continue;
            }

            if (in_array($type, [WorkflowActionType::UpdateLeadStatus, WorkflowActionType::UpdateAppointmentStatus, WorkflowActionType::UpdateSupportTicketStatus, WorkflowActionType::RequestHumanHandoff], true)
                && ! in_array((string) ($config['status'] ?? $config['reason'] ?? ''), array_column($this->actionOptions($type), 'value'), true)) {
                $errors["actions.$index.config"] = 'Choose a supported action value.';
            }

            if ($type === WorkflowActionType::SendInAppNotification) {
                $permission = TeamPermission::tryFrom((string) ($config['permission'] ?? ''));
                $allowedPermissions = array_column($this->notificationPermissions(), 'value');
                if (! $permission || ! in_array($permission->value, $allowedPermissions, true)) {
                    $errors["actions.$index.config.permission"] = 'Choose a supported Team recipient category.';
                }
                if (! is_string($config['title'] ?? null) || trim($config['title']) === '' || Str::length($config['title']) > 120) {
                    $errors["actions.$index.config.title"] = 'Enter a notification title up to 120 characters.';
                }
                if (! is_string($config['message'] ?? null) || trim($config['message']) === '' || Str::length($config['message']) > 500) {
                    $errors["actions.$index.config.message"] = 'Enter a notification message up to 500 characters.';
                }
            }
        }

        return $errors;
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(fn (\BackedEnum $case): array => ['value' => (string) $case->value, 'label' => Str::headline((string) $case->value)], $cases);
    }
}
