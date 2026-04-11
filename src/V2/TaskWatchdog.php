<?php

declare(strict_types=1);

namespace Workflow\V2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\CommandContractBackfillSweep;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\TaskCompatibility;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TaskRepair;
use Workflow\V2\Support\TaskRepairCandidates;
use Workflow\V2\Support\TaskRepairPolicy;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class TaskWatchdog
{
    public const LOOP_THROTTLE_KEY = 'workflow:v2:task-watchdog:looping';

    public static function wake(?string $connection = null, ?string $queue = null): void
    {
        self::runPass($connection, $queue, respectThrottle: true);
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
     *     selected_command_contract_candidates: int,
     *     backfilled_command_contracts: int,
     *     command_contract_backfill_unavailable: int,
     *     existing_task_failures: list<array{candidate_id: string, message: string}>,
     *     missing_run_failures: list<array{run_id: string, message: string}>,
     *     command_contract_failures: list<array{run_id: string, message: string}>
     * }
     */
    public static function runPass(
        ?string $connection = null,
        ?string $queue = null,
        bool $respectThrottle = false,
        array $runIds = [],
        ?string $instanceId = null,
    ): array {
        WorkerCompatibilityFleet::heartbeat($connection, $queue);

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

        $commandContractReport = CommandContractBackfillSweep::run(
            $runIds,
            $instanceId,
            TaskRepairPolicy::scanLimit(),
        );
        $report['selected_command_contract_candidates'] = $commandContractReport['selected_candidates'];
        $report['backfilled_command_contracts'] = $commandContractReport['backfilled'];
        $report['command_contract_backfill_unavailable'] = $commandContractReport['unavailable'];
        $report['command_contract_failures'] = $commandContractReport['failures'];

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

            return [
                'task' => null,
                'error' => null,
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
     *     selected_command_contract_candidates: int,
     *     backfilled_command_contracts: int,
     *     command_contract_backfill_unavailable: int,
     *     existing_task_failures: list<array{candidate_id: string, message: string}>,
     *     missing_run_failures: list<array{run_id: string, message: string}>,
     *     command_contract_failures: list<array{run_id: string, message: string}>
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
            'selected_command_contract_candidates' => 0,
            'backfilled_command_contracts' => 0,
            'command_contract_backfill_unavailable' => 0,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'command_contract_failures' => [],
        ];
    }
}
