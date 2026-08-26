<?php

namespace App\Enums;

enum WorkflowTriggerType: string
{
    case LeadCaptured = 'lead_captured';
    case AppointmentBooked = 'appointment_booked';
    case SupportTicketCreated = 'support_ticket_created';
    case HumanHandoffRequested = 'human_handoff_requested';
}
