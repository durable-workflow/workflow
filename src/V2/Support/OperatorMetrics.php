<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;
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
            'starts' => self::startMetrics($now),
            'history' => self::historyMetrics(),
            'projections' => self::projectionMetrics(),
            'workers' => self::workerMetrics(),
            'backend' => BackendCapabilities::snapshot($now),
            'repair_policy' => TaskRepairPolicy::snapshot(),
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
            'archived' => $summaryModel::query()->whereNotNull('archived_at')->count(),
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
     * @return array<string, int|string|null>
     */
    private static function startMetrics(CarbonInterface $now): array
    {
        $oldestPendingStartAt = self::oldestPendingStartAt();

        return [
            'pending_runs' => self::pendingStartRuns(),
            'pending_commands' => self::pendingStartCommands(),
            'ready_tasks' => self::readyPendingStartTasks($now),
            'oldest_pending_start_at' => $oldestPendingStartAt?->toJSON(),
            'max_pending_ms' => $oldestPendingStartAt === null
                ? 0
                : (int) $oldestPendingStartAt->diffInMilliseconds($now),
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

    /**
     * @return array<string, int>
     */
    private static function historyMetrics(): array
    {
        $summaryModel = self::summaryModel();

        return [
            'continue_as_new_recommended_runs' => $summaryModel::query()
                ->where('continue_as_new_recommended', true)
                ->count(),
            'max_event_count' => (int) $summaryModel::query()->max('history_event_count'),
            'max_size_bytes' => (int) $summaryModel::query()->max('history_size_bytes'),
            'event_threshold' => HistoryBudget::eventThreshold(),
            'size_bytes_threshold' => HistoryBudget::sizeBytesThreshold(),
        ];
    }

    /**
     * @return array<string, array<string, int|string|null>>
     */
    private static function projectionMetrics(): array
    {
        $runModel = self::runModel();
        $summaryModel = self::summaryModel();

        $missing = $runModel::query()
            ->whereNotIn('id', $summaryModel::query()->select('id'))
            ->count();
        $orphaned = $summaryModel::query()
            ->whereNotIn('id', $runModel::query()->select('id'))
            ->count();

        return [
            'run_summaries' => [
                'runs' => $runModel::query()->count(),
                'summaries' => $summaryModel::query()->count(),
                'missing' => $missing,
                'orphaned' => $orphaned,
                'needs_rebuild' => $missing + $orphaned,
                'oldest_updated_at' => self::jsonTimestamp($summaryModel::query()->min('updated_at')),
                'newest_updated_at' => self::jsonTimestamp($summaryModel::query()->max('updated_at')),
            ],
        ];
    }

    private static function compatibilityBlockedRuns(): int
    {
        return self::summaryModel()::query()
            ->where('liveness_state', 'like', '%_task_waiting_for_compatible_worker')
            ->count();
    }

    private static function pendingStartRuns(): int
    {
        return self::summaryModel()::query()
            ->where('status', RunStatus::Pending->value)
            ->count();
    }

    private static function pendingStartCommands(): int
    {
        return self::commandModel()::query()
            ->where('command_type', CommandType::Start->value)
            ->where('status', CommandStatus::Accepted->value)
            ->where('outcome', CommandOutcome::StartedNew->value)
            ->whereHas('run', static function ($query): void {
                $query->where('status', RunStatus::Pending->value);
            })
            ->count();
    }

    private static function readyPendingStartTasks(CarbonInterface $now): int
    {
        return self::taskModel()::query()
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now);
            })
            ->whereHas('run', static function ($query): void {
                $query->where('status', RunStatus::Pending->value);
            })
            ->count();
    }

    private static function oldestPendingStartAt(): ?CarbonInterface
    {
        /** @var WorkflowCommand|null $command */
        $command = self::commandModel()::query()
            ->where('command_type', CommandType::Start->value)
            ->where('status', CommandStatus::Accepted->value)
            ->where('outcome', CommandOutcome::StartedNew->value)
            ->whereNotNull('accepted_at')
            ->whereHas('run', static function ($query): void {
                $query->where('status', RunStatus::Pending->value);
            })
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->first();

        if ($command?->accepted_at instanceof CarbonInterface) {
            return $command->accepted_at;
        }

        /** @var WorkflowRunSummary|null $summary */
        $summary = self::summaryModel()::query()
            ->where('status', RunStatus::Pending->value)
            ->whereNotNull('started_at')
            ->orderBy('started_at')
            ->orderBy('id')
            ->first();

        return $summary?->started_at;
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
        $cutoff = $now->copy()->subSeconds(TaskRepairPolicy::redispatchAfterSeconds());

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
     * @return class-string<WorkflowRun>
     */
    private static function runModel(): string
    {
        /** @var class-string<WorkflowRun> $model */
        $model = config('workflows.v2.run_model', WorkflowRun::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowCommand>
     */
    private static function commandModel(): string
    {
        /** @var class-string<WorkflowCommand> $model */
        $model = config('workflows.v2.command_model', WorkflowCommand::class);

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

    private static function jsonTimestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        if (is_string($value) && $value !== '') {
            return \Illuminate\Support\Carbon::parse($value)->toJSON();
        }

        return null;
    }
}
