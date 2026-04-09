<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

final class OperatorMetrics
{
    /**
     * @return array<string, mixed>
     */
    public static function snapshot(?CarbonInterface $now = null): array
    {
        $now ??= now();

        return [
            'generated_at' => $now->toJSON(),
            'runs' => self::runMetrics(),
            'tasks' => self::taskMetrics($now),
            'backlog' => self::backlogMetrics($now),
            'workers' => self::workerMetrics(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function runMetrics(): array
    {
        $summaryModel = self::summaryModel();

        return [
            'total' => $summaryModel::query()->count(),
            'current' => $summaryModel::query()->where('is_current_run', true)->count(),
            'running' => $summaryModel::query()->where('status_bucket', 'running')->count(),
            'completed' => $summaryModel::query()->where('status', RunStatus::Completed->value)->count(),
            'failed' => $summaryModel::query()->where('status', RunStatus::Failed->value)->count(),
            'cancelled' => $summaryModel::query()->where('status', RunStatus::Cancelled->value)->count(),
            'terminated' => $summaryModel::query()->where('status', RunStatus::Terminated->value)->count(),
            'repair_needed' => $summaryModel::query()->where('liveness_state', 'repair_needed')->count(),
            'compatibility_blocked' => self::compatibilityBlockedRuns(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function taskMetrics(CarbonInterface $now): array
    {
        return [
            'open' => self::openTasks(),
            'ready' => self::readyTasks(),
            'ready_due' => self::readyDueTasks($now),
            'delayed' => self::delayedTasks($now),
            'leased' => self::leasedTasks(),
            'dispatch_failed' => self::dispatchFailedTasks(),
            'dispatch_overdue' => self::dispatchOverdueTasks($now),
            'lease_expired' => self::leaseExpiredTasks($now),
            'unhealthy' => self::dispatchFailedTasks()
                + self::dispatchOverdueTasks($now)
                + self::leaseExpiredTasks($now),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function backlogMetrics(CarbonInterface $now): array
    {
        return [
            'runnable_tasks' => self::readyDueTasks($now),
            'delayed_tasks' => self::delayedTasks($now),
            'leased_tasks' => self::leasedTasks(),
            'unhealthy_tasks' => self::dispatchFailedTasks()
                + self::dispatchOverdueTasks($now)
                + self::leaseExpiredTasks($now),
            'repair_needed_runs' => self::summaryModel()::query()
                ->where('liveness_state', 'repair_needed')
                ->count(),
            'compatibility_blocked_runs' => self::compatibilityBlockedRuns(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function workerMetrics(): array
    {
        $required = WorkerCompatibility::current();
        $snapshots = WorkerCompatibilityFleet::details($required);
        $workerIds = [];
        $supportingWorkerIds = [];

        foreach ($snapshots as $snapshot) {
            $workerId = is_string($snapshot['worker_id'] ?? null)
                ? $snapshot['worker_id']
                : null;

            if ($workerId === null) {
                continue;
            }

            $workerIds[$workerId] = true;

            if (($snapshot['supports_required'] ?? false) === true) {
                $supportingWorkerIds[$workerId] = true;
            }
        }

        return [
            'compatibility_namespace' => WorkerCompatibilityFleet::scopeNamespace(),
            'required_compatibility' => $required,
            'active_workers' => count($workerIds),
            'active_worker_scopes' => count($snapshots),
            'active_workers_supporting_required' => count($supportingWorkerIds),
        ];
    }

    private static function compatibilityBlockedRuns(): int
    {
        return self::summaryModel()::query()
            ->where('liveness_state', 'like', '%_task_waiting_for_compatible_worker')
            ->count();
    }

    private static function openTasks(): int
    {
        return self::taskModel()::query()
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count();
    }

    private static function readyTasks(): int
    {
        return self::taskModel()::query()
            ->where('status', TaskStatus::Ready->value)
            ->count();
    }

    private static function readyDueTasks(CarbonInterface $now): int
    {
        return self::taskModel()::query()
            ->where('status', TaskStatus::Ready->value)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now);
            })
            ->count();
    }

    private static function delayedTasks(CarbonInterface $now): int
    {
        return self::taskModel()::query()
            ->where('status', TaskStatus::Ready->value)
            ->where('available_at', '>', $now)
            ->count();
    }

    private static function leasedTasks(): int
    {
        return self::taskModel()::query()
            ->where('status', TaskStatus::Leased->value)
            ->count();
    }

    private static function leaseExpiredTasks(CarbonInterface $now): int
    {
        return self::taskModel()::query()
            ->where('status', TaskStatus::Leased->value)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', $now)
            ->count();
    }

    private static function dispatchFailedTasks(): int
    {
        return self::dispatchFailedQuery()->count();
    }

    private static function dispatchOverdueTasks(CarbonInterface $now): int
    {
        $cutoff = $now->copy()->subSeconds(TaskRepairPolicy::REDISPATCH_AFTER_SECONDS);

        return self::taskModel()::query()
            ->where('status', TaskStatus::Ready->value)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now);
            })
            ->where(static function ($query): void {
                self::applyDispatchHealthy($query);
            })
            ->where(static function ($query) use ($cutoff): void {
                $query->where(static function ($dispatched) use ($cutoff): void {
                    $dispatched->whereNotNull('last_dispatched_at')
                        ->where('last_dispatched_at', '<=', $cutoff);
                })->orWhere(static function ($neverDispatched) use ($cutoff): void {
                    $neverDispatched->whereNull('last_dispatched_at')
                        ->where('created_at', '<=', $cutoff);
                });
            })
            ->count();
    }

    private static function dispatchFailedQuery()
    {
        $query = self::taskModel()::query();

        self::applyDispatchFailed($query);

        return $query;
    }

    private static function applyDispatchFailed($query): void
    {
        $query
            ->where('status', TaskStatus::Ready->value)
            ->whereNotNull('last_dispatch_attempt_at')
            ->whereNotNull('last_dispatch_error')
            ->where('last_dispatch_error', '!=', '')
            ->where(static function ($dispatch): void {
                $dispatch->whereNull('last_dispatched_at')
                    ->orWhereColumn('last_dispatch_attempt_at', '>', 'last_dispatched_at');
            });
    }

    private static function applyDispatchHealthy($query): void
    {
        $query
            ->whereNull('last_dispatch_attempt_at')
            ->orWhereNull('last_dispatch_error')
            ->orWhere('last_dispatch_error', '')
            ->orWhere(static function ($successfulDispatch): void {
                $successfulDispatch->whereNotNull('last_dispatched_at')
                    ->whereColumn('last_dispatch_attempt_at', '<=', 'last_dispatched_at');
            });
    }

    /**
     * @return class-string<WorkflowRunSummary>
     */
    private static function summaryModel(): string
    {
        /** @var class-string<WorkflowRunSummary> $model */
        $model = config('workflows.v2.run_summary_model', WorkflowRunSummary::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowTask>
     */
    private static function taskModel(): string
    {
        /** @var class-string<WorkflowTask> $model */
        $model = config('workflows.v2.task_model', WorkflowTask::class);

        return $model;
    }
}
