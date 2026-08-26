<?php

use App\Enums\AppointmentStatus;
use App\Enums\LeadStatus;
use App\Enums\SupportTicketStatus;
use App\Enums\TeamRole;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowConditionOperator;
use App\Enums\WorkflowConditionType;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowTriggerType;
use App\Models\Appointment;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Workflows\WorkflowEngine;
use App\Services\Workflows\WorkflowTriggerService;

function workflowContext(TeamRole $role = TeamRole::Owner): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    return [$user, $team, $bot];
}

function activeWorkflow(Team $team, array $attributes = []): Workflow
{
    $workflow = Workflow::factory()->create([
        'team_id' => $team->id,
        'status' => WorkflowStatus::Active->value,
        'is_enabled' => true,
        ...$attributes,
    ]);
    $workflow->actions()->create([
        'type' => WorkflowActionType::UpdateLeadStatus->value,
        'config' => ['status' => LeadStatus::Qualified->value],
        'position' => 0,
    ]);

    return $workflow;
}

test('team members can create and update draft workflows without trusting team input', function (): void {
    [$user, $team, $bot] = workflowContext();

    $response = $this->actingAs($user)->post(route('workflows.store', $team->slug), [
        'name' => 'Qualify sales leads',
        'description' => 'A bounded workflow.',
        'trigger_type' => WorkflowTriggerType::LeadCaptured->value,
        'conditions' => [[
            'type' => WorkflowConditionType::BotEquals->value,
            'operator' => WorkflowConditionOperator::Equals->value,
            'value' => (string) $bot->id,
        ]],
        'actions' => [[
            'type' => WorkflowActionType::UpdateLeadStatus->value,
            'config' => ['status' => LeadStatus::Qualified->value],
        ]],
        'team_id' => Team::factory()->create()->id,
    ]);

    $workflow = Workflow::query()->where('team_id', $team->id)->firstOrFail();
    $response->assertRedirect(route('workflows.show', [$team->slug, $workflow]));
    expect($workflow->status)->toBe(WorkflowStatus::Draft)
        ->and($workflow->conditions)->toHaveCount(1)
        ->and($workflow->actions)->toHaveCount(1);

    $this->actingAs($user)->put(route('workflows.update', [$team->slug, $workflow]), [
        'name' => 'Qualify priority leads',
        'description' => 'Updated bounded workflow.',
        'trigger_type' => WorkflowTriggerType::LeadCaptured->value,
        'conditions' => [[
            'type' => WorkflowConditionType::BotEquals->value,
            'operator' => WorkflowConditionOperator::Equals->value,
            'value' => (string) $bot->id,
        ]],
        'actions' => [[
            'type' => WorkflowActionType::UpdateLeadStatus->value,
            'config' => ['status' => LeadStatus::Qualified->value],
        ]],
        'status' => WorkflowStatus::Draft->value,
    ])->assertRedirect(route('workflows.show', [$team->slug, $workflow]));

    expect($workflow->fresh()->name)->toBe('Qualify priority leads');

    $this->actingAs($user)
        ->patch(route('workflows.activate', [$team->slug, $workflow]))
        ->assertRedirect();
    expect($workflow->fresh()->status)->toBe(WorkflowStatus::Active);

    $this->actingAs($user)
        ->patch(route('workflows.disable', [$team->slug, $workflow]))
        ->assertRedirect();
    expect($workflow->fresh()->status)->toBe(WorkflowStatus::Disabled);
});

test('workflow resources are isolated to the current team', function (): void {
    [$user, $team] = workflowContext();
    $foreignTeam = Team::factory()->create();
    $workflow = Workflow::factory()->create(['team_id' => $foreignTeam->id]);

    $this->actingAs($user)
        ->get(route('workflows.show', [$team->slug, $workflow]))
        ->assertNotFound();
});

test('analysts can view workflows while support agents cannot manage them', function (): void {
    [$analyst, $team] = workflowContext(TeamRole::Analyst);
    $this->actingAs($analyst)->get(route('workflows.index', $team->slug))->assertSuccessful();
    $this->actingAs($analyst)->get(route('workflows.create', $team->slug))->assertForbidden();

    [$supportAgent, $supportTeam] = workflowContext(TeamRole::SupportAgent);
    $this->actingAs($supportAgent)->get(route('workflows.index', $supportTeam->slug))->assertForbidden();
});

test('lead triggers evaluate all conditions, execute ordered actions, and are idempotent', function (): void {
    [$user, $team, $bot] = workflowContext();
    $workflow = activeWorkflow($team);
    $workflow->conditions()->create(['type' => WorkflowConditionType::BotEquals->value, 'operator' => WorkflowConditionOperator::Equals->value, 'value' => (string) $bot->id, 'position' => 0]);
    $workflow->actions()->create([
        'type' => WorkflowActionType::SendInAppNotification->value,
        'config' => ['permission' => 'leads.view', 'title' => 'New lead', 'message' => 'A lead needs attention.'],
        'position' => 1,
    ]);
    $lead = Lead::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'status' => LeadStatus::New->value]);

    app(WorkflowTriggerService::class)->leadCaptured($lead);
    app(WorkflowTriggerService::class)->leadCaptured($lead->fresh());

    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified)
        ->and($workflow->runs()->count())->toBe(1)
        ->and($workflow->runs()->firstOrFail()->status->value)->toBe('completed')
        ->and($workflow->runs()->firstOrFail()->actions()->pluck('position')->all())->toBe([0, 1]);
});

