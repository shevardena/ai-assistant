<?php

namespace App\Services\Operational;

use App\Models\Appointment;
use App\Models\Lead;
use App\Models\SupportTicket;
use App\Models\ToolRun;
use App\Services\Appointments\AppointmentProjectionService;
use App\Services\Leads\LeadService;
use App\Services\SupportTickets\SupportTicketProjectionService;
use App\Services\Teams\TeamNotificationService;
use App\Services\Workflows\WorkflowTriggerService;

final class CompletedActionProjectionService
{
    public function __construct(
        private readonly LeadService $leads,
        private readonly AppointmentProjectionService $appointments,
        private readonly SupportTicketProjectionService $supportTickets,
        private readonly TeamNotificationService $notifications,
        private readonly WorkflowTriggerService $workflowTriggers,
    ) {}

    public function project(ToolRun $run): void
    {
        $projection = match ($run->tool_name) {
            'capture_lead' => $this->leads->createFromCompletedRun($run),
            'book_appointment' => $this->appointments->createFromCompletedRun($run),
            'create_support_ticket' => $this->supportTickets->createFromCompletedRun($run),
            default => null,
        };

        if ($projection instanceof Lead) {
            $this->notifications->notifyLeadCaptured($projection);
            $this->workflowTriggers->leadCaptured($projection);
        } elseif ($projection instanceof Appointment) {
            $this->notifications->notifyAppointmentBooked($projection);
            $this->workflowTriggers->appointmentBooked($projection);
        } elseif ($projection instanceof SupportTicket) {
            $this->notifications->notifySupportTicketCreated($projection);
            $this->workflowTriggers->supportTicketCreated($projection);
        }
    }
}
