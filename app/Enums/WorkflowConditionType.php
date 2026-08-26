<?php

namespace App\Enums;

enum WorkflowConditionType: string
{
    case BotEquals = 'bot_equals';
    case SourceEquals = 'source_equals';
    case LeadStatusEquals = 'lead_status_equals';
    case AppointmentStatusEquals = 'appointment_status_equals';
    case TicketStatusEquals = 'ticket_status_equals';
    case HandoffReasonEquals = 'handoff_reason_equals';
}
