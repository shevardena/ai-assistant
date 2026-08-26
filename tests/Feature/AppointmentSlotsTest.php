<?php

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Conversations\AppointmentSlotNormalizer;
use App\Services\Conversations\ConversationAppointmentService;
use Symfony\Component\HttpKernel\Exception\HttpException;

function appointmentServiceContext(): array
{
    $user = User::factory()->create();
    $bot = Bot::factory()->create(['team_id' => $user->currentTeam->id]);
    $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
    ]);

    return [$user, $bot, $conversation, ToolExecutionContext::forBot($bot, $conversation, $message)];
}

test('appointment picker persists trusted slots and selection without booking', function () {
    [, $bot, $conversation, $context] = appointmentServiceContext();
    $slots = app(AppointmentSlotNormalizer::class)->normalize([
        [
            'slot_reference' => 'calendar-slot-1',
            'starts_at' => '2026-09-01T10:00:00+04:00',
            'ends_at' => '2026-09-01T10:30:00+04:00',
        ],
    ], 'Asia/Tbilisi');
    $service = app(ConversationAppointmentService::class);
    $result = $service->request(
        $context,
        'book_appointment',
        '123e4567-e89b-12d3-a456-426614174000',
        'Available times',
        'Asia/Tbilisi',
        $slots,
    );
    $appointmentReference = (string) data_get($result->blocks, '0.data.appointment_reference');
    $slotReference = (string) data_get($result->blocks, '0.data.slots.0.slot_reference');

    $selection = $service->select($bot, $conversation, $appointmentReference, $slotReference);

    expect($result->data)->toMatchArray(['ok' => false, 'error' => 'select_slot'])
        ->and($result->blocks[0])->not->toContain('calendar-slot-1')
        ->and($selection->block->status->value)->toBe('selected')
        ->and($selection->runtimeContext['selected_slot_reference'])->toBe($slotReference)
        ->and(ConversationState::query()->firstOrFail()->memory['active_appointment']['status'])->toBe('selected');
});

test('appointment selection rejects a forged slot, duplicate selection, and expired picker', function () {
    [, $bot, $conversation, $context] = appointmentServiceContext();
    $slots = app(AppointmentSlotNormalizer::class)->normalize([
        ['slot_reference' => 'calendar-slot-1', 'starts_at' => '2026-09-01T10:00:00+04:00'],
    ], 'Asia/Tbilisi');
    $service = app(ConversationAppointmentService::class);
    $result = $service->request($context, 'book_appointment', '123e4567-e89b-12d3-a456-426614174001', null, 'Asia/Tbilisi', $slots);
    $reference = (string) data_get($result->blocks, '0.data.appointment_reference');

    expect(fn () => $service->select($bot, $conversation, $reference, 'forged-slot'))
        ->toThrow(HttpException::class);

    $slotReference = (string) data_get($result->blocks, '0.data.slots.0.slot_reference');
    $service->select($bot, $conversation, $reference, $slotReference);

    expect(fn () => $service->select($bot, $conversation, $reference, $slotReference))
        ->toThrow(HttpException::class);

    $state = ConversationState::query()->firstOrFail();
    $memory = $state->memory;
    $memory['active_appointment']['status'] = 'pending';
    $memory['active_appointment']['expires_at'] = now()->subMinute()->toIso8601String();
    $memory['appointments'][$reference]['status'] = 'pending';
    $memory['appointments'][$reference]['expires_at'] = $memory['active_appointment']['expires_at'];
    $state->update(['memory' => $memory]);

    expect(fn () => $service->select($bot, $conversation, $reference, $slotReference))
        ->toThrow(HttpException::class);
});
