<?php

namespace App\Services\Workflows;

use App\Enums\WorkflowTriggerType;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\SupportTicket;

final class WorkflowTriggerService
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function leadCaptured(Lead $lead): void
    {
        $lead->loadMissing(['bot', 'conversation']);
        if (! $lead->bot || (int) $lead->team_id !== (int) $lead->bot->team_id || data_get($lead->conversation?->metadata, 'source') === 'dashboard_preview') {
            return;
        }
        $this->engine->dispatch(WorkflowTriggerType::LeadCaptured, $lead->bot->team, ['bot' => $lead->bot, 'lead' => $lead, 'conversation' => $lead->conversation, 'source' => $lead->source], 'lead:'.$lead->public_id);
    }

    public function appointmentBooked(Appointment $appointment): void
    {
        $appointment->loadMissing(['bot', 'conversation']);
        if (! $appointment->bot || (int) $appointment->team_id !== (int) $appointment->bot->team_id || data_get($appointment->conversation?->metadata, 'source') === 'dashboard_preview') {
            return;
        }
        $this->engine->dispatch(WorkflowTriggerType::AppointmentBooked, $appointment->bot->team, ['bot' => $appointment->bot, 'appointment' => $appointment, 'conversation' => $appointment->conversation], 'appointment:'.$appointment->public_id);
    }

    public function supportTicketCreated(SupportTicket $ticket): void
    {
        $ticket->loadMissing(['bot', 'conversation']);
        if (! $ticket->bot || (int) $ticket->team_id !== (int) $ticket->bot->team_id || data_get($ticket->conversation?->metadata, 'source') === 'dashboard_preview') {
            return;
        }
        $this->engine->dispatch(WorkflowTriggerType::SupportTicketCreated, $ticket->bot->team, ['bot' => $ticket->bot, 'ticket' => $ticket, 'conversation' => $ticket->conversation], 'support_ticket:'.$ticket->public_id);
    }

    public function humanHandoffRequested(Conversation $conversation, string $reason, ?string $originRunId = null, int $depth = 0): void
    {
        $conversation->loadMissing('bot');
        if (! $conversation->bot || data_get($conversation->metadata, 'source') === 'dashboard_preview') {
            return;
        }
        $requestedAt = (string) ($conversation->getRawOriginal('handoff_requested_at') ?: 'requested');
        $this->engine->dispatch(WorkflowTriggerType::HumanHandoffRequested, $conversation->bot->team, ['bot' => $conversation->bot, 'conversation' => $conversation, 'reason' => $reason], 'conversation:'.$conversation->public_id.':'.$requestedAt, $originRunId, $depth);
    }
}
