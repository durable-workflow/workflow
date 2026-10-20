<?php

declare(strict_types=1);

namespace Workflow\V2\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\ExecutionTracing;
use Workflow\V2\Support\ServiceCallWorkflowDelivery;
use Workflow\V2\Support\SqliteTransaction;
use Workflow\V2\Support\StickyExecution;
use Workflow\V2\Support\TaskBackendCapabilities;
use Workflow\V2\Support\TaskCompatibility;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TaskOutcomeCarrier;
use Workflow\V2\Support\TaskTraceTimings;
use Workflow\V2\Support\TraceCarrier;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Support\WorkflowContinuationCache;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\Support\WorkflowTaskLease;

final class RunWorkflowTask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    // Mixed so malformed telemetry cannot prevent an older job from loading.
    public mixed $dispatchObservabilityContext = null;

    public function __construct(
        public readonly string $taskId,
    ) {
        $this->afterCommit();
    }

    public function handle(WorkflowExecutor $executor): void
    {
        WorkerCompatibilityFleet::heartbeat(
            is_string($this->connection ?? null) ? $this->connection : null,
            is_string($this->queue ?? null) ? $this->queue : null,
        );

        [$task, $deferredTask, $claimedAttempt] = $this->claimTask();

        if ($deferredTask instanceof WorkflowTask) {
            TaskDispatcher::dispatch($deferredTask);

            return;
        }

        if ($task === null) {
            return;
        }

        if (! ExecutionTracing::enabled()) {
            $this->executeClaim($executor, $claimedAttempt);

            return;
        }

        /** @var WorkflowRun $run */
        $run = $task->getRelation('run');
        $allowlist = ExecutionTracing::baggageAllowlist();
        $storedContext = TraceCarrier::fromRun($run, $diagnostic);
        // Activate and end the segment outside the execution transaction.
        ExecutionTracing::workflow(
            [
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id,
                'workflow_type' => $run->workflow_type,
                'namespace' => $run->namespace,
                'task_id' => $task->id,
                'durable.trace_context.disposition' => $diagnostic,
                ...TaskTraceTimings::forTask($task),
            ],
            $storedContext,
            fn () => $this->executeClaim($executor, $claimedAttempt),
            $allowlist,
            true,
            $this->dispatchObservabilityContext,
            static fn (): array => TaskOutcomeCarrier::forRun($run, $task),
        );
    }

    private function executeClaim(WorkflowExecutor $executor, int $claimedAttempt): void
    {
        $nextTask = null;
        $continuation = config('workflows.v2.live_execution.workflows', []) === []
            ? null : app(WorkflowContinuationCache::class)->session($this->job, $claimedAttempt);

        try {
            $nextTask = SqliteTransaction::forTask($this->taskId, function () use (
                $executor,
                $continuation
            ): ?WorkflowTask {
                /** @var WorkflowTask $task */
                $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                    ->lockForUpdate()
                    ->findOrFail($this->taskId);

                /** @var WorkflowRun $run */
                $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                    ->lockForUpdate()
                    ->findOrFail($task->workflow_run_id);

                if (in_array($run->status->value, ['completed', 'failed', 'cancelled', 'terminated'], true)) {
                    $task->forceFill([
                        'status' => $task->status === TaskStatus::Cancelled ? TaskStatus::Cancelled : (
                            $run->status->value === 'failed' ? TaskStatus::Failed : TaskStatus::Completed
                        ),
                        'lease_expires_at' => null,
                    ])->save();

                    $this->projectRun(
                        $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures'])
                    );

                    return null;
                }

                if ($continuation !== null && ! $continuation->hasAuthority($run, $task)) {
                    $continuation->disable('lost_task_authority');
                    return null;
                }

                $nextTask = $executor->run($run, $task, $continuation);
                $continuation?->offer($task);

                return $nextTask;
            });
        } catch (Throwable $throwable) {
            $continuation?->disable('transaction_failure');
            SqliteTransaction::forTask($this->taskId, function () use ($throwable): void {
                /** @var WorkflowTask|null $task */
                $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                    ->lockForUpdate()
                    ->find($this->taskId);

                if ($task === null) {
                    return;
                }

                $task->forceFill([
                    'status' => TaskStatus::Failed,
                    'last_error' => $throwable->getMessage(),
                    'lease_expires_at' => null,
                ])->save();

                /** @var WorkflowRun $run */
                $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->findOrFail($task->workflow_run_id);
                $this->projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));
            });

            throw $throwable;
        }

        $continuation?->committed();

        if ($nextTask instanceof WorkflowTask) {
            TaskDispatcher::dispatch($nextTask);
        }
    }

    /**
     * @return array{0: WorkflowTask|null, 1: WorkflowTask|null, 2: int}
     */
    private function claimTask(): array
    {
        return SqliteTransaction::forTask($this->taskId, function (): array {
            /** @var WorkflowTask|null $task */
            $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->lockForUpdate()
                ->find($this->taskId);

            if ($task === null || $task->task_type !== TaskType::Workflow || $task->status !== TaskStatus::Ready) {
                return [null, null, 0];
            }

            if ($task->available_at !== null && $task->available_at->isFuture()) {
                return [null, $task, 0];
            }

            /** @var WorkflowRun $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->findOrFail($task->workflow_run_id);

            TaskCompatibility::sync($task, $run);

            if (TaskBackendCapabilities::recordClaimFailureIfUnsupported($task) !== null) {
                $this->projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return [null, null, 0];
            }

            if (! TaskCompatibility::supported($task, $run)) {
                $this->projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return [null, null, 0];
            }

            // The logical task ID is reused as owner; the attempt fences this claim.
            $claimedAttempt = (int) $task->attempt_count + 1;
            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $this->taskId,
                'lease_expires_at' => WorkflowTaskLease::expiresAt(),
                'attempt_count' => $claimedAttempt,
                'sticky_replay_mode' => StickyExecution::claimReplayMode($task, $this->taskId),
                'sticky_claimed_at' => now(),
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ])->save();

            ServiceCallWorkflowDelivery::recordForClaim($task, $run);
            $this->projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return [$task->setRelation('run', $run), null, $claimedAttempt];
        });
    }

    private function projectRun(WorkflowRun $run): void
    {
        $this->historyProjectionRole()
            ->projectRun($run);
    }

    private function historyProjectionRole(): HistoryProjectionRole
    {
        /** @var HistoryProjectionRole $role */
        $role = app(HistoryProjectionRole::class);

        return $role;
    }
}
