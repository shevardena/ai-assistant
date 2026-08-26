<?php

use App\Enums\ApiOperationMode;
use App\Enums\ToolRunStatus;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\Conversation;
use App\Models\DataSource;
use App\Models\Message;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Ai\AiToolSchemaBuilder;
use App\Services\Ai\BotToolRegistry;
use App\Services\Ai\Tools\BookAppointmentTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Ai\WriteActionManager;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $settings
 * @param  array<string, mixed>  $operationOverrides
 * @return array{0: User, 1: Bot, 2: ApiOperation, 3: BotApiOperation, 4: ToolExecutionContext, 5: ApiOperation|null}
 */
function createBookAppointmentContext(
    array $settings = [],
    array $operationOverrides = [],
    bool $withAvailability = false,
): array {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $dataSource = DataSource::factory()->ready()->create([
        'team_id' => $team->id,
        'type' => 'rest_api',
        'config' => ['base_url' => 'https://calendar.example.test'],
    ]);

    $operation = ApiOperation::factory()->create([
        'data_source_id' => $dataSource->id,
        'key' => 'appointment_create',
        'name' => 'Book appointment',
        'type' => 'action',
        'execution_mode' => ApiOperationMode::Write->value,
        'method' => 'POST',
        'path' => '/appointments',
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'appointment_time' => ['type' => 'string'],
                'timezone' => ['type' => 'string'],
                'service_code' => ['type' => 'string'],
                'location_code' => ['type' => 'string'],
                'customer_name' => ['type' => 'string'],
                'customer_email' => ['type' => 'string'],
                'customer_phone' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'slot_reference' => ['type' => 'string'],
            ],
            'required' => ['appointment_time', 'customer_email'],
            'additionalProperties' => false,
        ],
        'request_mapping' => [
            'body' => [
                'appointment_time' => 'appointment.start_at',
                'timezone' => 'appointment.timezone',
                'service_code' => 'appointment.service',
                'location_code' => 'appointment.location',
                'customer_name' => 'customer.name',
                'customer_email' => 'customer.email',
                'customer_phone' => 'customer.phone',
                'notes' => 'appointment.notes',
                'slot_reference' => 'appointment.slot_reference',
            ],
            'idempotency_header' => 'Idempotency-Key',
        ],
        'response_mapping' => [
            'output' => [
                'appointment_reference' => 'data.reference',
                'start_at' => 'data.start_at',
                'status' => 'data.status',
            ],
        ],
        ...$operationOverrides,
    ]);

    $defaultSettings = [
        'input_mapping' => [
            'start_at' => [
                'source' => 'model_input',
                'model_input' => 'start_at',
                'operation_argument' => 'appointment_time',
            ],
            'timezone' => [
                'source' => 'model_input',
                'model_input' => 'timezone',
                'operation_argument' => 'timezone',
            ],
            'service' => [
                'source' => 'model_input',
                'model_input' => 'service',
                'operation_argument' => 'service_code',
            ],
            'location' => [
                'source' => 'model_input',
                'model_input' => 'location',
                'operation_argument' => 'location_code',
            ],
            'name' => [
                'source' => 'model_input',
                'model_input' => 'name',
                'operation_argument' => 'customer_name',
            ],
            'email' => [
                'source' => 'model_input',
                'model_input' => 'email',
                'operation_argument' => 'customer_email',
            ],
            'phone' => [
                'source' => 'model_input',
                'model_input' => 'phone',
                'operation_argument' => 'customer_phone',
            ],
            'notes' => [
                'source' => 'model_input',
                'model_input' => 'notes',
                'operation_argument' => 'notes',
            ],
        ],
    ];

    $attachment = BotApiOperation::factory()->create([
        'bot_id' => $bot->id,
        'api_operation_id' => $operation->id,
        'tool_name' => 'book_appointment',
        'is_enabled' => true,
        'settings' => $settings ?: $defaultSettings,
    ]);
    $availabilityOperation = null;

    if ($withAvailability) {
        $availabilityOperation = ApiOperation::factory()->create([
            'data_source_id' => $dataSource->id,
            'key' => 'appointment_availability',
            'name' => 'Appointment availability',
            'type' => 'query',
            'execution_mode' => ApiOperationMode::Read->value,
            'method' => 'POST',
            'path' => '/appointments/availability',
            'request_schema' => [
                'type' => 'object',
                'properties' => [
                    'appointment_time' => ['type' => 'string'],
                    'timezone' => ['type' => 'string'],
                    'service_code' => ['type' => 'string'],
                    'location_code' => ['type' => 'string'],
                ],
                'required' => ['appointment_time'],
                'additionalProperties' => false,
            ],
            'request_mapping' => [
                'body' => [
                    'appointment_time' => 'appointment.start_at',
                    'timezone' => 'appointment.timezone',
                    'service_code' => 'appointment.service',
                    'location_code' => 'appointment.location',
                ],
            ],
            'response_mapping' => [
                'output' => [
                    'available' => 'data.available',
                    'slot_reference' => [
                        'path' => 'data.slot_reference',
                        'required' => false,
                    ],
                ],
            ],
        ]);
        BotApiOperation::factory()->create([
            'bot_id' => $bot->id,
            'api_operation_id' => $availabilityOperation->id,
            'tool_name' => 'appointment_availability',
            'is_enabled' => true,
            'settings' => [
                'input_mapping' => [
                    'start_at' => [
                        'source' => 'model_input',
                        'model_input' => 'start_at',
                        'operation_argument' => 'appointment_time',
                    ],
                    'timezone' => [
                        'source' => 'model_input',
                        'model_input' => 'timezone',
                        'operation_argument' => 'timezone',
                    ],
                    'service' => [
                        'source' => 'model_input',
                        'model_input' => 'service',
                        'operation_argument' => 'service_code',
                    ],
                    'location' => [
                        'source' => 'model_input',
                        'model_input' => 'location',
                        'operation_argument' => 'location_code',
                    ],
                ],
            ],
        ]);
    }

    $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
    ]);

    return [
        $user,
        $bot,
        $operation,
        $attachment,
        ToolExecutionContext::forBot($bot, $conversation, $message),
        $availabilityOperation,
    ];
}

