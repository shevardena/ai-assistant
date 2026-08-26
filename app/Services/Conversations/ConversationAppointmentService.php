<?php

namespace App\Services\Conversations;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\WidgetVisitor;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Conversations\Blocks\AppointmentSlotsBlock;
use App\Services\Conversations\Blocks\AppointmentSlotsStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

final class ConversationAppointmentService
{
    private const EXPIRY_MINUTES = 20;

    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * Persist one trusted pending appointment picker for this conversation.
     *
     * @param  list<array{slot_reference: string, provider_slot_reference: string, starts_at: string, ends_at: string|null, label: string|null}>  $slots
     */
    public function request(
        ToolExecutionContext $context,
        string $toolName,
        string $appointmentReference,
        ?string $title,
        string $timezone,
        array $slots,
    ): ToolResult {
        $conversation = $context->conversation;

        if ($conversation === null
            || (int) $conversation->bot_id !== (int) $context->bot->id
            || (int) $context->team->id !== (int) $context->bot->team_id) {
            return ToolResult::failure(
                'invalid_request',
                'The requested appointment times cannot be shown in this conversation.',
            );
        }

        $block = $this->database->connection()->transaction(function () use (
            $conversation,
            $context,
            $toolName,
            $appointmentReference,
            $title,
            $timezone,
            $slots,
        ): ?AppointmentSlotsBlock {
            $state = $this->lockedState($conversation);
            $memory = $this->memory($state);
            $active = is_array($memory['active_appointment'] ?? null) ? $memory['active_appointment'] : null;

            if (($active['status'] ?? null) === AppointmentSlotsStatus::Pending->value
                && ($active['tool_name'] ?? null) === $toolName
                && $this->belongsToConversation($active, $context->bot, $conversation)) {
                $existing = $this->blockFromState($active);

                if ($existing !== null) {
                    return $existing;
                }
            }

            $block = AppointmentSlotsBlock::fromDefinition(
                $appointmentReference,
                $title,
                $timezone,
                $this->publicSlots($slots),
            );

            if ($block === null) {
                return null;
            }

            $record = [
                'appointment_reference' => $block->appointmentReference,
                'tool_name' => $toolName,
                'status' => AppointmentSlotsStatus::Pending->value,
                'team_id' => (int) $context->team->id,
                'bot_id' => (int) $context->bot->id,
                'conversation_id' => (int) $conversation->id,
                'visitor_id' => $conversation->visitor_id,
                'title' => $block->title,
                'timezone' => $block->timezone,
                'slots' => $slots,
                'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES)->toIso8601String(),
            ];
            $appointments = is_array($memory['appointments'] ?? null) ? $memory['appointments'] : [];

            if (is_array($active)
                && is_string($active['appointment_reference'] ?? null)
                && ($active['status'] ?? null) === AppointmentSlotsStatus::Pending->value) {
                $appointments[$active['appointment_reference']]['status'] = AppointmentSlotsStatus::Cancelled->value;
            }

            $appointments[$block->appointmentReference] = $record;
            $memory['appointments'] = $appointments;
            $memory['active_appointment'] = $record;
            $state->update([
                'memory' => $memory,
                'version' => ((int) $state->version) + 1,
            ]);

            return $block;
        });

        if (! $block instanceof AppointmentSlotsBlock) {
            return ToolResult::failure(
                'invalid_request',
                'The appointment times could not be prepared safely.',
            );
        }

        return ToolResult::success([
            'ok' => false,
            'error' => 'select_slot',
            'message' => 'Choose an available appointment time to continue.',
        ], blocks: [$block->toArray()]);
    }

