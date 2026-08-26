<?php

namespace App\Services\Teams;

use App\Enums\ConversationHandoffStatus;
use App\Enums\TeamNotificationType;
use App\Enums\TeamPermission;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\DataSource;
use App\Models\Lead;
use App\Models\SourceRun;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Notifications\TeamEventNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class TeamNotificationService
{
    public function __construct(
        private readonly TeamAuthorizationService $authorization,
    ) {}

    public function notifyHandoffRequested(Conversation $conversation): void
    {
        $conversation->loadMissing(['bot:id,team_id,name', 'bot.team']);

        if ($this->isPreview($conversation) || $conversation->handoff_status !== ConversationHandoffStatus::Requested) {
            return;
        }

        $bot = $conversation->bot;

        if (! $bot || ! $bot->team) {
            return;
        }

        $requestedAt = $conversation->getAttribute('handoff_requested_at');
        $requestedAtKey = $requestedAt instanceof CarbonInterface
            ? $requestedAt->toIso8601String()
            : (string) ($requestedAt ?? 'requested');
        $eventKey = 'handoff:'.$conversation->getKey().':'.$requestedAtKey;

        $this->sendToTeam(
            team: $bot->team,
            permission: TeamPermission::ConversationsHandoff,
            type: TeamNotificationType::HumanHandoffRequested,
            eventKey: $eventKey,
            title: 'Human handoff requested',
            message: 'A customer asked to speak with a team member.',
            data: $this->target('conversation', (string) $conversation->public_id, $bot->name),
        );
    }

    public function notifyConversationAssigned(Conversation $conversation, User $recipient): void
    {
        $conversation->loadMissing(['bot:id,team_id,name', 'bot.team']);
        $team = $conversation->bot?->team;

        if (! $team || ! $recipient->belongsToTeam($team)
            || ! $this->authorization->can($recipient, $team, TeamPermission::ConversationsReply)) {
            return;
        }

        $eventKey = 'conversation-assigned:'.$conversation->getKey().':'.$recipient->getKey().':'.$conversation->updated_at?->format('U.u');

        try {
            if ($this->alreadySent($recipient, $team, $eventKey)) {
                return;
            }

            $recipient->notifyNow(new TeamEventNotification(
                notificationType: TeamNotificationType::ConversationAssigned,
                teamId: (int) $team->getKey(),
                eventKey: $eventKey,
                title: 'Conversation assigned to you',
                message: 'A conversation was assigned to you.',
                data: $this->target('conversation', (string) $conversation->public_id, $conversation->bot->name),
            ));
        } catch (Throwable $exception) {
            Log::warning('Conversation assignment notification failed.', [
                'team_id' => $team->getKey(),
                'recipient_id' => $recipient->getKey(),
                'conversation_id' => $conversation->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }

    public function notifyLeadCaptured(Lead $lead): void
    {
        $lead->loadMissing(['bot:id,team_id,name', 'conversation:id,metadata', 'bot.team']);
        $bot = $lead->bot;

        if (! $bot || ! $bot->team || (int) $lead->team_id !== (int) $bot->team_id || $this->isPreview($lead->conversation)) {
            return;
        }

        $this->sendToTeam(
            team: $bot->team,
            permission: TeamPermission::LeadsView,
            type: TeamNotificationType::LeadCaptured,
            eventKey: 'lead:'.$lead->getKey(),
            title: 'New lead captured',
            message: 'A new lead was captured by '.$bot->name.'.',
            data: $this->target('lead', (string) $lead->public_id, $bot->name),
        );
    }

    public function notifyAppointmentBooked(Appointment $appointment): void
    {
        $appointment->loadMissing(['bot:id,team_id,name', 'conversation:id,metadata', 'bot.team']);
        $bot = $appointment->bot;

        if (! $bot || ! $bot->team || (int) $appointment->team_id !== (int) $bot->team_id || $this->isPreview($appointment->conversation)) {
            return;
        }

        $when = $appointment->starts_at?->timezone($appointment->timezone ?: config('app.timezone'))->format('M j \a\t g:i A');
        $message = $when === null
            ? 'A new appointment was booked.'
            : 'A new appointment was booked for '.$when.'.';

        $this->sendToTeam(
            team: $bot->team,
            permission: TeamPermission::AppointmentsView,
            type: TeamNotificationType::AppointmentBooked,
            eventKey: 'appointment:'.$appointment->getKey(),
            title: 'Appointment booked',
            message: $message,
            data: $this->target('appointment', (string) $appointment->public_id, $bot->name),
        );
    }

    public function notifySupportTicketCreated(SupportTicket $ticket): void
    {
        $ticket->loadMissing(['bot:id,team_id,name', 'conversation:id,metadata', 'bot.team']);
        $bot = $ticket->bot;

        if (! $bot || ! $bot->team || (int) $ticket->team_id !== (int) $bot->team_id || $this->isPreview($ticket->conversation)) {
            return;
        }

        $this->sendToTeam(
            team: $bot->team,
            permission: TeamPermission::TicketsView,
            type: TeamNotificationType::SupportTicketCreated,
            eventKey: 'support-ticket:'.$ticket->getKey(),
            title: 'Support ticket created',
            message: 'A new support ticket was created.',
            data: $this->target('support_ticket', (string) $ticket->public_id, $bot->name),
        );
    }

    public function notifyIntegrationFailure(DataSource $dataSource, ?string $failureKey = null): void
    {
        $dataSource->loadMissing('team');

        if (! $dataSource->team) {
            return;
        }

        $safeFailureKey = $failureKey === null || trim($failureKey) === ''
            ? 'failure'
            : Str::slug(Str::limit($failureKey, 80, ''));
        $eventKey = 'integration:'.$dataSource->getKey().':'.$safeFailureKey.':'.now()->format('YmdH');

        $this->sendToTeam(
            team: $dataSource->team,
            permission: TeamPermission::IntegrationHealthView,
            type: TeamNotificationType::IntegrationFailure,
            eventKey: $eventKey,
            title: $dataSource->name.' is failing',
            message: 'The integration could not complete a request.',
            data: $this->target('data_source', (string) $dataSource->getKey()),
        );
    }

    public function notifyDataImportFailed(SourceRun $sourceRun): void
    {
        $sourceRun->loadMissing(['dataSource.team', 'dataset']);
        $dataSource = $sourceRun->dataSource;
        $dataset = $sourceRun->dataset;

        if (! $dataSource || ! $dataSource->team) {
            return;
        }

        $target = $dataset && (int) $dataset->team_id === (int) $dataSource->team_id
            ? $this->target('dataset', (string) $dataset->getKey())
            : $this->target('data_source', (string) $dataSource->getKey());

        $this->sendToTeam(
            team: $dataSource->team,
            permission: TeamPermission::DataHealthView,
            type: TeamNotificationType::DataImportFailed,
            eventKey: 'source-run:'.$sourceRun->getKey(),
            title: 'Data import failed',
            message: $dataSource->name.' could not be synchronized.',
            data: $target,
        );
    }

    public function notifyActionFailed(ToolRun $run): void
    {
        $run->loadMissing(['bot:id,team_id,name', 'bot.team', 'conversation:id,metadata', 'apiOperation.dataSource']);

        if ($this->isPreview($run->conversation) || ! $run->bot?->team) {
            return;
        }

        $errorCode = (string) ($run->error_code ?? 'action_failed');

        if (in_array($errorCode, ['integration_error', 'timeout', 'unavailable'], true)) {
            if ($run->apiOperation?->dataSource instanceof DataSource
                && (int) $run->apiOperation->dataSource->team_id === (int) $run->bot->team_id) {
                $this->notifyIntegrationFailure($run->apiOperation->dataSource, $errorCode);
            }

            return;
        }

        $this->sendToTeam(
            team: $run->bot->team,
            permission: TeamPermission::ActionsView,
            type: TeamNotificationType::ActionFailed,
            eventKey: 'action:'.$run->getKey(),
            title: 'Action failed',
            message: Str::headline((string) $run->tool_name).' could not be completed.',
            data: $this->target('action', (string) $run->action_reference, $run->bot->name),
        );
    }

    public function notifySubscriptionActivated(Team $team, string $eventKey): void
    {
        $this->sendToTeam(
            team: $team,
            permission: TeamPermission::BillingView,
            type: TeamNotificationType::SubscriptionActivated,
            eventKey: $eventKey,
            title: 'Subscription active',
            message: 'Your Team subscription is active.',
            data: [],
        );
    }

    public function notifySubscriptionPaymentFailed(Team $team, string $eventKey): void
    {
        $this->sendToTeam(
            team: $team,
            permission: TeamPermission::BillingView,
            type: TeamNotificationType::SubscriptionPaymentFailed,
            eventKey: $eventKey,
            title: 'Subscription payment failed',
            message: 'Payment for your Team subscription failed. Update your billing details.',
            data: [],
        );
    }

    public function notifySubscriptionCancelScheduled(Team $team, string $eventKey): void
    {
        $this->sendToTeam(
            team: $team,
            permission: TeamPermission::BillingView,
            type: TeamNotificationType::SubscriptionCancelScheduled,
            eventKey: $eventKey,
            title: 'Subscription cancellation scheduled',
            message: 'Your subscription will remain active until the end of the current billing period.',
            data: [],
        );
    }

    public function notifySubscriptionEnded(Team $team, string $eventKey): void
    {
        $this->sendToTeam(
            team: $team,
            permission: TeamPermission::BillingView,
            type: TeamNotificationType::SubscriptionEnded,
            eventKey: $eventKey,
            title: 'Subscription ended',
            message: 'Your Team subscription has ended and billing access has returned to the Free plan.',
            data: [],
        );
    }

    public function notifyWorkflowMessage(
        Team $team,
        TeamPermission $permission,
        string $title,
        string $message,
        string $eventKey,
    ): void {
        $this->sendToTeam(
            team: $team,
            permission: $permission,
            type: TeamNotificationType::WorkflowNotification,
            eventKey: 'workflow:'.Str::limit($eventKey, 180, ''),
            title: Str::limit(trim($title), 120, ''),
            message: Str::limit(trim($message), 500, ''),
            data: [],
        );
    }

    /**
     * @param  array{bot_name?: string|null, target_type?: string|null, target_reference?: string|int|null}  $data
     */
    private function sendToTeam(
        Team $team,
        TeamPermission $permission,
        TeamNotificationType $type,
        string $eventKey,
        string $title,
        string $message,
        array $data,
    ): void {
        try {
            $recipients = $this->authorization->membersWithPermission($team, $permission);
        } catch (Throwable $exception) {
            Log::warning('Team notification delivery failed.', [
                'team_id' => $team->getKey(),
                'notification_type' => $type->value,
                'event_key' => $eventKey,
                'exception' => $exception::class,
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            try {
                if ($this->alreadySent($recipient, $team, $eventKey)) {
                    continue;
                }

                $recipient->notifyNow(new TeamEventNotification(
                    notificationType: $type,
                    teamId: (int) $team->getKey(),
                    eventKey: $eventKey,
                    title: $title,
                    message: $message,
                    data: $data,
                ));
            } catch (Throwable $exception) {
                Log::warning('Team notification delivery failed for recipient.', [
                    'team_id' => $team->getKey(),
                    'recipient_id' => $recipient->getKey(),
                    'notification_type' => $type->value,
                    'event_key' => $eventKey,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    private function alreadySent(User $recipient, Team $team, string $eventKey): bool
    {
        return $recipient->notifications()
            ->where('team_id', $team->getKey())
            ->where('event_key', $eventKey)
            ->exists();
    }

    /**
     * @return array{bot_name?: string|null, target_type: string, target_reference: string|int}
     */
    private function target(string $type, string|int $reference, ?string $botName = null): array
    {
        return array_filter([
            'bot_name' => $botName,
            'target_type' => $type,
            'target_reference' => $reference,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function isPreview(?Conversation $conversation): bool
    {
        return data_get($conversation?->metadata, 'source') === 'dashboard_preview';
    }
}
