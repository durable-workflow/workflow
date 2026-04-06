<?php

declare(strict_types=1);

namespace Workflow\V2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TaskRepair;
use Workflow\V2\Support\TaskRepairPolicy;
use Workflow\V2\Support\WorkerCompatibility;

final class TaskWatchdog
{
    public const LOOP_THROTTLE_KEY = 'workflow:v2:task-watchdog:looping';

    public static function wake(): void
    {
        if (! Cache::add(self::LOOP_THROTTLE_KEY, true, TaskRepairPolicy::LOOP_THROTTLE_SECONDS)) {
            return;
        }

        foreach (self::candidateTaskIds() as $candidateId) {
            self::recoverExistingTask($candidateId);
        }

        foreach (self::repairCandidateRunIds() as $runId) {
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

                if (! WorkerCompatibility::supports($task->compatibility)) {
                    return null;
                }

                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($task->workflow_run_id);

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

                if (! WorkerCompatibility::supports($run->compatibility)) {
                    return null;
                }

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

    /**
     * @return list<string>
     */
    private static function repairCandidateRunIds(): array
    {
        /** @var list<string> $ids */
        $ids = WorkflowRunSummary::query()
            ->where('liveness_state', 'repair_needed')
            ->whereNull('next_task_id')
            ->whereIn('status', ['pending', 'running', 'waiting'])
            ->orderBy('wait_started_at')
            ->orderBy('started_at')
            ->orderBy('id')
            ->limit(TaskRepairPolicy::SCAN_LIMIT)
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function candidateTaskIds(): array
    {
        $now = now();
        $staleDispatchCutoff = $now->copy()
            ->subSeconds(TaskRepairPolicy::REDISPATCH_AFTER_SECONDS);

        /** @var list<string> $ids */
        $ids = WorkflowTask::query()
            ->where(static function ($query) use ($now, $staleDispatchCutoff): void {
                $query->where(static function ($ready) use ($now, $staleDispatchCutoff): void {
                    $ready->where('status', TaskStatus::Ready->value)
                        ->where(static function ($available) use ($now): void {
                            $available->whereNull('available_at')
                                ->orWhere('available_at', '<=', $now);
                        })
                        ->where(static function ($dispatch) use ($staleDispatchCutoff): void {
                            $dispatch->where('last_dispatched_at', '<=', $staleDispatchCutoff)
                                ->orWhere(static function ($neverDispatched) use ($staleDispatchCutoff): void {
                                    $neverDispatched->whereNull('last_dispatched_at')
                                        ->where('created_at', '<=', $staleDispatchCutoff);
                                });
                        });
                })->orWhere(static function ($leased) use ($now): void {
                    $leased->where('status', TaskStatus::Leased->value)
                        ->whereNotNull('lease_expires_at')
                        ->where('lease_expires_at', '<=', $now);
                });
            })
            ->orderBy('available_at')
            ->orderBy('created_at')
            ->limit(TaskRepairPolicy::SCAN_LIMIT)
            ->pluck('id')
            ->all();

        return $ids;
    }
}
