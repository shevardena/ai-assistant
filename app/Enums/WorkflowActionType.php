<?php

namespace App\Enums;

enum WorkflowActionType: string
{
    case SendInAppNotification = 'send_in_app_notification';
    case UpdateLeadStatus = 'update_lead_status';
    case UpdateAppointmentStatus = 'update_appointment_status';
    case UpdateSupportTicketStatus = 'update_support_ticket_status';
    case RequestHumanHandoff = 'request_human_handoff';
}