/**
 * @param  array<string, mixed>  $arguments
 */
function executeBookAppointment(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
{
    return app(BookAppointmentTool::class)->execute($bot, $arguments, $context);
}

test('book_appointment exposes strict fields with operation-driven required inputs', function () {
    [, $bot] = createBookAppointmentContext();
    $tool = app(BotToolRegistry::class)->find($bot, 'book_appointment');
    $schema = app(AiToolSchemaBuilder::class)->build($tool, $bot);

    expect($tool)->toBeInstanceOf(BookAppointmentTool::class)
        ->and($schema)->toMatchArray([
            'type' => 'function',
            'name' => 'book_appointment',
            'strict' => true,
        ])
        ->and($schema['parameters']['required'])->toBe(['start_at', 'email'])
        ->and($schema['parameters']['additionalProperties'])->toBeFalse()
        ->and($schema['parameters']['properties'])->toHaveKeys([
            'start_at',
            'timezone',
            'service',
            'location',
            'name',
            'email',
            'phone',
            'notes',
        ]);
});

test('registry requires an enabled write operation but no dataset or availability operation', function () {
    [, $bot, $operation, $attachment] = createBookAppointmentContext();

    expect(app(BotToolRegistry::class)->find($bot, 'book_appointment'))
        ->toBeInstanceOf(BookAppointmentTool::class);

    $operation->update(['execution_mode' => ApiOperationMode::Read->value]);
    expect(app(BotToolRegistry::class)->find($bot, 'book_appointment'))->toBeNull();

    $operation->update(['execution_mode' => ApiOperationMode::Write->value, 'is_enabled' => false]);
    expect(app(BotToolRegistry::class)->find($bot, 'book_appointment'))->toBeNull();

    $operation->update(['is_enabled' => true]);
    $attachment->update(['is_enabled' => false]);
    expect(app(BotToolRegistry::class)->find($bot, 'book_appointment'))->toBeNull();
});

test('invalid timezone, date-time, email, oversized, and unexpected inputs are rejected', function () {
    Http::preventStrayRequests();
    [, $bot, , , $context] = createBookAppointmentContext();

    expect(executeBookAppointment($bot, [
        'start_at' => '2026-08-28T15:00',
        'timezone' => 'Not/AZone',
        'email' => 'jane@example.com',
    ], $context)->data)->toMatchArray(['ok' => false, 'error' => 'invalid_arguments'])
        ->and(executeBookAppointment($bot, [
            'start_at' => '2026-02-30T15:00',
            'timezone' => 'Asia/Tbilisi',
            'email' => 'jane@example.com',
        ], $context)->data)->toMatchArray(['ok' => false, 'error' => 'invalid_arguments'])
        ->and(executeBookAppointment($bot, [
            'start_at' => '2026-08-28T15:00',
            'timezone' => 'Asia/Tbilisi',
            'email' => 'not-an-email',
        ], $context)->data)->toMatchArray(['ok' => false, 'error' => 'invalid_arguments'])
        ->and(executeBookAppointment($bot, [
            'start_at' => '2026-08-28T15:00',
            'timezone' => 'Asia/Tbilisi',
            'notes' => str_repeat('x', 2001),
            'email' => 'jane@example.com',
        ], $context)->data)->toMatchArray(['ok' => false, 'error' => 'invalid_arguments'])
        ->and(executeBookAppointment($bot, [
            'start_at' => '2026-08-28T15:00',
            'timezone' => 'Asia/Tbilisi',
            'email' => 'jane@example.com',
            'unexpected' => 'value',
        ], $context)->data)->toMatchArray(['ok' => false, 'error' => 'invalid_arguments']);
});

test('local date-times require an explicit timezone and nonexistent local times are rejected', function () {
    Http::preventStrayRequests();
    [, $bot, , , $context] = createBookAppointmentContext();

    expect(executeBookAppointment($bot, [
        'start_at' => '2026-08-28T15:00',
        'email' => 'jane@example.com',
    ], $context)->data)->toMatchArray(['ok' => false, 'error' => 'invalid_arguments'])
        ->and(executeBookAppointment($bot, [
            'start_at' => '2026-03-08T02:30',
            'timezone' => 'America/New_York',
            'email' => 'jane@example.com',
        ], $context)->data)->toMatchArray(['ok' => false, 'error' => 'invalid_arguments']);
});

test('valid booking proposes without making a booking write and normalizes local time', function () {
    Http::preventStrayRequests();
    [, $bot, , , $context] = createBookAppointmentContext();

    $result = executeBookAppointment($bot, [
        'start_at' => '2026-08-28T15:00',
        'timezone' => 'Asia/Tbilisi',
        'service' => 'consultation',
        'location' => 'main-office',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'notes' => 'Discuss the product demo.',
    ], $context);

    $run = ToolRun::query()->firstOrFail();

    expect($result->data['requires_confirmation'])->toBeTrue()
        ->and($result->data['summary'])->toContain('August 28, 2026 at 3:00 PM Asia/Tbilisi')
        ->and($run->status)->toBe(ToolRunStatus::PendingConfirmation)
        ->and($run->safe_arguments)->toMatchArray([
            'appointment_time' => '2026-08-28T15:00:00+04:00',
            'timezone' => 'Asia/Tbilisi',
            'service_code' => 'consultation',
            'location_code' => 'main-office',
            'customer_email' => 'jane@example.com',
        ]);
});

test('missing configured required data returns invalid_request without a proposal', function () {
    Http::preventStrayRequests();
    [, $bot, , , $context] = createBookAppointmentContext([], [
        'request_schema' => [
            'type' => 'object',
            'properties' => [
                'appointment_time' => ['type' => 'string'],
                'timezone' => ['type' => 'string'],
                'customer_email' => ['type' => 'string'],
            ],
            'required' => ['appointment_time', 'timezone', 'customer_email'],
        ],
    ]);

    $result = executeBookAppointment($bot, [
        'start_at' => '2026-08-28T15:00+04:00',
        'email' => 'jane@example.com',
    ], $context);

    expect($result->data)->toMatchArray(['ok' => false, 'error' => 'invalid_request'])
        ->and(ToolRun::query()->count())->toBe(0);
});

test('configured availability must approve the slot before proposal', function () {
    Http::fake([
        'https://calendar.example.test/*' => Http::response([
            'data' => [
                'available' => false,
                'slot_reference' => null,
            ],
        ]),
    ]);
    [, $bot, , , $context] = createBookAppointmentContext([], [], true);

    $result = executeBookAppointment($bot, [
        'start_at' => '2026-08-28T15:00+04:00',
        'email' => 'jane@example.com',
    ], $context);

    expect($result->data)->toMatchArray(['ok' => false, 'error' => 'slot_unavailable'])
        ->and(ToolRun::query()->count())->toBe(0);
    Http::assertSentCount(1);
});

test('configured availability collections render trusted appointment slots without proposing a write', function () {
    Http::fake([
        'https://calendar.example.test/*' => Http::response([
            'data' => [
                'slots' => [
                    [
                        'reference' => 'calendar-slot-1',
                        'starts_at' => '2026-08-28T15:00:00+04:00',
                        'ends_at' => '2026-08-28T15:30:00+04:00',
                        'label' => '3:00 PM',
                    ],
                ],
            ],
        ]),
    ]);
    [, $bot, $operation, , $context] = createBookAppointmentContext([], [], true);
    $operation->update([
        'response_mapping' => [
            'output' => [
                'appointment_reference' => 'data.reference',
                'start_at' => 'data.start_at',
                'status' => 'data.status',
            ],
        ],
    ]);
    $availabilityOperation = ApiOperation::query()
        ->where('key', 'appointment_availability')
        ->where('data_source_id', $operation->data_source_id)
        ->firstOrFail();
    $availabilityOperation->update([
        'response_mapping' => [
            'collection' => [
                'path' => 'data.slots',
                'fields' => [
                    'slot_reference' => 'reference',
                    'starts_at' => 'starts_at',
                    'ends_at' => 'ends_at',
                    'label' => 'label',
                ],
            ],
        ],
    ]);

    $result = executeBookAppointment($bot, [
        'start_at' => '2026-08-28T15:00+04:00',
        'timezone' => 'Asia/Tbilisi',
        'email' => 'jane@example.com',
    ], $context);

    expect($result->data)->toMatchArray(['ok' => false, 'error' => 'select_slot'])
        ->and($result->blocks[0]['type'])->toBe('appointment_slots')
        ->and($result->blocks[0]['data']['slots'][0])->not->toHaveKey('provider_slot_reference')
        ->and(ToolRun::query()->count())->toBe(0);
    Http::assertSentCount(1);
});

test('stale slot protection rechecks availability after confirmation and prevents the booking write', function () {
    Http::fakeSequence('https://calendar.example.test/*')
        ->push(['data' => ['available' => true, 'slot_reference' => 'slot-1']])
        ->push(['data' => ['available' => false, 'slot_reference' => null]]);
    [, $bot, , , $context] = createBookAppointmentContext([], [], true);
    $tool = app(BookAppointmentTool::class);

    $proposal = $tool->execute($bot, [
        'start_at' => '2026-08-28T15:00+04:00',
        'email' => 'jane@example.com',
    ], $context);
    $result = $tool->confirm($bot, $context, $proposal->data['action_reference']);
    $run = ToolRun::query()->firstOrFail();

    expect($result->data)->toMatchArray(['ok' => false, 'error' => 'slot_unavailable'])
        ->and($run->status)->toBe(ToolRunStatus::Failed)
        ->and($run->error_code)->toBe('slot_unavailable');
    Http::assertSentCount(2);
});

test('confirmed booking rechecks availability, writes once, and returns mapped safe output', function () {
    Http::fakeSequence('https://calendar.example.test/*')
        ->push(['data' => ['available' => true, 'slot_reference' => 'slot-1']])
        ->push(['data' => ['available' => true, 'slot_reference' => 'slot-2']])
        ->push([
            'data' => [
                'reference' => 'APT-1837',
                'start_at' => '2026-08-28T15:00:00+04:00',
                'status' => 'confirmed',
                'internal_note' => 'private',
            ],
        ], 201);
    [, $bot, , , $context] = createBookAppointmentContext([], [], true);
    $tool = app(BookAppointmentTool::class);
    $proposal = $tool->execute($bot, [
        'start_at' => '2026-08-28T15:00+04:00',
        'email' => 'jane@example.com',
    ], $context);
    $result = $tool->confirm($bot, $context, $proposal->data['action_reference']);
    $run = ToolRun::query()->firstOrFail();

    expect($result->data)->toMatchArray([
        'ok' => true,
        'status' => 'completed',
        'result' => [
            'appointment_reference' => 'APT-1837',
            'start_at' => '2026-08-28T15:00:00+04:00',
            'status' => 'confirmed',
        ],
    ])
        ->and($run->status)->toBe(ToolRunStatus::Completed)
        ->and($run->safe_result)->not->toHaveKey('internal_note');

    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://calendar.example.test/appointments'
            && $request->data()['appointment']['slot_reference'] === 'slot-2';
    });
    Http::assertSentCount(3);
});