test('non-matching AND conditions persist a skipped run without executing actions', function (): void {
    [, $team, $bot] = workflowContext();
    $workflow = activeWorkflow($team);
    $workflow->conditions()->createMany([
        ['type' => WorkflowConditionType::BotEquals->value, 'operator' => WorkflowConditionOperator::Equals->value, 'value' => (string) $bot->id, 'position' => 0],
        ['type' => WorkflowConditionType::SourceEquals->value, 'operator' => WorkflowConditionOperator::Equals->value, 'value' => 'api', 'position' => 1],
    ]);
    $lead = Lead::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'source' => 'widget']);

    app(WorkflowTriggerService::class)->leadCaptured($lead);

    expect($workflow->runs()->firstOrFail()->status->value)->toBe('skipped')
        ->and($lead->fresh()->status)->toBe(LeadStatus::New);
});

test('appointment, support ticket, and handoff triggers execute their trusted actions', function (): void {
    [$user, $team, $bot] = workflowContext();
    $appointmentWorkflow = activeWorkflow($team, ['trigger_type' => WorkflowTriggerType::AppointmentBooked->value]);
    $appointmentWorkflow->actions()->delete();
    $appointmentWorkflow->actions()->create(['type' => WorkflowActionType::UpdateAppointmentStatus->value, 'config' => ['status' => AppointmentStatus::Completed->value], 'position' => 0]);
    $appointment = Appointment::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id]);

    $ticketWorkflow = activeWorkflow($team, ['trigger_type' => WorkflowTriggerType::SupportTicketCreated->value]);
    $ticketWorkflow->actions()->delete();
    $ticketWorkflow->actions()->create(['type' => WorkflowActionType::UpdateSupportTicketStatus->value, 'config' => ['status' => SupportTicketStatus::Resolved->value], 'position' => 0]);
    $ticket = SupportTicket::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id]);

    $handoffWorkflow = activeWorkflow($team, ['trigger_type' => WorkflowTriggerType::HumanHandoffRequested->value]);
    $handoffWorkflow->actions()->delete();
    $handoffWorkflow->actions()->create(['type' => WorkflowActionType::SendInAppNotification->value, 'config' => ['permission' => 'conversations.handoff', 'title' => 'Handoff', 'message' => 'A handoff was requested.'], 'position' => 0]);
    $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'metadata' => ['source' => 'widget']]);

    $triggers = app(WorkflowTriggerService::class);
    $triggers->appointmentBooked($appointment);
    $triggers->supportTicketCreated($ticket);
    $triggers->humanHandoffRequested($conversation, 'customer_requested');

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Completed)
        ->and($ticket->fresh()->status)->toBe(SupportTicketStatus::Resolved)
        ->and($appointmentWorkflow->runs()->count())->toBe(1)
        ->and($ticketWorkflow->runs()->count())->toBe(1)
        ->and($handoffWorkflow->runs()->count())->toBe(1);
});

test('preview contexts do not execute active workflows', function (): void {
    [, $team, $bot] = workflowContext();
    $workflow = activeWorkflow($team);
    $lead = Lead::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id]);
    app(WorkflowEngine::class)->dispatch(WorkflowTriggerType::LeadCaptured, $team, ['bot' => $bot, 'lead' => $lead, 'preview' => true], 'lead:'.$lead->public_id);

    expect($workflow->runs()->count())->toBe(0)
        ->and($lead->fresh()->status)->toBe(LeadStatus::New);
});

test('a failed action stops later actions and depth limits fail safely', function (): void {
    [, $team, $bot] = workflowContext();
    $workflow = activeWorkflow($team);
    $workflow->actions()->create(['type' => WorkflowActionType::SendInAppNotification->value, 'config' => [], 'position' => 1]);
    $workflow->actions()->create(['type' => WorkflowActionType::UpdateLeadStatus->value, 'config' => ['status' => LeadStatus::Lost->value], 'position' => 2]);
    $lead = Lead::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id]);

    app(WorkflowTriggerService::class)->leadCaptured($lead);
    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified)
        ->and($workflow->runs()->firstOrFail()->status->value)->toBe('failed');

    $depthWorkflow = activeWorkflow($team, ['name' => 'Depth guard']);
    app(WorkflowEngine::class)->dispatch(
        WorkflowTriggerType::LeadCaptured,
        $team,
        ['bot' => $bot, 'lead' => $lead],
        'lead:depth-guard',
        null,
        5,
    );

    expect($depthWorkflow->runs()->firstOrFail()->error_code)->toBe('depth_limit');
});