    public function select(
        Bot $bot,
        Conversation $conversation,
        string $appointmentReference,
        string $slotReference,
        ?WidgetVisitor $visitor = null,
    ): AppointmentSelection {
        abort_unless((int) $conversation->bot_id === (int) $bot->id, 404);

        return $this->database->connection()->transaction(function () use (
            $bot,
            $conversation,
            $appointmentReference,
            $slotReference,
            $visitor,
        ): AppointmentSelection {
            $state = $this->lockedState($conversation);
            $memory = $this->memory($state);
            $appointments = is_array($memory['appointments'] ?? null) ? $memory['appointments'] : [];
            $record = is_array($appointments[$appointmentReference] ?? null)
                ? $appointments[$appointmentReference]
                : null;

            if ($record === null || ! $this->belongsToConversation($record, $bot, $conversation)
                || ($visitor !== null && (int) ($record['visitor_id'] ?? 0) !== (int) $visitor->id)) {
                abort(404);
            }

            if (($record['status'] ?? null) !== AppointmentSlotsStatus::Pending->value) {
                abort(409, 'These appointment times are no longer selectable.');
            }

            if ($this->expired($record)) {
                $this->setStatus($memory, $appointmentReference, AppointmentSlotsStatus::Expired);
                $state->update([
                    'memory' => $memory,
                    'version' => ((int) $state->version) + 1,
                ]);
                abort(409, 'These appointment times have expired.');
            }

            $slot = collect((array) ($record['slots'] ?? []))->first(
                static fn (mixed $candidate): bool => is_array($candidate)
                    && ($candidate['slot_reference'] ?? null) === $slotReference,
            );

            if (! is_array($slot)) {
                abort(404);
            }

            $record['status'] = AppointmentSlotsStatus::Selected->value;
            $record['selected_slot_reference'] = $slotReference;
            $record['selected_slot'] = $slot;
            $record['selected_at'] = now()->toIso8601String();
            $appointments[$appointmentReference] = $record;
            $memory['appointments'] = $appointments;
            $memory['active_appointment'] = $record;
            $state->update([
                'memory' => $memory,
                'version' => ((int) $state->version) + 1,
            ]);

            $block = $this->blockFromState($record, AppointmentSlotsStatus::Selected);

            if ($block === null) {
                abort(500, 'The appointment selection could not be restored.');
            }

            return new AppointmentSelection(
                block: $block,
                runtimeContext: [
                    'appointment_reference' => $appointmentReference,
                    'selected_slot_reference' => $slotReference,
                    'starts_at' => (string) $slot['starts_at'],
                    'ends_at' => is_string($slot['ends_at'] ?? null) ? $slot['ends_at'] : null,
                    'timezone' => (string) $record['timezone'],
                ],
                displayMessage: 'I selected the appointment time for '.$this->displayTime((string) $slot['starts_at'], (string) $record['timezone']).'.',
            );
        });
    }

    public function blockForReference(Conversation $conversation, string $appointmentReference): ?AppointmentSlotsBlock
    {
        $memory = $conversation->state()->value('memory');
        $memory = is_array($memory) ? $memory : [];
        $record = $memory['appointments'][$appointmentReference] ?? null;

        return is_array($record) ? $this->blockFromState($record) : null;
    }

