<?php

namespace App\Http\Controllers;

use App\Enums\WorkflowStatus;
use App\Http\Requests\StoreWorkflowRequest;
use App\Http\Requests\UpdateWorkflowRequest;
use App\Models\Team;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use App\Models\WorkflowCondition;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunAction;
use App\Services\Workflows\WorkflowMetadataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkflowController extends Controller
{
    public function __construct(private readonly WorkflowMetadataService $metadata) {}

    public function index(Team $currentTeam): Response
    {
        Gate::authorize('viewAny', Workflow::class);
        $workflows = $currentTeam->workflows()
            ->withCount(['conditions', 'actions'])
            ->with(['runs' => fn ($query) => $query->latest('created_at')->limit(1)])
            ->latest('updated_at')
            ->get()
            ->map(fn (Workflow $workflow): array => $this->listItem($workflow))
            ->values();

        return Inertia::render('workflows/index', ['workflows' => $workflows]);
    }

    public function create(Team $currentTeam): Response
    {
        Gate::authorize('create', Workflow::class);

        return Inertia::render('workflows/create', ['metadata' => $this->metadata->builderMetadata($currentTeam), 'workflow' => null]);
    }

    public function store(StoreWorkflowRequest $request, Team $currentTeam): RedirectResponse
    {
        $workflow = DB::transaction(fn (): Workflow => $this->persist($currentTeam, $request->validated(), $request->user()));

        return to_route('workflows.show', [$currentTeam->slug, $workflow]);
    }

    public function show(Team $currentTeam, Workflow $workflow): Response
    {
        $workflow = $this->scoped($currentTeam, $workflow);
        Gate::authorize('view', $workflow);

        return Inertia::render('workflows/show', $this->pageData($currentTeam, $workflow));
    }

    public function edit(Team $currentTeam, Workflow $workflow): Response
    {
        $workflow = $this->scoped($currentTeam, $workflow);
        Gate::authorize('update', $workflow);

        return Inertia::render('workflows/edit', $this->pageData($currentTeam, $workflow));
    }

    public function update(UpdateWorkflowRequest $request, Team $currentTeam, Workflow $workflow): RedirectResponse
    {
        $workflow = $this->scoped($currentTeam, $workflow);
        Gate::authorize('update', $workflow);
        $data = $request->validated();
        $this->assertActivatable($currentTeam, $data);
        $status = $data['status'] ?? $workflow->status->value;

        DB::transaction(function () use ($workflow, $data, $status): void {
            $workflow->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'trigger_type' => $data['trigger_type'],
                'status' => $status,
                'is_enabled' => $status === WorkflowStatus::Active->value,
            ]);
            $this->replaceDefinition($workflow, $data);
        });

        return to_route('workflows.show', [$currentTeam->slug, $workflow]);
    }

    public function activate(Team $currentTeam, Workflow $workflow): RedirectResponse
    {
        $workflow = $this->scoped($currentTeam, $workflow);
        Gate::authorize('update', $workflow);
        $data = $workflow->load(['conditions', 'actions'])->toArray();
        $data['conditions'] = $workflow->conditions->toArray();
        $data['actions'] = $workflow->actions->toArray();
        $data['trigger_type'] = $workflow->trigger_type->value;
        $this->assertActivatable($currentTeam, array_merge($data, ['status' => WorkflowStatus::Active->value]));
        $workflow->update(['status' => WorkflowStatus::Active, 'is_enabled' => true]);

        return back()->with('success', 'Workflow activated.');
    }

    public function disable(Team $currentTeam, Workflow $workflow): RedirectResponse
    {
        $workflow = $this->scoped($currentTeam, $workflow);
        Gate::authorize('update', $workflow);
        $workflow->update(['status' => WorkflowStatus::Disabled, 'is_enabled' => false]);

        return back()->with('success', 'Workflow disabled.');
    }

    /** @return array<string, mixed> */
    private function pageData(Team $team, Workflow $workflow): array
    {
        $workflow->load(['conditions', 'actions', 'runs.actions']);
        $workflow->loadCount(['conditions', 'actions']);
        $workflow->setRelation('runs', $workflow->runs->sortByDesc('created_at')->take(20)->values());

        return [
            'workflow' => $this->detailItem($workflow),
            'metadata' => $this->metadata->builderMetadata($team, $workflow->trigger_type),
            'runs' => $workflow->runs->map(fn (WorkflowRun $run): array => [
                'publicId' => $run->public_id,
                'status' => $run->status->value,
                'triggerType' => $run->trigger_type->value,
                'triggerReference' => $run->trigger_reference,
                'errorCode' => $run->error_code,
                'startedAt' => $run->started_at->toIso8601String(),
                'finishedAt' => $run->finished_at?->toIso8601String(),
                'actions' => $run->actions->map(fn (WorkflowRunAction $action): array => [
                    'type' => $action->action_type->value,
                    'status' => $action->status->value,
                    'position' => $action->position,
                    'safeSummary' => $action->safe_summary,
                    'errorCode' => $action->error_code,
                ])->values()->all(),
            ])->values(),
        ];
    }

    private function scoped(Team $team, Workflow $workflow): Workflow
    {
        return $team->workflows()->whereKey($workflow->getKey())->firstOrFail();
    }

    /** @param array<string, mixed> $data */
    private function persist(Team $team, array $data, User $user): Workflow
    {
        $workflow = $team->workflows()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'trigger_type' => $data['trigger_type'],
            'status' => WorkflowStatus::Draft,
            'is_enabled' => false,
            'created_by_user_id' => $user->getKey(),
        ]);
        $this->replaceDefinition($workflow, $data);

        return $workflow;
    }

    /** @param array<string, mixed> $data */
    private function replaceDefinition(Workflow $workflow, array $data): void
    {
        $workflow->conditions()->delete();
        $workflow->actions()->delete();

        foreach (array_values($data['conditions'] ?? []) as $position => $condition) {
            $workflow->conditions()->create([
                'type' => $condition['type'],
                'operator' => $condition['operator'],
                'value' => $condition['value'],
                'position' => $position,
            ]);
        }

        foreach (array_values($data['actions'] ?? []) as $position => $action) {
            $type = $action['type'];
            $config = is_array($action['config'] ?? null) ? $action['config'] : [];
            $allowedConfig = match ($type) {
                'send_in_app_notification' => ['permission', 'title', 'message'],
                'request_human_handoff' => ['reason'],
                default => ['status'],
            };
            $workflow->actions()->create([
                'type' => $type,
                'config' => array_intersect_key($config, array_flip($allowedConfig)),
                'position' => $position,
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function assertActivatable(Team $team, array $data): void
    {
        if (($data['status'] ?? null) !== WorkflowStatus::Active->value) {
            return;
        }

        $errors = $this->metadata->validateDefinition($team, $data);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, mixed> */
    private function listItem(Workflow $workflow): array
    {
        $lastRun = $workflow->runs->first();

        return [
            'publicId' => $workflow->public_id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'status' => $workflow->status->value,
            'triggerType' => $workflow->trigger_type->value,
            'isEnabled' => $workflow->is_enabled,
            'conditionCount' => $workflow->conditions_count,
            'actionCount' => $workflow->actions_count,
            'lastRun' => $lastRun ? ['status' => $lastRun->status->value, 'createdAt' => $lastRun->created_at?->toIso8601String()] : null,
            'updatedAt' => $workflow->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detailItem(Workflow $workflow): array
    {
        return [
            ...$this->listItem($workflow),
            'conditions' => $workflow->conditions->map(fn (WorkflowCondition $condition): array => ['type' => $condition->type->value, 'operator' => $condition->operator->value, 'value' => $condition->value])->values()->all(),
            'actions' => $workflow->actions->map(fn (WorkflowAction $action): array => ['type' => $action->type->value, 'config' => $action->config ?? []])->values()->all(),
            'createdAt' => $workflow->created_at?->toIso8601String(),
        ];
    }
}
