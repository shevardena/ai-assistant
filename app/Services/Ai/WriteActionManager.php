<?php

namespace App\Services\Ai;

use App\Enums\ApiOperationMode;
use App\Enums\PlanLimit;
use App\Enums\ToolRunStatus;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Bot;
use App\Models\ToolRun;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Api\RuntimeApiOperationResolver;
use App\Services\Api\RuntimeApiWriteOperationExecutor;
use App\Services\Billing\TeamEntitlementService;
use App\Services\Conversations\Blocks\ConfirmationBlock;
use App\Services\Conversations\Blocks\ConfirmationBlockStatus;
use App\Services\Operational\CompletedActionProjectionService;
use App\Services\Teams\TeamNotificationService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class WriteActionManager
{
    public function __construct(
        private readonly RuntimeApiOperationResolver $operationResolver,
        private readonly RuntimeApiWriteOperationExecutor $operationExecutor,
        private readonly ToolRunPayloadSanitizer $payloadSanitizer,
        private readonly CompletedActionProjectionService $projections,
        private readonly TeamNotificationService $notifications,
        private readonly TeamEntitlementService $entitlements,
    ) {}

    /**
     * Validate and persist a write proposal without making an outbound request.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|null  $preflightArguments
     */
    public function propose(
        Bot $bot,
        string $toolName,
        array $arguments,
        string $summary,
        ToolExecutionContext $context,
        ?array $preflightArguments = null,
    ): ToolResult {
        if (! $this->contextMatchesBot($bot, $context)) {
            return ToolResult::failure(
                'action_not_available',
                'This action is not available in the current conversation.',
            );
        }

        $runtimeOperation = $this->operationResolver->resolveWrite($bot, $toolName);

        if ($runtimeOperation === null) {
            return ToolResult::failure(
                'action_not_available',
                'This action is not available for the current bot.',
            );
        }

        $validation = $this->operationExecutor->validate($runtimeOperation, $arguments);

        if (! $validation->success) {
            return ToolResult::failure(
                $validation->error ?? 'invalid_request',
                $validation->message ?? 'The action arguments are invalid.',
            );
        }

        if ($context->isTest()) {
            return ToolResult::success([
                'ok' => true,
                'test_mode' => true,
                'action_proposed' => $toolName,
                'requires_confirmation' => false,
                'message' => 'Test mode simulated action proposal.',
            ]);
        }

        $safeArguments = $arguments;

        if ($preflightArguments !== null) {
            $safeArguments['__preflight'] = $preflightArguments;
        }

        $run = DB::transaction(function () use ($bot, $context, $runtimeOperation, $safeArguments, $toolName): ?ToolRun {
            $team = $bot->team()->lockForUpdate()->firstOrFail();

            try {
                $this->entitlements->ensureProductionLimit($team, PlanLimit::MonthlyActions);
            } catch (PlanLimitExceededException) {
                return null;
            }

            return ToolRun::query()->create([
                'team_id' => $bot->team_id,
                'bot_id' => $bot->id,
                'visitor_id' => $context->visitor?->id,
                'conversation_id' => $context->conversation?->id,
                'message_id' => $context->userMessage?->id,
                'api_operation_id' => $runtimeOperation->operation->id,
                'action_reference' => (string) Str::uuid(),
                'tool_name' => $toolName,
                'execution_mode' => ApiOperationMode::Write->value,
                'runtime_mode' => $context->mode->value,
                'status' => ToolRunStatus::PendingConfirmation->value,
                'idempotency_key' => (string) Str::uuid(),
                'safe_arguments' => $this->payloadSanitizer->sanitize($safeArguments),
            ]);
        });

        if (! $run instanceof ToolRun) {
            return ToolResult::failure(
                'action_unavailable',
                'This action is temporarily unavailable.',
            );
        }

        return ToolResult::requiresConfirmation(
            $run->action_reference,
            Str::limit(trim($summary), 500, ''),
        );
    }

    /**
     * @param  Closure(ToolRun): (?ToolResult)|null  $beforeWrite
     */
    public function confirm(
        Bot $bot,
        ToolExecutionContext $context,
        string $actionReference,
        ?Closure $beforeWrite = null,
    ): ToolResult {
        $run = $this->findScopedRun($bot, $context, $actionReference);

        if (! $run instanceof ToolRun) {
            return ToolResult::failure(
                'action_not_available',
                'This action is not available in the current conversation.',
            );
        }

        $status = $this->runStatus($run);

        if ($status === ToolRunStatus::Completed) {
            $this->projectCompletedRun($run);

            return $this->completedResult($run);
        }

        if ($status === ToolRunStatus::Cancelled) {
            return $this->failureForRun($run, 'action_cancelled', 'This action was cancelled.', ConfirmationBlockStatus::Cancelled);
        }

        if ($status === ToolRunStatus::Failed) {
            return $this->failureForRun($run, 'action_failed', 'This action failed and was not retried.', ConfirmationBlockStatus::Failed);
        }

        if ($status === ToolRunStatus::Executing) {
            return $this->failureForRun($run, 'action_in_progress', 'This action is already in progress.', ConfirmationBlockStatus::Confirmed);
        }

        DB::transaction(function () use ($run): void {
            $lockedRun = ToolRun::query()->lockForUpdate()->find($run->id);

            if ($lockedRun instanceof ToolRun && $this->runStatus($lockedRun) === ToolRunStatus::PendingConfirmation) {
                $lockedRun->update([
                    'status' => ToolRunStatus::Confirmed->value,
                    'confirmed_at' => now(),
                ]);
            }
        });

        /**
         * @var array{run: ToolRun|null, claimed: bool} $execution
         */
        $execution = DB::transaction(function () use ($run): array {
            $lockedRun = ToolRun::query()->lockForUpdate()->find($run->id);

            if (! $lockedRun instanceof ToolRun || $this->runStatus($lockedRun) !== ToolRunStatus::Confirmed) {
                return ['run' => $lockedRun, 'claimed' => false];
            }

            $lockedRun->update([
                'status' => ToolRunStatus::Executing->value,
                'started_at' => now(),
            ]);

            return ['run' => $lockedRun->fresh(), 'claimed' => true];
        });

        $executionRun = $execution['run'];

        if (! $executionRun instanceof ToolRun) {
            return ToolResult::failure('action_not_available', 'This action is not available.');
        }

        $executionStatus = $this->runStatus($executionRun);

        if ($executionStatus === ToolRunStatus::Completed) {
            return $this->completedResult($executionRun);
        }

        if (! $execution['claimed'] || $executionStatus !== ToolRunStatus::Executing) {
            return $this->failureForRun($executionRun, 'action_in_progress', 'This action is already being handled.', ConfirmationBlockStatus::Confirmed);
        }

        $runtimeOperation = $this->operationResolver->resolveWrite($bot, $executionRun->tool_name);

        if ($runtimeOperation === null || (int) $runtimeOperation->operation->id !== (int) $executionRun->api_operation_id) {
            return $this->failRun($executionRun, 'action_not_available');
        }

        $startedAt = hrtime(true);

        if ($beforeWrite !== null) {
            try {
                $preflightResult = $beforeWrite($executionRun);
            } catch (Throwable $exception) {
                logger()->warning('AI write action preflight failed.', [
                    'bot_id' => $bot->id,
                    'team_id' => $bot->team_id,
                    'tool' => $executionRun->tool_name,
                    'exception' => $exception::class,
                ]);

                $executionRun->update([
                    'status' => ToolRunStatus::Failed->value,
                    'error_code' => 'preflight_failed',
                    'duration_ms' => $this->durationMs($startedAt),
                    'failed_at' => now(),
                ]);
                $this->notifications->notifyActionFailed($executionRun->fresh() ?? $executionRun);

                return $this->failureForRun(
                    $executionRun,
                    'action_failed',
                    'The action could not be completed safely.',
                    ConfirmationBlockStatus::Failed,
                );
            }

            if ($preflightResult instanceof ToolResult) {
                $errorCode = $preflightResult->data['error'] ?? 'preflight_failed';

                $executionRun->update([
                    'status' => ToolRunStatus::Failed->value,
                    'error_code' => is_string($errorCode) ? $errorCode : 'preflight_failed',
                    'duration_ms' => $this->durationMs($startedAt),
                    'failed_at' => now(),
                ]);
                $this->notifications->notifyActionFailed($executionRun->fresh() ?? $executionRun);

                return $preflightResult->withBlocks([
                    $this->confirmationBlock($executionRun, ConfirmationBlockStatus::Failed),
                ]);
            }
        }

        $result = $this->operationExecutor->execute(
            $runtimeOperation,
            $this->runArguments($executionRun),
            $executionRun->idempotency_key,
        );
        $durationMs = $this->durationMs($startedAt);

        if (! $result->success) {
            $executionRun->update([
                'status' => ToolRunStatus::Failed->value,
                'error_code' => $result->error ?? 'integration_error',
                'duration_ms' => $durationMs,
                'failed_at' => now(),
            ]);
            $this->notifications->notifyActionFailed($executionRun->fresh() ?? $executionRun);

            return $this->failureForRun(
                $executionRun,
                'action_failed',
                'The action could not be completed safely.',
                ConfirmationBlockStatus::Failed,
            );
        }

        $safeResult = $this->payloadSanitizer->sanitize($result->data);
        $completedRun = DB::transaction(function () use ($executionRun, $safeResult, $durationMs): ToolRun {
            $executionRun->update([
                'status' => ToolRunStatus::Completed->value,
                'safe_result' => $safeResult,
                'duration_ms' => $durationMs,
                'completed_at' => now(),
            ]);

            $completedRun = $executionRun->fresh() ?? $executionRun;

            return $completedRun;
        });

        $this->projectCompletedRun($completedRun);

        return $this->completedResult($completedRun);
    }

    public function cancel(Bot $bot, ToolExecutionContext $context, string $actionReference): ToolResult
    {
        $run = $this->findScopedRun($bot, $context, $actionReference);

        if (! $run instanceof ToolRun) {
            return ToolResult::failure(
                'action_not_available',
                'This action is not available in the current conversation.',
            );
        }

        $status = $this->runStatus($run);

        if (in_array($status, [ToolRunStatus::PendingConfirmation, ToolRunStatus::Confirmed], true)) {
            $run->update([
                'status' => ToolRunStatus::Cancelled->value,
                'error_code' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            return ToolResult::success(
                [
                    'ok' => false,
                    'status' => ToolRunStatus::Cancelled->value,
                    'action_reference' => $run->action_reference,
                ],
                [],
                [$this->confirmationBlock($run, ConfirmationBlockStatus::Cancelled)],
            );
        }

        if ($status === ToolRunStatus::Completed) {
            return $this->completedResult($run);
        }

        if ($status === ToolRunStatus::Failed) {
            return $this->failureForRun($run, 'action_failed', 'This action failed and was not retried.', ConfirmationBlockStatus::Failed);
        }

        return $this->failureForRun($run, 'action_in_progress', 'This action is already in progress.', ConfirmationBlockStatus::Confirmed);
    }

    private function contextMatchesBot(Bot $bot, ToolExecutionContext $context): bool
    {
        if ((int) $context->bot->id !== (int) $bot->id
            || (int) $context->team->id !== (int) $bot->team_id) {
            return false;
        }

        if ($context->visitor && (int) $context->visitor->bot_id !== (int) $bot->id) {
            return false;
        }

        if ($context->conversation
            && ((int) $context->conversation->bot_id !== (int) $bot->id
                || (int) $context->conversation->visitor_id !== (int) ($context->visitor?->id))) {
            return false;
        }

        return ! $context->userMessage
            || ($context->userMessage->role === 'user'
                && (int) $context->userMessage->conversation_id === (int) ($context->conversation?->id));
    }

    private function findScopedRun(Bot $bot, ToolExecutionContext $context, string $actionReference): ?ToolRun
    {
        if (! $this->contextMatchesBot($bot, $context)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $actionReference) !== 1) {
            return null;
        }

        return ToolRun::query()
            ->where('team_id', $bot->team_id)
            ->where('bot_id', $bot->id)
            ->where('action_reference', $actionReference)
            ->where('conversation_id', $context->conversation?->id)
            ->where('visitor_id', $context->visitor?->id)
            ->first();
    }

    public function scopedRun(Bot $bot, ToolExecutionContext $context, string $actionReference): ?ToolRun
    {
        return $this->findScopedRun($bot, $context, $actionReference);
    }

    private function completedResult(ToolRun $run): ToolResult
    {
        $result = $this->safeRunResult($run);

        return ToolResult::success(
            [
                'ok' => true,
                'status' => ToolRunStatus::Completed->value,
                'action_reference' => $run->action_reference,
                'result' => $result,
            ],
            [],
            [$this->confirmationBlock($run, ConfirmationBlockStatus::Completed, $result)],
        );
    }

    private function failRun(ToolRun $run, string $errorCode): ToolResult
    {
        $run->update([
            'status' => ToolRunStatus::Failed->value,
            'error_code' => $errorCode,
            'failed_at' => now(),
        ]);
        $this->notifications->notifyActionFailed($run->fresh() ?? $run);

        return $this->failureForRun($run, 'action_not_available', 'This action is no longer available.', ConfirmationBlockStatus::Failed);
    }

    private function failureForRun(
        ToolRun $run,
        string $error,
        string $message,
        ConfirmationBlockStatus $status,
    ): ToolResult {
        return ToolResult::failure($error, $message)->withBlocks([
            $this->confirmationBlock($run, $status),
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function confirmationBlock(
        ToolRun $run,
        ConfirmationBlockStatus $status,
        array $result = [],
    ): array {
        $summary = [];

        $messages = $run->conversation?->messages()
            ->where('role', 'assistant')
            ->latest('id')
            ->get() ?? collect();

        foreach ($messages as $message) {
            $blocks = is_array($message->metadata) ? $message->metadata['blocks'] ?? [] : [];

            foreach (is_array($blocks) ? $blocks : [] as $block) {
                if (($block['type'] ?? null) !== 'confirmation'
                    || data_get($block, 'data.action_reference') !== $run->action_reference) {
                    continue;
                }

                $summary = data_get($block, 'data.summary');

                break 2;
            }

        }

        $summary = is_string($summary) && trim($summary) !== ''
            ? trim($summary)
            : 'Confirm this action.';

        return (new ConfirmationBlock(
            actionReference: $run->action_reference,
            summary: $summary,
            status: $status,
            result: $result,
        ))->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function safeRunResult(ToolRun $run): array
    {
        $value = $run->getAttribute('safe_result');

        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private function runStatus(ToolRun $run): ToolRunStatus
    {
        $status = $run->getAttribute('status');

        return $status instanceof ToolRunStatus
            ? $status
            : ToolRunStatus::from((string) $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function runArguments(ToolRun $run): array
    {
        $arguments = $run->getAttribute('safe_arguments');

        if (! is_array($arguments)) {
            return [];
        }

        $safeArguments = [];

        foreach ($arguments as $key => $value) {
            if (is_string($key) && $key !== '__preflight') {
                $safeArguments[$key] = $value;
            }
        }

        return $safeArguments;
    }

    private function durationMs(int|float $startedAt): int
    {
        return (int) min(
            PHP_INT_MAX,
            max(0, intdiv((int) (hrtime(true) - $startedAt), 1_000_000)),
        );
    }

    private function projectCompletedRun(ToolRun $run): void
    {
        try {
            $this->projections->project($run);
        } catch (Throwable $exception) {
            report($exception);
            logger()->warning('Completed action projection failed after provider success.', [
                'tool_run_id' => $run->id,
                'tool' => $run->tool_name,
                'team_id' => $run->team_id,
                'exception' => $exception::class,
            ]);
        }
    }
}
