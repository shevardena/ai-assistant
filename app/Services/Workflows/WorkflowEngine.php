<?php

namespace App\Services\Workflows;

use App\Enums\WorkflowRunStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowTriggerType;
use App\Models\Team;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

final class WorkflowEngine
{
    private const MAX_DEPTH = 5;

    public function __construct(
        private readonly WorkflowConditionEvaluator $conditions,
        private readonly WorkflowActionExecutor $actions,
    ) {}

    /** @param array<string, mixed> $context */
    public function dispatch(WorkflowTriggerType $trigger, Team $team, array $context, string $reference, ?string $originRunId = null, int $depth = 0): void
    {
        if (($context['preview'] ?? false) === true || ($context['test'] ?? false) === true) {
            return;
        }

        $workflows = Workflow::query()
            ->where('team_id', $team->getKey())
            ->where('trigger_type', $trigger->value)
            ->where('status', WorkflowStatus::Active->value)
            ->where('is_enabled', true)
            ->with(['conditions', 'actions'])
            ->orderBy('id')
            ->get();

        foreach ($workflows as $workflow) {
            $run = $this->createRun($workflow, $team, $trigger, $reference, $originRunId, $depth);
            if (! $run) {
                continue;
            }

            if ($depth >= self::MAX_DEPTH) {
                $this->finish($run, WorkflowRunStatus::Failed, 'depth_limit');

                continue;
            }

            if (! $this->conditions->matches($workflow, $context)) {
                $this->finish($run, WorkflowRunStatus::Skipped);

                continue;
            }

            $run->update(['status' => WorkflowRunStatus::Running]);
            $failed = false;
            foreach ($workflow->actions as $position => $action) {
                $started = microtime(true);
                $runAction = WorkflowRunAction::query()->create([
                    'workflow_run_id' => $run->getKey(),
                    'workflow_action_id' => $action->getKey(),
                    'action_type' => $action->type->value,
                    'status' => 'running',
                    'position' => $position,
                    'started_at' => now(),
                ]);

                try {
                    $result = $this->actions->execute($action, ['team' => $team, ...$context], $run);
                } catch (\Throwable $exception) {
                    Log::warning('Workflow action failed.', ['workflow_id' => $workflow->getKey(), 'action_type' => $action->type->value, 'exception' => $exception::class]);
                    $result = ['ok' => false, 'summary' => 'The workflow action failed.', 'error_code' => 'execution_failed'];
                }

                $runAction->update([
                    'status' => $result['ok'] ? 'completed' : 'failed',
                    'safe_summary' => $result['summary'],
                    'error_code' => $result['error_code'] ?? null,
                    'finished_at' => now(),
                ]);

                if (! $result['ok']) {
                    $failed = true;
                    $this->finish($run, WorkflowRunStatus::Failed, $result['error_code'] ?? 'action_failed');
                    break;
                }
            }

            if (! $failed) {
                $this->finish($run, WorkflowRunStatus::Completed);
            }
        }
    }

    private function createRun(Workflow $workflow, Team $team, WorkflowTriggerType $trigger, string $reference, ?string $originRunId, int $depth): ?WorkflowRun
    {
        try {
            $run = WorkflowRun::query()->firstOrCreate(
                ['workflow_id' => $workflow->getKey(), 'trigger_type' => $trigger->value, 'trigger_reference' => $reference],
                ['team_id' => $team->getKey(), 'status' => WorkflowRunStatus::Running, 'started_at' => now(), 'origin_workflow_run_id' => $originRunId, 'depth' => $depth],
            );
        } catch (QueryException) {
            $run = WorkflowRun::query()->where('workflow_id', $workflow->getKey())->where('trigger_type', $trigger->value)->where('trigger_reference', $reference)->first();
        }

        return $run instanceof WorkflowRun && $run->wasRecentlyCreated ? $run : null;
    }

    private function finish(WorkflowRun $run, WorkflowRunStatus $status, ?string $errorCode = null): void
    {
        $startedAt = $run->started_at->getTimestampMs();
        $run->update(['status' => $status, 'error_code' => $errorCode, 'finished_at' => now(), 'duration_ms' => max(0, now()->getTimestampMs() - $startedAt)]);
    }
}
