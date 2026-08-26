<?php

use App\Enums\AppointmentStatus;
use App\Enums\TeamRole;
use App\Enums\ToolRunStatus;
use App\Models\Appointment;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Appointments\AppointmentProjectionService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

function appointmentDashboardContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Appointments Team']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id, 'name' => 'Booking Bot']);

    return [$user, $team, $bot];
}

function completedAppointmentRun(Bot $bot, array $overrides = []): ToolRun
{
    return ToolRun::factory()->create([
        'team_id' => $bot->team_id,
        'bot_id' => $bot->id,
        'api_operation_id' => null,
        'tool_name' => 'book_appointment',
        'status' => ToolRunStatus::Completed->value,
        'safe_arguments' => ['__preflight' => ['start_at' => '2026-08-28T15:00:00+04:00', 'timezone' => 'Asia/Tbilisi', 'name' => 'Jane Doe', 'email' => 'jane@example.com', 'phone' => '+995555123456']],
        'safe_result' => ['appointment_reference' => 'APPT-42'],
        'completed_at' => now(),
        ...$overrides,
    ]);
}

test('completed bookings project once and expose only trusted appointment fields', function (): void {
    [, $team, $bot] = appointmentDashboardContext();
    $run = completedAppointmentRun($bot);
    $service = app(AppointmentProjectionService::class);
    $appointment = $service->project($run);
    $repeat = $service->project($run->fresh());

    expect($appointment)->toBeInstanceOf(Appointment::class)
        ->and($repeat?->id)->toBe($appointment?->id)
        ->and(Appointment::query()->where('team_id', $team->id)->count())->toBe(1)
        ->and($appointment?->starts_at?->toIso8601String())->toBe('2026-08-28T11:00:00+00:00')
        ->and($appointment?->customer_name)->toBe('Jane Doe')
        ->and($appointment?->customer_id)->not->toBeNull()
        ->and(Customer::query()->where('team_id', $team->id)->count())->toBe(1)
        ->and($appointment?->provider_reference)->toBe('APPT-42');
});

test('failed, cancelled, and preview bookings do not project', function (): void {
    [, , $bot] = appointmentDashboardContext();
    $service = app(AppointmentProjectionService::class);

    foreach ([ToolRunStatus::PendingConfirmation, ToolRunStatus::Failed, ToolRunStatus::Cancelled] as $status) {
        expect($service->project(completedAppointmentRun($bot, ['status' => $status->value])))->toBeNull();
    }

    $preview = Conversation::factory()->create(['bot_id' => $bot->id, 'metadata' => ['source' => 'dashboard_preview']]);
    expect($service->project(completedAppointmentRun($bot, ['conversation_id' => $preview->id])))->toBeNull()
        ->and(Appointment::query()->count())->toBe(0);
});

test('appointment dashboard is current-team scoped and supports internal status updates', function (): void {
    [$user, $team, $bot] = appointmentDashboardContext();
    $appointment = Appointment::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'starts_at' => Carbon::now()->addDays(2)]);
    $foreignTeam = Team::factory()->create();
    $foreignBot = Bot::factory()->create(['team_id' => $foreignTeam->id]);
    $foreign = Appointment::factory()->create(['team_id' => $foreignTeam->id, 'bot_id' => $foreignBot->id]);

    $this->actingAs($user)->get(route('appointments.index', ['current_team' => $team->slug, 'range' => 'all']))
        ->assertSuccessful()->assertInertia(fn (Assert $page) => $page->component('appointments/index')->where('appointments.total', 1)->where('summary.upcoming', 1));

    $this->actingAs($user)->patch(route('appointments.update', ['current_team' => $team->slug, 'appointment' => $appointment]), ['status' => AppointmentStatus::Completed->value])->assertRedirect();
    expect($appointment->fresh()?->status)->toBe(AppointmentStatus::Completed);

    $this->actingAs($user)->get(route('appointments.show', ['current_team' => $team->slug, 'appointment' => $foreign]))->assertNotFound();
});
