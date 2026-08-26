<?php

namespace App\Services\Workflows;

use App\Enums\AppointmentStatus;
use App\Enums\LeadStatus;
use App\Enums\SupportTicketStatus;
use App\Enums\TeamPermission;
use App\Enums\WorkflowActionType;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\WorkflowAction;
use App\Models\WorkflowRun;
use App\Services\Appointments\AppointmentService;
use App\Services\Conversations\ConversationHandoffService;
use App\Services\Leads\LeadService;
use App\Services\SupportTickets\SupportTicketService;
use App\Services\Teams\TeamNotificationService;

final class WorkflowActionExecutor
{
    public function __construct(
        private readonly LeadService $leads,
        private readonly AppointmentService $appointments,
        private readonly SupportTicketService $tickets,
        private readonly ConversationHandoffService $handoff,
        private readonly TeamNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, summary: string, error_code?: string}
     */
    public function execute(WorkflowAction $action, array $context, WorkflowRun $run): array
    {
        $type = $action->type;
        $config = is_array($action->config) ? $action->config : [];
        $team = $context['team'];
        if (! $team instanceof Team) {
            return ['ok' => false, 'summary' => 'The Team context is unavailable.', 'error_code' => 'resource_not_found'];
        }

        return match ($type) {
            WorkflowActionType::SendInAppNotification => $this->notification($team, $config, $run),
            WorkflowActionType::UpdateLeadStatus => $this->leadStatus($team, $context, (string) ($config['status'] ?? '')),
            WorkflowActionType::UpdateAppointmentStatus => $this->appointmentStatus($team, $context, (string) ($config['status'] ?? '')),
            WorkflowActionType::UpdateSupportTicketStatus => $this->ticketStatus($team, $context, (string) ($config['status'] ?? '')),
            WorkflowActionType::RequestHumanHandoff => $this->requestHandoff($team, $context, (string) ($config['reason'] ?? 'manual'), $run),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{ok: bool, summary: string, error_code?: string}
     */
    private function notification(Team $team, array $config, WorkflowRun $run): array
    {
        $permission = TeamPermission::tryFrom((string) ($config['permission'] ?? ''));
        if (! $permission || ! is_string($config['title'] ?? null) || ! is_string($config['message'] ?? null)) {
            return ['ok' => false, 'summary' => 'Notification configuration is invalid.', 'error_code' => 'invalid_configuration'];
        }

        $this->notifications->notifyWorkflowMessage($team, $permission, $config['title'], $config['message'], $run->public_id.':'.$run->actions()->count());

        return ['ok' => true, 'summary' => 'Sent an in-app notification.'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, summary: string, error_code?: string}
     */
    private function leadStatus(Team $team, array $context, string $status): array
    {
        if (! ($context['lead'] ?? null) instanceof Lead) {
            return ['ok' => false, 'summary' => 'The lead context is unavailable.', 'error_code' => 'resource_not_found'];
        }
        $lead = $this->leads->updateStatus($team, $context['lead'], LeadStatus::from($status));

        return ['ok' => true, 'summary' => 'Updated lead status to '.str_replace('_', ' ', (string) $lead->getRawOriginal('status')).'.'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, summary: string, error_code?: string}
     */
    private function appointmentStatus(Team $team, array $context, string $status): array
    {
        if (! ($context['appointment'] ?? null) instanceof Appointment) {
            return ['ok' => false, 'summary' => 'The appointment context is unavailable.', 'error_code' => 'resource_not_found'];
        }
        $appointment = $this->appointments->updateStatus($team, $context['appointment'], AppointmentStatus::from($status));

        return ['ok' => true, 'summary' => 'Updated appointment status to '.str_replace('_', ' ', $appointment->status->value).'.'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, summary: string, error_code?: string}
     */
    private function ticketStatus(Team $team, array $context, string $status): array
    {
        if (! ($context['ticket'] ?? null) instanceof SupportTicket) {
            return ['ok' => false, 'summary' => 'The support ticket context is unavailable.', 'error_code' => 'resource_not_found'];
        }
        $ticket = $this->tickets->updateStatus($team, $context['ticket'], SupportTicketStatus::from($status));

        return ['ok' => true, 'summary' => 'Updated support ticket status to '.str_replace('_', ' ', $ticket->status->value).'.'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, summary: string, error_code?: string}
     */
    private function requestHandoff(Team $team, array $context, string $reason, WorkflowRun $run): array
    {
        if (! ($context['conversation'] ?? null) instanceof Conversation) {
            return ['ok' => false, 'summary' => 'The conversation context is unavailable.', 'error_code' => 'resource_not_found'];
        }
        $this->handoff->request($team, $context['conversation'], $reason, $run->public_id, $run->depth + 1);

        return ['ok' => true, 'summary' => 'Requested human handoff.'];
    }
}
