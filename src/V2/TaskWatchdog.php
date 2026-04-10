<?php

declare(strict_types=1);

namespace Workflow\V2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TaskRepair;
use Workflow\V2\Support\TaskRepairCandidates;
use Workflow\V2\Support\TaskRepairPolicy;
use Workflow\V2\Support\TaskCompatibility;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class TaskWatchdog
{
    public const LOOP_THROTTLE_KEY = 'workflow:v2:task-watchdog:looping';

    public static function wake(?string $connection = null, ?string $queue = null): void
    {
        WorkerCompatibilityFleet::heartbeat($connection, $queue);

        if (! Cache::add(self::LOOP_THROTTLE_KEY, true, TaskRepairPolicy::loopThrottleSeconds())) {
            return;
        }

        foreach (TaskRepairCandidates::taskIds() as $candidateId) {
            self::recoverExistingTask($candidateId);
        }

        foreach (TaskRepairCandidates::runIds() as $runId) {
            self::recoverMissingTask($runId);
        }
    }

    private static function recoverExistingTask(string $candidateId): void
    {
        try {
            $task = DB::transaction(static function () use ($candidateId): ?WorkflowTask {
                /** @var WorkflowTask|null $task */
                $task = WorkflowTask::query()
                    ->lockForUpdate()
                    ->find($candidateId);

                if ($task === null) {
                    return null;
                }

                if (! TaskRepairPolicy::readyTaskNeedsRedispatch($task) && ! TaskRepairPolicy::leaseExpired(
                    $task
                )) {
                    return null;
                }

                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($task->workflow_run_id);

                TaskCompatibility::sync($task, $run);

                $task = TaskRepair::recoverExistingTask($task, $run);

                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                );

                return $task;
            });

            if ($task instanceof WorkflowTask) {
                TaskDispatcher::dispatch($task);
            }
        } catch (Throwable $throwable) {
            WorkflowTask::query()
                ->whereKey($candidateId)
                ->update([
                    'last_error' => $throwable->getMessage(),
                ]);
        }
    }

    private static function recoverMissingTask(string $runId): void
    {
        try {
            $task = DB::transaction(static function () use ($runId): ?WorkflowTask {
                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->with(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                    ->lockForUpdate()
                    ->findOrFail($runId);

                $summary = RunSummaryProjector::project($run);

                if ($summary->liveness_state !== 'repair_needed' || $summary->next_task_id !== null) {
                    return null;
                }

                $task = TaskRepair::repairRun($run, $summary);

                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                );

                return $task;
            });

            if ($task instanceof WorkflowTask) {
                TaskDispatcher::dispatch($task);
            }
        } catch (Throwable) {
            // A later worker loop will retry the same durable run summary candidate.
        }
    }

}
