<?php

namespace App\Enums;

enum TeamNotificationType: string
{
    case ConversationAssigned = 'conversation_assigned';
    case HumanHandoffRequested = 'human_handoff_requested';
    case IntegrationFailure = 'integration_failure';
    case DataImportFailed = 'data_import_failed';
    case LeadCaptured = 'lead_captured';
    case AppointmentBooked = 'appointment_booked';
    case SupportTicketCreated = 'support_ticket_created';
    case ActionFailed = 'action_failed';
    case WorkflowNotification = 'workflow_notification';
    case SubscriptionActivated = 'subscription_activated';
    case SubscriptionPaymentFailed = 'subscription_payment_failed';
    case SubscriptionCancelScheduled = 'subscription_cancel_scheduled';
    case SubscriptionEnded = 'subscription_ended';
}
