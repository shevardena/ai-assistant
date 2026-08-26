<?php

use App\Enums\PipelineStageSemanticType;
use App\Enums\TaskStatus;
use App\Enums\TeamRole;
use App\Models\Customer;
use App\Models\Task;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Customers\CustomerMergeService;
use App\Services\Deals\DealService;
use App\Services\Deals\PipelineService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

function taskContext(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $customer = Customer::factory()->create(['team_id' => $team->id]);

    return compact('user', 'team', 'customer');
}

test('tasks support team scoped creation, editing, lifecycle history, and no hard delete', function (): void {
    $context = taskContext();
    $service = app(TaskService::class);
    $task = $service->create($context['team'], ['title' => 'Call customer', 'description' => 'Discuss renewal', 'priority' => 'high', 'assigned_user_id' => $context['user']->id, 'customer_id' => $context['customer']->id, 'due_at' => now()->addDay()], $context['user']);

    expect($task->status)->toBe(TaskStatus::Open)->and($task->customer_id)->toBe($context['customer']->id)->and($task->created_by_user_id)->toBe($context['user']->id);
    $service->update($context['team'], $task, ['title' => 'Call customer again', 'description' => null, 'priority' => 'urgent', 'assigned_user_id' => null, 'customer_id' => $context['customer']->id], $context['user']);
    $service->complete($context['team'], $task, $context['user']);
    expect($task->fresh()->status)->toBe(TaskStatus::Completed)->and($task->fresh()->completed_at)->not->toBeNull();
    $service->update($context['team'], $task, ['title' => 'Completed follow-up', 'description' => null, 'priority' => 'normal', 'assigned_user_id' => null, 'customer_id' => $context['customer']->id], $context['user']);
    $service->reopen($context['team'], $task, $context['user']);
    $service->cancel($context['team'], $task, $context['user']);

    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled)->and(Task::query()->whereKey($task->id)->exists())->toBeTrue()->and(fn () => $service->complete($context['team'], $task, $context['user']))->toThrow(ValidationException::class);
});

test('deal links derive the strongest customer and preserve explicit CRM relations', function (): void {
    $context = taskContext();
    $pipeline = app(PipelineService::class)->ensureDefault($context['team'])['pipeline'];
    $stage = $pipeline->stages()->where('semantic_type', PipelineStageSemanticType::Open->value)->firstOrFail();
    $deal = app(DealService::class)->create($context['team'], ['title' => 'Renewal', 'customer_id' => $context['customer']->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'currency' => 'USD'], $context['user']);
    $task = app(TaskService::class)->create($context['team'], ['title' => 'Deal follow-up', 'deal_id' => $deal->id, 'customer_id' => null], $context['user']);

    expect($task->deal_id)->toBe($deal->id)->and($task->customer_id)->toBe($context['customer']->id);
});

test('overdue and upcoming scopes derive from due date and active status', function (): void {
    $context = taskContext();
    CarbonImmutable::setTestNow('2026-08-25 12:00:00');
    $service = app(TaskService::class);
    $overdue = $service->create($context['team'], ['title' => 'Past', 'assigned_user_id' => $context['user']->id, 'due_at' => '2026-08-24 12:00:00'], $context['user']);
    $service->create($context['team'], ['title' => 'Soon', 'assigned_user_id' => $context['user']->id, 'due_at' => '2026-08-26 12:00:00'], $context['user']);
    $service->complete($context['team'], $overdue, $context['user']);

    expect($service->index($context['team'], ['scope' => 'overdue'], $context['user'])['tasks']->total())->toBe(0)->and($service->index($context['team'], ['scope' => 'upcoming'], $context['user'])['tasks']->total())->toBe(1)->and($service->index($context['team'], ['scope' => 'completed'], $context['user'])['tasks']->total())->toBe(1);
    CarbonImmutable::setTestNow();
});

test('task index supports all, mine, search, relation filters, and pagination', function (): void {
    $context = taskContext();
    $assignee = User::factory()->create();
    $context['team']->members()->attach($assignee, ['role' => TeamRole::Member->value]);
    app(TaskService::class)->create($context['team'], ['title' => 'Alpha customer call', 'customer_id' => $context['customer']->id, 'assigned_user_id' => $context['user']->id], $context['user']);
    app(TaskService::class)->create($context['team'], ['title' => 'Beta internal task', 'assigned_user_id' => $assignee->id], $context['user']);
    $service = app(TaskService::class);

    expect($service->index($context['team'], ['scope' => 'my', 'search' => 'Alpha'], $context['user'])['tasks']->total())->toBe(1)->and($service->index($context['team'], ['scope' => 'all'], $context['user'])['tasks']->total())->toBe(2)->and($service->index($context['team'], ['customer_id' => $context['customer']->id], $context['user'])['tasks']->total())->toBe(1);
});

test('task routes expose inertial list, create, detail, and complete flows', function (): void {
    $context = taskContext();
    $task = app(TaskService::class)->create($context['team'], ['title' => 'Route task', 'assigned_user_id' => $context['user']->id], $context['user']);

    $this->actingAs($context['user'])->get(route('tasks.index', $context['team']->slug))->assertSuccessful()->assertInertia(fn ($page) => $page->component('tasks/index')->has('tasks'));
    $this->actingAs($context['user'])->get(route('tasks.create', $context['team']->slug))->assertSuccessful()->assertInertia(fn ($page) => $page->component('tasks/create')->has('priorityOptions'));
    $this->actingAs($context['user'])->get(route('tasks.show', [$context['team']->slug, $task]))->assertSuccessful()->assertInertia(fn ($page) => $page->component('tasks/show')->where('task.id', $task->id));
    $this->actingAs($context['user'])->post(route('tasks.complete', [$context['team']->slug, $task]))->assertRedirect();
    expect($task->fresh()->status)->toBe(TaskStatus::Completed);
});

test('analysts can view tasks while task records remain team isolated', function (): void {
    $context = taskContext();
    $analyst = User::factory()->create();
    $context['team']->members()->attach($analyst, ['role' => TeamRole::Analyst->value]);
    $analyst->switchTeam($context['team']);
    $otherUser = User::factory()->create();
    $otherTeam = $otherUser->currentTeam;
    $foreignTask = Task::factory()->for($otherTeam)->create();

    $this->actingAs($analyst)->get(route('tasks.index', $context['team']->slug))->assertSuccessful();
    $this->actingAs($analyst)->post(route('tasks.store', $context['team']->slug), [])->assertForbidden();
    $this->actingAs($context['user'])->get(route('tasks.show', [$context['team']->slug, $foreignTask]))->assertNotFound();
});

test('customer merges preserve task records and reassign their customer relation', function (): void {
    $context = taskContext();
    $destination = Customer::factory()->create(['team_id' => $context['team']->id]);
    $task = app(TaskService::class)->create($context['team'], ['title' => 'Merge follow-up', 'customer_id' => $context['customer']->id], $context['user']);
    app(CustomerMergeService::class)->merge($context['team'], $context['customer'], $destination, $context['user']);

    expect($task->fresh()->customer_id)->toBe($destination->id)->and(Task::query()->whereKey($task->id)->exists())->toBeTrue();
});

test('manual task operations never create tool runs', function (): void {
    $context = taskContext();
    $before = ToolRun::query()->count();
    app(TaskService::class)->create($context['team'], ['title' => 'No tool run'], $context['user']);

    expect(ToolRun::query()->count())->toBe($before);
});