test('booking works without availability when the write operation is authoritative', function () {
    Http::fake([
        'https://calendar.example.test/*' => Http::response([
            'data' => [
                'reference' => 'APT-1837',
                'start_at' => '2026-08-28T15:00:00+04:00',
                'status' => 'confirmed',
            ],
        ], 201),
    ]);
    [, $bot, , , $context] = createBookAppointmentContext();
    $tool = app(BookAppointmentTool::class);
    $proposal = $tool->execute($bot, [
        'start_at' => '2026-08-28T15:00+04:00',
        'email' => 'jane@example.com',
    ], $context);
    $result = $tool->confirm($bot, $context, $proposal->data['action_reference']);

    expect($result->data['status'])->toBe('completed');
    Http::assertSentCount(1);
});

test('duplicate confirmation creates one appointment and cancellation prevents execution', function () {
    Http::fake([
        'https://calendar.example.test/*' => Http::response([
            'data' => [
                'reference' => 'APT-1837',
                'start_at' => '2026-08-28T15:00:00+04:00',
                'status' => 'confirmed',
            ],
        ], 201),
    ]);
    [, $bot, , , $context] = createBookAppointmentContext();
    $tool = app(BookAppointmentTool::class);
    $proposal = $tool->execute($bot, [
        'start_at' => '2026-08-28T15:00+04:00',
        'email' => 'jane@example.com',
    ], $context);
    $first = $tool->confirm($bot, $context, $proposal->data['action_reference']);
    $second = $tool->confirm($bot, $context, $proposal->data['action_reference']);

    expect($second->data)->toBe($first->data);
    Http::assertSentCount(1);

    $newProposal = $tool->execute($bot, [
        'start_at' => '2026-08-29T15:00+04:00',
        'email' => 'jane@example.com',
    ], $context);
    $manager = app(WriteActionManager::class);
    $cancelled = $manager->cancel($bot, $context, $newProposal->data['action_reference']);
    $confirmed = $tool->confirm($bot, $context, $newProposal->data['action_reference']);

    expect($cancelled->data['status'])->toBe('cancelled')
        ->and($confirmed->data['error'])->toBe('action_cancelled')
        ->and(ToolRun::query()->where('status', ToolRunStatus::Cancelled)->count())->toBe(1);
    Http::assertSentCount(1);
});

test('foreign bot context cannot book through the appointment tool', function () {
    [, $bot, , , $context] = createBookAppointmentContext();
    $foreignUser = User::factory()->create();
    $foreignBot = Bot::factory()->create(['team_id' => $foreignUser->currentTeam->id]);
    $foreignConversation = Conversation::factory()->create(['bot_id' => $foreignBot->id]);
    $foreignMessage = Message::factory()->create([
        'conversation_id' => $foreignConversation->id,
        'role' => 'user',
    ]);
    $foreignContext = ToolExecutionContext::forBot($foreignBot, $foreignConversation, $foreignMessage);

    $result = executeBookAppointment($bot, [
        'start_at' => '2026-08-28T15:00+04:00',
        'email' => 'jane@example.com',
    ], $foreignContext);

    expect($result->data)->toMatchArray(['ok' => false, 'error' => 'action_not_available'])
        ->and(ToolRun::query()->count())->toBe(0);
});
