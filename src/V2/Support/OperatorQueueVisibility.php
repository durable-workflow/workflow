<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * Produces a per-namespace snapshot of queue depth and active leases for the
 * operator/fleet visibility APIs.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures on this class are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class OperatorQueueVisibility
{
    private const CURRENT_LEASE_LIMIT = 50;

    /**
     * @param array<string, iterable<array<string, mixed>>> $pollersByQueue
     */
    public static function forNamespace(
        string $namespace,
        array $pollersByQueue = [],
        ?CarbonInterface $now = null,
        ?int $staleAfterSeconds = null,
    ): QueueVisibilitySnapshot {
        $now ??= now();
        $queueNames = array_values(array_unique(array_merge(
            self::queueNames($namespace),
            array_keys($pollersByQueue),
        )));

        sort($queueNames);

        $details = array_map(
            static fn (string $taskQueue): QueueVisibilityDetail => self::forQueue(
                $namespace,
                $taskQueue,
                $pollersByQueue[$taskQueue] ?? [],
                $now,
                $staleAfterSeconds,
            ),
            $queueNames,
        );

        return new QueueVisibilitySnapshot($namespace, $details);
    }

    /**
     * @param iterable<array<string, mixed>> $pollers
     */
    public static function forQueue(
        string $namespace,
        string $taskQueue,
        iterable $pollers = [],
        ?CarbonInterface $now = null,
        ?int $staleAfterSeconds = null,
    ): QueueVisibilityDetail {
        $now ??= now();
        $pollers = self::pollers($pollers, $now, $staleAfterSeconds);

        return new QueueVisibilityDetail(
            $taskQueue,
            $pollers,
            self::stats($namespace, $taskQueue, $pollers, $now, $staleAfterSeconds),
            self::currentLeases($namespace, $taskQueue, $now),
            self::repairStats($namespace, $taskQueue, $now),
        );
    }

    /**
     * @return list<string>
     */
    public static function queueNames(string $namespace): array
    {
        $taskTable = self::taskTable();

        return self::namespaceTaskQuery($namespace)
            ->whereNotNull($taskTable . '.queue')
            ->where($taskTable . '.queue', '!=', '')
            ->selectRaw($taskTable . '.queue as queue')
            ->distinct()
            ->orderBy('queue')
            ->pluck('queue')
            ->filter(static fn ($queue): bool => is_string($queue) && $queue !== '')
            ->values()
            ->all();
    }

    /**
     * @param iterable<array<string, mixed>> $pollers
     * @return list<array<string, mixed>>
     */
    private static function pollers(iterable $pollers, CarbonInterface $now, ?int $staleAfterSeconds): array
    {
        $normalized = [];

        foreach ($pollers as $poller) {
            $normalized[] = self::poller($poller, $now, $staleAfterSeconds);
        }

        usort($normalized, static function (array $left, array $right): int {
            if (($left['is_stale'] ?? false) !== ($right['is_stale'] ?? false)) {
                return ($left['is_stale'] ?? false) <=> ($right['is_stale'] ?? false);
            }

            return strcmp((string) ($left['worker_id'] ?? ''), (string) ($right['worker_id'] ?? ''));
        });

        return $normalized;
    }

    /**
     * @param array<string, mixed> $poller
     * @return array<string, mixed>
     */
    private static function poller(array $poller, CarbonInterface $now, ?int $staleAfterSeconds): array
    {
        $lastHeartbeatAt = self::carbon($poller['last_heartbeat_at'] ?? null);
        $heartbeatDeadline = $lastHeartbeatAt !== null && $staleAfterSeconds !== null
            ? $lastHeartbeatAt->copy()
                ->addSeconds($staleAfterSeconds)
            : self::carbon($poller['heartbeat_expires_at'] ?? null);
        $isStale = $heartbeatDeadline !== null
            ? $heartbeatDeadline->lte($now)
            : (($poller['is_stale'] ?? false) === true);
        $status = $isStale
            ? 'stale'
            : (is_string($poller['status'] ?? null) && $poller['status'] !== '' ? $poller['status'] : 'active');

        return [
            'worker_id' => is_string($poller['worker_id'] ?? null) ? $poller['worker_id'] : null,
            'runtime' => is_string($poller['runtime'] ?? null) ? $poller['runtime'] : null,
            'sdk_version' => is_string($poller['sdk_version'] ?? null) ? $poller['sdk_version'] : null,
            'build_id' => is_string($poller['build_id'] ?? null) ? $poller['build_id'] : null,
            'last_heartbeat_at' => $lastHeartbeatAt?->toJSON(),
            'heartbeat_expires_at' => $heartbeatDeadline?->toJSON(),
            'status' => $status,
            'is_stale' => $isStale,
            'supported_workflow_types' => self::stringList($poller['supported_workflow_types'] ?? []),
            'supported_activity_types' => self::stringList($poller['supported_activity_types'] ?? []),
            'max_concurrent_workflow_tasks' => max(0, (int) ($poller['max_concurrent_workflow_tasks'] ?? 0)),
            'max_concurrent_activity_tasks' => max(0, (int) ($poller['max_concurrent_activity_tasks'] ?? 0)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $pollers
     * @return array<string, mixed>
     */
    private static function stats(
        string $namespace,
        string $taskQueue,
        array $pollers,
        CarbonInterface $now,
        ?int $staleAfterSeconds,
    ): array {
        $taskTable = self::taskTable();
        $runTable = self::runTable();
        $readyCounts = self::groupedTaskCounts(self::readyTaskQuery($namespace, $taskQueue, $now));
        $leasedCounts = self::groupedTaskCounts(
            self::baseTaskQuery($namespace, $taskQueue)
                ->where($taskTable . '.status', TaskStatus::Leased->value),
        );
        $expiredLeaseCounts = self::groupedTaskCounts(
            self::baseTaskQuery($namespace, $taskQueue)
                ->where($taskTable . '.status', TaskStatus::Leased->value)
                ->whereNotNull($taskTable . '.lease_expires_at')
                ->where($taskTable . '.lease_expires_at', '<=', $now),
        );

        /** @var WorkflowTask|null $oldestReadyTask */
        $oldestReadyTask = self::readyTaskQuery($namespace, $taskQueue, $now)
            ->join($runTable, $runTable . '.id', '=', $taskTable . '.workflow_run_id')
            ->select($taskTable . '.*', $runTable . '.workflow_instance_id')
            ->orderByRaw('case when ' . $taskTable . '.available_at is null then 1 else 0 end desc')
            ->orderBy($taskTable . '.available_at')
            ->orderBy($taskTable . '.created_at')
            ->orderBy($taskTable . '.id')
            ->first();

        $readySince = $oldestReadyTask?->available_at ?? $oldestReadyTask?->created_at;
        $backlogAgeSeconds = $readySince instanceof CarbonInterface
            ? max(0, (int) floor($readySince->diffInSeconds($now)))
            : null;
        $activePollers = count(array_filter(
            $pollers,
            static fn (array $poller): bool => ($poller['is_stale'] ?? false) !== true,
        ));
        $stalePollers = count($pollers) - $activePollers;

        return [
            'approximate_backlog_count' => $readyCounts[TaskType::Workflow->value] + $readyCounts[TaskType::Activity->value],
            'approximate_backlog_age' => self::ageLabel($backlogAgeSeconds),
            'approximate_backlog_age_seconds' => $backlogAgeSeconds,
            'oldest_ready_task' => $oldestReadyTask instanceof WorkflowTask
                ? [
                    'task_id' => $oldestReadyTask->id,
                    'task_type' => $oldestReadyTask->task_type?->value ?? $oldestReadyTask->task_type,
                    'workflow_id' => $oldestReadyTask->workflow_instance_id,
                    'run_id' => $oldestReadyTask->workflow_run_id,
                    'available_at' => ($oldestReadyTask->available_at ?? $oldestReadyTask->created_at)?->toJSON(),
                    'age_seconds' => $backlogAgeSeconds,
                ]
                : null,
            'workflow_tasks' => [
                'ready_count' => $readyCounts[TaskType::Workflow->value],
                'leased_count' => $leasedCounts[TaskType::Workflow->value],
                'expired_lease_count' => $expiredLeaseCounts[TaskType::Workflow->value],
            ],
            'activity_tasks' => [
                'ready_count' => $readyCounts[TaskType::Activity->value],
                'leased_count' => $leasedCounts[TaskType::Activity->value],
                'expired_lease_count' => $expiredLeaseCounts[TaskType::Activity->value],
            ],
            'pollers' => [
                'active_count' => $activePollers,
                'stale_count' => $stalePollers,
                'stale_after_seconds' => $staleAfterSeconds,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function currentLeases(string $namespace, string $taskQueue, CarbonInterface $now): array
    {
        $taskTable = self::taskTable();
        $runTable = self::runTable();
        $activityAttemptTable = self::activityAttemptTable();

        $workflowLeases = self::baseTaskQuery($namespace, $taskQueue)
            ->join($runTable, $runTable . '.id', '=', $taskTable . '.workflow_run_id')
            ->where($taskTable . '.task_type', TaskType::Workflow->value)
            ->where($taskTable . '.status', TaskStatus::Leased->value)
            ->select([
                $taskTable . '.id as task_id',
                $taskTable . '.workflow_run_id',
                $runTable . '.workflow_instance_id',
                $taskTable . '.lease_owner',
                $taskTable . '.lease_expires_at',
                $taskTable . '.attempt_count',
            ])
            ->orderBy($taskTable . '.lease_expires_at')
            ->orderBy($taskTable . '.id')
            ->limit(self::CURRENT_LEASE_LIMIT)
            ->get()
            ->map(static function ($task) use ($now): array {
                $leaseExpiresAt = self::carbon($task->lease_expires_at);

                return [
                    'task_id' => $task->task_id,
                    'task_type' => TaskType::Workflow->value,
                    'workflow_id' => $task->workflow_instance_id,
                    'run_id' => $task->workflow_run_id,
                    'lease_owner' => $task->lease_owner,
                    'lease_expires_at' => $leaseExpiresAt?->toJSON(),
                    'is_expired' => $leaseExpiresAt?->lte($now) ?? false,
                    'workflow_task_attempt' => is_int($task->attempt_count)
                        ? (int) $task->attempt_count
                        : null,
                ];
            });

        $activityLeases = self::baseTaskQuery($namespace, $taskQueue)
            ->join($runTable, $runTable . '.id', '=', $taskTable . '.workflow_run_id')
            ->leftJoin($activityAttemptTable, static function ($join) use ($activityAttemptTable, $taskTable): void {
                $join->on($activityAttemptTable . '.workflow_task_id', '=', $taskTable . '.id')
                    ->where($activityAttemptTable . '.status', '=', ActivityAttemptStatus::Running->value);
            })
            ->where($taskTable . '.task_type', TaskType::Activity->value)
            ->where($taskTable . '.status', TaskStatus::Leased->value)
            ->select([
                $taskTable . '.id as task_id',
                $taskTable . '.workflow_run_id',
                $runTable . '.workflow_instance_id',
                $taskTable . '.lease_owner',
                $taskTable . '.lease_expires_at',
                $activityAttemptTable . '.id as activity_attempt_id',
                $activityAttemptTable . '.attempt_number',
            ])
            ->orderBy($taskTable . '.lease_expires_at')
            ->orderBy($taskTable . '.id')
            ->limit(self::CURRENT_LEASE_LIMIT)
            ->get()
            ->map(static function ($task) use ($now): array {
                $leaseExpiresAt = self::carbon($task->lease_expires_at);

                return [
                    'task_id' => $task->task_id,
                    'task_type' => TaskType::Activity->value,
                    'workflow_id' => $task->workflow_instance_id,
                    'run_id' => $task->workflow_run_id,
                    'lease_owner' => $task->lease_owner,
                    'lease_expires_at' => $leaseExpiresAt?->toJSON(),
                    'is_expired' => $leaseExpiresAt?->lte($now) ?? false,
                    'activity_attempt_id' => $task->activity_attempt_id,
                    'attempt_number' => is_numeric($task->attempt_number) ? (int) $task->attempt_number : null,
                ];
            });

        $leases = $workflowLeases
            ->concat($activityLeases)
            ->all();

        usort($leases, static function (array $left, array $right): int {
            if (($left['is_expired'] ?? false) !== ($right['is_expired'] ?? false)) {
                return ($left['is_expired'] ?? false) === true ? -1 : 1;
            }

            return strcmp((string) ($left['task_id'] ?? ''), (string) ($right['task_id'] ?? ''));
        });

        return array_slice($leases, 0, self::CURRENT_LEASE_LIMIT);
    }

    /**
     * @return array<string, mixed>
     */
    private static function repairStats(string $namespace, string $taskQueue, CarbonInterface $now): array
    {
        $taskTable = self::taskTable();
        $redispatchCutoff = $now->copy()
            ->subSeconds(TaskRepairPolicy::redispatchAfterSeconds());

        $dispatchFailedCount = self::baseTaskQuery($namespace, $taskQueue)
            ->where($taskTable . '.status', TaskStatus::Ready->value)
            ->whereNotNull($taskTable . '.last_dispatch_attempt_at')
            ->whereNotNull($taskTable . '.last_dispatch_error')
            ->where($taskTable . '.last_dispatch_error', '!=', '')
            ->where(static function ($query) use ($taskTable): void {
                $query->whereNull($taskTable . '.last_dispatched_at')
                    ->orWhereColumn($taskTable . '.last_dispatch_attempt_at', '>', $taskTable . '.last_dispatched_at');
            })
            ->count();

        $expiredLeaseCount = self::baseTaskQuery($namespace, $taskQueue)
            ->where($taskTable . '.status', TaskStatus::Leased->value)
            ->whereNotNull($taskTable . '.lease_expires_at')
            ->where($taskTable . '.lease_expires_at', '<=', $now)
            ->count();

        $dispatchOverdueCount = self::baseTaskQuery($namespace, $taskQueue)
            ->where($taskTable . '.status', TaskStatus::Ready->value)
            ->where(static function ($query) use ($now, $taskTable): void {
                $query->whereNull($taskTable . '.available_at')
                    ->orWhere($taskTable . '.available_at', '<=', $now);
            })
            ->where(static function ($query) use ($redispatchCutoff, $taskTable): void {
                $query->where(static function ($dispatched) use ($redispatchCutoff, $taskTable): void {
                    $dispatched->whereNotNull($taskTable . '.last_dispatched_at')
                        ->where($taskTable . '.last_dispatched_at', '<=', $redispatchCutoff);
                })->orWhere(static function ($neverDispatched) use ($redispatchCutoff, $taskTable): void {
                    $neverDispatched->whereNull($taskTable . '.last_dispatched_at')
                        ->where($taskTable . '.created_at', '<=', $redispatchCutoff);
                });
            })
            ->where(static function ($query) use ($taskTable): void {
                $query->whereNull($taskTable . '.last_dispatch_error')
                    ->orWhere($taskTable . '.last_dispatch_error', '');
            })
            ->count();

        $totalCandidates = $dispatchFailedCount + $expiredLeaseCount + $dispatchOverdueCount;

        return [
            'candidates' => $totalCandidates,
            'dispatch_failed' => $dispatchFailedCount,
            'expired_leases' => $expiredLeaseCount,
            'dispatch_overdue' => $dispatchOverdueCount,
            'needs_attention' => $totalCandidates > 0,
            'policy' => [
                'redispatch_after_seconds' => TaskRepairPolicy::redispatchAfterSeconds(),
            ],
        ];
    }

    private static function namespaceTaskQuery(string $namespace): Builder
    {
        return self::taskQuery()
            ->where(self::taskTable() . '.namespace', $namespace);
    }

    private static function baseTaskQuery(string $namespace, string $taskQueue): Builder
    {
        $taskTable = self::taskTable();

        return self::namespaceTaskQuery($namespace)
            ->whereIn($taskTable . '.task_type', [TaskType::Workflow->value, TaskType::Activity->value])
            ->where($taskTable . '.queue', $taskQueue);
    }

    private static function readyTaskQuery(string $namespace, string $taskQueue, CarbonInterface $now): Builder
    {
        $taskTable = self::taskTable();
        $nowTimestamp = self::databaseTimestamp($now);

        return self::baseTaskQuery($namespace, $taskQueue)
            ->where($taskTable . '.status', TaskStatus::Ready->value)
            ->where(static function ($query) use ($nowTimestamp, $taskTable): void {
                $query->whereNull($taskTable . '.available_at')
                    ->orWhere($taskTable . '.available_at', '<=', $nowTimestamp);
            });
    }

    /**
     * @return array{workflow: int, activity: int}
     */
    private static function groupedTaskCounts(Builder $query): array
    {
        $taskTable = self::taskTable();
        $counts = [
            TaskType::Workflow->value => 0,
            TaskType::Activity->value => 0,
        ];

        foreach (
            $query->select($taskTable . '.task_type')
                ->selectRaw('COUNT(*) as aggregate')
                ->groupBy($taskTable . '.task_type')
                ->get() as $row
        ) {
            $taskType = $row->task_type instanceof TaskType
                ? $row->task_type->value
                : (is_string($row->task_type) ? $row->task_type : null);

            if ($taskType === null || ! array_key_exists($taskType, $counts)) {
                continue;
            }

            $counts[$taskType] = (int) $row->aggregate;
        }

        return $counts;
    }

    private static function taskQuery(): Builder
    {
        return ConfiguredV2Models::query('task_model', WorkflowTask::class);
    }

    private static function taskTable(): string
    {
        return self::tableFor('task_model', WorkflowTask::class);
    }

    private static function runTable(): string
    {
        return self::tableFor('run_model', WorkflowRun::class);
    }

    private static function activityAttemptTable(): string
    {
        return self::tableFor('activity_attempt_model', ActivityAttempt::class);
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $default
     */
    private static function tableFor(string $configKey, string $default): string
    {
        $model = ConfiguredV2Models::resolve($configKey, $default);

        return (new $model())->getTable();
    }

    private static function ageLabel(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return sprintf('%ds', $seconds);
        }

        if ($seconds < 3600) {
            return sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf('%dh%02dm%02ds', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)));
    }

    private static function carbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private static function databaseTimestamp(CarbonInterface $value): string
    {
        return $value->copy()
            ->utc()
            ->format('Y-m-d H:i:s.u');
    }
}
