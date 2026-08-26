<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\TaskIndexRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Task;
use App\Models\Team;
use App\Services\Tasks\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index(TaskIndexRequest $request, Team $currentTeam): Response
    {
        Gate::authorize('viewAny', Task::class);

        return Inertia::render('tasks/index', $this->tasks->index($currentTeam, $request->validated(), $request->user()));
    }

    public function create(Request $request, Team $currentTeam): Response
    {
        Gate::authorize('create', Task::class);

        return Inertia::render('tasks/create', ['prefill' => Arr::only($request->query(), ['customer_id', 'lead_id', 'deal_id', 'conversation_id', 'support_ticket_id', 'appointment_id', 'assigned_user_id']), ...$this->tasks->formOptions($currentTeam)]);
    }

    public function store(StoreTaskRequest $request, Team $currentTeam): RedirectResponse
    {
        Gate::authorize('create', Task::class);
        $task = $this->tasks->create($currentTeam, $request->validated(), $request->user());

        return to_route('tasks.show', [$currentTeam->slug, $task]);
    }

    public function show(Team $currentTeam, Task $task): Response
    {
        Gate::authorize('view', $task);

        return Inertia::render('tasks/show', $this->tasks->detail($currentTeam, $task));
    }

    public function update(UpdateTaskRequest $request, Team $currentTeam, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);
        $this->tasks->update($currentTeam, $task, $request->validated(), $request->user());

        return back()->with('success', 'Task updated.');
    }

    public function complete(Team $currentTeam, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);
        $this->tasks->complete($currentTeam, $task, request()->user());

        return back()->with('success', 'Task completed.');
    }

    public function reopen(Team $currentTeam, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);
        $this->tasks->reopen($currentTeam, $task, request()->user());

        return back()->with('success', 'Task reopened.');
    }

    public function cancel(Team $currentTeam, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);
        $this->tasks->cancel($currentTeam, $task, request()->user());

        return back()->with('success', 'Task cancelled.');
    }
}