    /**
     * Resolve a public selected slot reference back to its trusted provider reference.
     *
     * @return array{provider_slot_reference: string, starts_at: string, ends_at: string|null, timezone: string}|null
     */
    public function selectedForContext(ToolExecutionContext $context): ?array
    {
        $selection = $context->runtimeContext['appointment_selection'] ?? null;

        if (! is_array($selection)
            || ! is_string($selection['appointment_reference'] ?? null)
            || ! is_string($selection['selected_slot_reference'] ?? null)) {
            return null;
        }

        $conversation = $context->conversation;

        if ($conversation === null) {
            return null;
        }

        $memory = $conversation->state()->value('memory');
        $memory = is_array($memory) ? $memory : [];
        $record = $memory['appointments'][$selection['appointment_reference']] ?? null;

        if (! is_array($record)
            || ! $this->belongsToConversation($record, $context->bot, $conversation)
            || ($record['status'] ?? null) !== AppointmentSlotsStatus::Selected->value
            || ($record['selected_slot_reference'] ?? null) !== $selection['selected_slot_reference']) {
            return null;
        }

        $slot = $record['selected_slot'] ?? null;

        if (! is_array($slot) || ! is_string($slot['provider_slot_reference'] ?? null)) {
            return null;
        }

        return [
            'provider_slot_reference' => $slot['provider_slot_reference'],
            'starts_at' => (string) $slot['starts_at'],
            'ends_at' => is_string($slot['ends_at'] ?? null) ? $slot['ends_at'] : null,
            'timezone' => (string) $record['timezone'],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function blockFromState(array $state, ?AppointmentSlotsStatus $status = null): ?AppointmentSlotsBlock
    {
        $reference = $state['appointment_reference'] ?? null;
        $timezone = $state['timezone'] ?? null;
        $slots = $state['slots'] ?? null;

        if (! is_string($reference) || ! is_string($timezone) || ! is_array($slots)) {
            return null;
        }

        $status ??= AppointmentSlotsStatus::tryFrom((string) ($state['status'] ?? ''));

        if ($status === null) {
            return null;
        }

        if ($status === AppointmentSlotsStatus::Pending && $this->expired($state)) {
            $status = AppointmentSlotsStatus::Expired;
        }

        return AppointmentSlotsBlock::fromDefinition(
            $reference,
            is_string($state['title'] ?? null) ? $state['title'] : null,
            $timezone,
            $this->publicSlots($slots),
            $status,
            is_string($state['selected_slot_reference'] ?? null) ? $state['selected_slot_reference'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function expired(array $state): bool
    {
        $expiresAt = $state['expires_at'] ?? null;

        if (! is_string($expiresAt)) {
            return true;
        }

        try {
            return CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo(now());
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  array<string, mixed>  $memory
     */
    private function setStatus(array &$memory, string $reference, AppointmentSlotsStatus $status): void
    {
        if (is_array($memory['appointments'][$reference] ?? null)) {
            $memory['appointments'][$reference]['status'] = $status->value;
        }

        if (is_array($memory['active_appointment'] ?? null)
            && ($memory['active_appointment']['appointment_reference'] ?? null) === $reference) {
            $memory['active_appointment']['status'] = $status->value;
        }
    }

    private function lockedState(Conversation $conversation): ConversationState
    {
        $state = ConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->lockForUpdate()
            ->first();

        return $state ?? ConversationState::create([
            'conversation_id' => $conversation->id,
            'active_search' => null,
            'last_result_ids' => [],
            'memory' => [],
            'version' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function memory(ConversationState $state): array
    {
        $memory = $state->getAttribute('memory');

        return is_array($memory) ? $memory : [];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function belongsToConversation(array $record, Bot $bot, Conversation $conversation): bool
    {
        return (int) ($record['team_id'] ?? 0) === (int) $bot->team_id
            && (int) ($record['bot_id'] ?? 0) === (int) $bot->id
            && (int) ($record['conversation_id'] ?? 0) === (int) $conversation->id
            && (int) ($record['visitor_id'] ?? 0) === (int) ($conversation->visitor_id ?? 0);
    }

    /**
     * @param  array<mixed>  $slots
     * @return list<array<string, mixed>>
     */
    private function publicSlots(array $slots): array
    {
        $public = [];

        foreach ($slots as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $public[] = array_filter([
                'slot_reference' => $slot['slot_reference'] ?? null,
                'starts_at' => $slot['starts_at'] ?? null,
                'ends_at' => $slot['ends_at'] ?? null,
                'label' => $slot['label'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $public;
    }

    private function displayTime(string $startsAt, string $timezone): string
    {
        try {
            return CarbonImmutable::parse($startsAt)->setTimezone($timezone)->format('F j, Y \a\t g:i A T');
        } catch (\Throwable) {
            return 'the selected time';
        }
    }
}
