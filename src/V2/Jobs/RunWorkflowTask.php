<?php

declare(strict_types=1);

namespace Workflow\V2\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\TaskBackendCapabilities;
use Workflow\V2\Support\TaskCompatibility;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Support\WorkflowExecutor;

final class RunWorkflowTask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

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

        if (! $this->claimTask()) {
            return;
        }

        $nextTask = null;

        try {
            $nextTask = DB::transaction(function () use ($executor): ?WorkflowTask {
                /** @var WorkflowTask $task */
                $task = WorkflowTask::query()
                    ->lockForUpdate()
                    ->findOrFail($this->taskId);

                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($task->workflow_run_id);

                if (in_array($run->status->value, ['completed', 'failed', 'cancelled', 'terminated'], true)) {
                    $task->forceFill([
                        'status' => $task->status === TaskStatus::Cancelled ? TaskStatus::Cancelled : (
                            $run->status->value === 'failed' ? TaskStatus::Failed : TaskStatus::Completed
                        ),
                        'lease_expires_at' => null,
                    ])->save();

                    RunSummaryProjector::project(
                        $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures'])
                    );

                    return null;
                }

                return $executor->run($run, $task);
            });
        } catch (Throwable $throwable) {
            DB::transaction(function () use ($throwable): void {
                /** @var WorkflowTask|null $task */
                $task = WorkflowTask::query()
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
                $run = WorkflowRun::query()->findOrFail($task->workflow_run_id);
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));
            });

            throw $throwable;
        }

        if ($nextTask instanceof WorkflowTask) {
            TaskDispatcher::dispatch($nextTask);
        }
    }

    private function claimTask(): bool
    {
        return DB::transaction(function (): bool {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->find($this->taskId);

            if ($task === null || $task->task_type !== TaskType::Workflow || $task->status !== TaskStatus::Ready) {
                return false;
            }

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($task->workflow_run_id);

            TaskCompatibility::sync($task, $run);

            if (TaskBackendCapabilities::recordClaimFailureIfUnsupported($task) !== null) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return false;
            }

            if (! TaskCompatibility::supported($task, $run)) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return false;
            }

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $this->taskId,
                'lease_expires_at' => now()
                    ->addMinutes(5),
                'attempt_count' => $task->attempt_count + 1,
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ])->save();

            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return true;
        });
    }
}
