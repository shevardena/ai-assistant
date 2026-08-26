<?php

namespace App\Enums;

enum CustomerActivityType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case OwnerChanged = 'owner_changed';
    case StatusChanged = 'status_changed';
    case TagChanged = 'tag_changed';
    case IdentityAdded = 'identity_added';
    case IdentityRemoved = 'identity_removed';
    case CustomFieldChanged = 'custom_field_changed';
    case FactChanged = 'fact_changed';
    case NoteAdded = 'note_added';
    case Merged = 'merged';
    case SummaryGenerated = 'summary_generated';
    case DealCreated = 'deal_created';
    case DealStageChanged = 'deal_stage_changed';
    case DealOwnerChanged = 'deal_owner_changed';
    case DealValueChanged = 'deal_value_changed';
    case DealExpectedCloseChanged = 'deal_expected_close_changed';
    case DealWon = 'deal_won';
    case DealLost = 'deal_lost';
    case DealReopened = 'deal_reopened';
    case TaskCreated = 'task_created';
    case TaskAssigneeChanged = 'task_assignee_changed';
    case TaskDueChanged = 'task_due_changed';
    case TaskPriorityChanged = 'task_priority_changed';
    case TaskCompleted = 'task_completed';
    case TaskReopened = 'task_reopened';
    case TaskCancelled = 'task_cancelled';
}
