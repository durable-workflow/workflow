<?php

declare(strict_types=1);

namespace Workflow\V2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkerCompatibilityHeartbeat;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\Support\TaskCompatibility;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TaskRepair;
use Workflow\V2\Support\TaskRepairCandidates;
use Workflow\V2\Support\TaskRepairPolicy;
use Workflow\V2\Support\WorkerCompatibilityFleet;

/**
 * Periodic repair loop that scans for stuck workflow tasks, expired activity
 * leases, and missing projections. Invoked by the embedded queue runner and
 * by the standalone server's background sweep process.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures on this class are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class TaskWatchdog
{
    public const LOOP_THROTTLE_KEY = 'workflow:v2:task-watchdog:looping';

    public static function wake(?string $connection = null, ?string $queue = null): void
    {
        try {
            self::runPass($connection, $queue, respectThrottle: true);
        } catch (Throwable $throwable) {
            // Queue workers call wake() on every poll; a transient table-not-found
            // error during test migrate:fresh windows or a deadlock under heavy
            // contention must not kill the worker. The next poll re-enters the
            // pass once the schema/locks settle.
            report($throwable);
        }
    }

    /**
     * @return array{
     *     connection: string|null,
     *     queue: string|null,
     *     run_ids: list<string>,
     *     instance_id: string|null,
     *     respect_throttle: bool,
     *     throttled: bool,
     *     selected_existing_task_candidates: int,
     *     selected_missing_task_candidates: int,
     *     selected_total_candidates: int,
     *     repaired_existing_tasks: int,
     *     repaired_missing_tasks: int,
     *     dispatched_tasks: int,
     *     existing_task_failures: list<array{candidate_id: string, message: string}>,
     *     missing_run_failures: list<array{run_id: string, message: string}>,
     *     deadline_expired_candidates: int,
     *     deadline_expired_tasks_created: int,
     *     deadline_expired_failures: list<array{run_id: string, message: string}>,
     *     activity_timeout_candidates: int,
     *     activity_timeouts_enforced: int,
     *     activity_timeout_failures: list<array{execution_id: string, message: string}>
     * }
     */
    public static function runPass(
        ?string $connection = null,
        ?string $queue = null,
        bool $respectThrottle = false,
        array $runIds = [],
        ?string $instanceId = null,
    ): array {
        if (! self::tablesReady()) {
            return self::emptyReport($connection, $queue, $respectThrottle, $runIds, $instanceId);
        }

        if ($respectThrottle) {
            if (! Cache::add(self::LOOP_THROTTLE_KEY, true, TaskRepairPolicy::loopThrottleSeconds())) {
                return self::emptyReport(
                    $connection,
                    $queue,
                    $respectThrottle,
                    $runIds,
                    $instanceId,
                    throttled: true,
                );
            }
        } else {
            Cache::put(self::LOOP_THROTTLE_KEY, true, TaskRepairPolicy::loopThrottleSeconds());
        }

        WorkerCompatibilityFleet::heartbeat($connection, $queue);

        $existingTaskCandidateIds = TaskRepairCandidates::taskIds(runIds: $runIds, instanceId: $instanceId);
        $missingRunIds = TaskRepairCandidates::runIds(runIds: $runIds, instanceId: $instanceId);
        $report = self::emptyReport($connection, $queue, $respectThrottle, $runIds, $instanceId);
        $report['selected_existing_task_candidates'] = count($existingTaskCandidateIds);
        $report['selected_missing_task_candidates'] = count($missingRunIds);
        $report['selected_total_candidates'] = count($existingTaskCandidateIds) + count($missingRunIds);

        foreach ($existingTaskCandidateIds as $candidateId) {
            $result = self::recoverExistingTask($candidateId);

            if ($result['task'] instanceof WorkflowTask) {
                $report['repaired_existing_tasks']++;
                $report['dispatched_tasks']++;
            }

            if ($result['error'] !== null) {
                $report['existing_task_failures'][] = [
                    'candidate_id' => $candidateId,
                    'message' => $result['error'],
                ];
            }
        }

        foreach ($missingRunIds as $runId) {
            $result = self::recoverMissingTask($runId);

            if ($result['task'] instanceof WorkflowTask) {
                $report['repaired_missing_tasks']++;
                $report['dispatched_tasks']++;
            }

            if ($result['error'] !== null) {
                $report['missing_run_failures'][] = [
                    'run_id' => $runId,
                    'message' => $result['error'],
                ];
            }
        }

        $deadlineExpiredRunIds = self::deadlineExpiredRunIds($runIds, $instanceId);
        $report['deadline_expired_candidates'] = count($deadlineExpiredRunIds);

        foreach ($deadlineExpiredRunIds as $deadlineRunId) {
            $result = self::createDeadlineExpiredTask($deadlineRunId);

            if ($result['task'] instanceof WorkflowTask) {
                $report['deadline_expired_tasks_created']++;
                $report['dispatched_tasks']++;
            }

            if ($result['error'] !== null) {
                $report['deadline_expired_failures'][] = [
                    'run_id' => $deadlineRunId,
                    'message' => $result['error'],
                ];
            }
        }

        $activityTimeoutIds = ActivityTimeoutEnforcer::expiredExecutionIds(TaskRepairPolicy::scanLimit());
        $report['activity_timeout_candidates'] = count($activityTimeoutIds);

        foreach ($activityTimeoutIds as $activityExecutionId) {
            try {
                $result = ActivityTimeoutEnforcer::enforce($activityExecutionId);

                if ($result['enforced']) {
                    $report['activity_timeouts_enforced']++;

                    if ($result['next_task'] instanceof WorkflowTask) {
                        $report['dispatched_tasks']++;
                        TaskDispatcher::dispatch($result['next_task']);
                    }
                }

                if ($result['reason'] !== null) {
                    $report['activity_timeout_failures'][] = [
                        'execution_id' => $activityExecutionId,
                        'message' => $result['reason'],
                    ];
                }
            } catch (Throwable $throwable) {
                report($throwable);

                $report['activity_timeout_failures'][] = [
                    'execution_id' => $activityExecutionId,
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        return $report;
    }

    /**
     * @return array{task: WorkflowTask|null, error: string|null}
     */
    private static function recoverExistingTask(string $candidateId): array
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

                return TaskRepair::recoverExistingTask($task, $run);
            });

            if ($task instanceof WorkflowTask) {
                TaskDispatcher::dispatch($task);
            }
        } catch (Throwable $throwable) {
            report($throwable);

            WorkflowTask::query()
                ->whereKey($candidateId)
                ->update([
                    'last_error' => $throwable->getMessage(),
                ]);

            return [
                'task' => null,
                'error' => $throwable->getMessage(),
            ];
        }

        return [
            'task' => $task,
            'error' => null,
        ];
    }

    /**
     * @return array{task: WorkflowTask|null, error: string|null}
     */
    private static function recoverMissingTask(string $runId): array
    {
        try {
            $task = DB::transaction(static function () use ($runId): ?WorkflowTask {
                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->with(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                    ->lockForUpdate()
                    ->findOrFail($runId);

                $summary = self::historyProjectionRole()->projectRun($run);

                if ($summary->liveness_state !== 'repair_needed' || $summary->next_task_id !== null) {
                    return null;
                }

                $task = TaskRepair::repairRun($run, $summary);

                self::historyProjectionRole()->projectRun(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                );

                return $task;
            });

            if ($task instanceof WorkflowTask) {
                TaskDispatcher::dispatch($task);
            }
        } catch (Throwable $throwable) {
            report($throwable);

            return [
                'task' => null,
                'error' => $throwable->getMessage(),
            ];
        }

        return [
            'task' => $task,
            'error' => null,
        ];
    }

    /**
     * @return array{
     *     connection: string|null,
     *     queue: string|null,
     *     run_ids: list<string>,
     *     instance_id: string|null,
     *     respect_throttle: bool,
     *     throttled: bool,
     *     selected_existing_task_candidates: int,
     *     selected_missing_task_candidates: int,
     *     selected_total_candidates: int,
     *     repaired_existing_tasks: int,
     *     repaired_missing_tasks: int,
     *     dispatched_tasks: int,
     *     existing_task_failures: list<array{candidate_id: string, message: string}>,
     *     missing_run_failures: list<array{run_id: string, message: string}>,
     *     deadline_expired_candidates: int,
     *     deadline_expired_tasks_created: int,
     *     deadline_expired_failures: list<array{run_id: string, message: string}>,
     *     activity_timeout_candidates: int,
     *     activity_timeouts_enforced: int,
     *     activity_timeout_failures: list<array{execution_id: string, message: string}>
     * }
     */
    private static function emptyReport(
        ?string $connection,
        ?string $queue,
        bool $respectThrottle,
        array $runIds,
        ?string $instanceId,
        bool $throttled = false,
    ): array {
        return [
            'connection' => $connection,
            'queue' => $queue,
            'run_ids' => array_values($runIds),
            'instance_id' => $instanceId,
            'respect_throttle' => $respectThrottle,
            'throttled' => $throttled,
            'selected_existing_task_candidates' => 0,
            'selected_missing_task_candidates' => 0,
            'selected_total_candidates' => 0,
            'repaired_existing_tasks' => 0,
            'repaired_missing_tasks' => 0,
            'dispatched_tasks' => 0,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'deadline_expired_candidates' => 0,
            'deadline_expired_tasks_created' => 0,
            'deadline_expired_failures' => [],
            'activity_timeout_candidates' => 0,
            'activity_timeouts_enforced' => 0,
            'activity_timeout_failures' => [],
        ];
    }

    /**
     * Find non-terminal runs with expired execution or run deadlines
     * that have no open workflow task to detect the timeout.
     *
     * @return list<string>
     */
    private static function deadlineExpiredRunIds(array $runIds = [], ?string $instanceId = null): array
    {
        $now = now();

        $query = WorkflowRun::query()
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value, RunStatus::Waiting->value])
            ->where(static function ($deadline) use ($now): void {
                $deadline->where(static function ($execution) use ($now): void {
                    $execution->whereNotNull('execution_deadline_at')
                        ->where('execution_deadline_at', '<=', $now);
                })->orWhere(static function ($run) use ($now): void {
                    $run->whereNotNull('run_deadline_at')
                        ->where('run_deadline_at', '<=', $now);
                });
            })
            ->whereDoesntHave('tasks', static function ($task): void {
                $task->where('task_type', TaskType::Workflow->value)
                    ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value]);
            });

        if ($runIds !== []) {
            $query->whereKey($runIds);
        }

        if ($instanceId !== null) {
            $query->where('workflow_instance_id', $instanceId);
        }

        return $query->limit(TaskRepairPolicy::scanLimit())->pluck('id')->all();
    }

    private static function tablesReady(): bool
    {
        try {
            foreach ([
                new WorkerCompatibilityHeartbeat(),
                new WorkflowTask(),
                new WorkflowRun(),
                new WorkflowRunSummary(),
                new ActivityExecution(),
            ] as $model) {
                if (! Schema::hasTable($model->getTable())) {
                    return false;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @return array{task: WorkflowTask|null, error: string|null}
     */
    private static function createDeadlineExpiredTask(string $runId): array
    {
        try {
            $task = DB::transaction(static function () use ($runId): ?WorkflowTask {
                /** @var WorkflowRun|null $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->find($runId);

                if ($run === null || $run->status->isTerminal()) {
                    return null;
                }

                $now = now();
                $deadlineExpired = ($run->execution_deadline_at !== null && $now->gte($run->execution_deadline_at))
                    || ($run->run_deadline_at !== null && $now->gte($run->run_deadline_at));

                if (! $deadlineExpired) {
                    return null;
                }

                $existingWorkflowTask = WorkflowTask::query()
                    ->where('workflow_run_id', $run->id)
                    ->where('task_type', TaskType::Workflow->value)
                    ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
                    ->first();

                if ($existingWorkflowTask !== null) {
                    return null;
                }

                /** @var WorkflowTask $task */
                $task = WorkflowTask::query()->create([
                    'workflow_run_id' => $run->id,
                    'namespace' => $run->namespace,
                    'task_type' => TaskType::Workflow->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => $now,
                    'payload' => [
                        'reason' => 'deadline_expired',
                    ],
                    'connection' => $run->connection,
                    'queue' => $run->queue,
                    'compatibility' => $run->compatibility,
                    'repair_count' => 1,
                ]);

                self::historyProjectionRole()->projectRun(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                );

                return $task;
            });

            if ($task instanceof WorkflowTask) {
                TaskDispatcher::dispatch($task);
            }
        } catch (Throwable $throwable) {
            report($throwable);

            return [
                'task' => null,
                'error' => $throwable->getMessage(),
            ];
        }

        return [
            'task' => $task,
            'error' => null,
        ];
    }

    private static function historyProjectionRole(): HistoryProjectionRole
    {
        /** @var HistoryProjectionRole $role */
        $role = app(HistoryProjectionRole::class);

        return $role;
    }
}
