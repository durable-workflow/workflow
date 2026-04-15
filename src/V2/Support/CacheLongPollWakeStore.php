<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;
use Workflow\V2\Contracts\LongPollWakeStore;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * Cache-backed long-poll wake signal store.
 *
 * This implementation uses Laravel's cache system to coordinate wake signals
 * across server nodes. The cache backend must be shared (Redis, database cache,
 * Memcached) for multi-node deployments.
 *
 * Signal TTL defaults to 60 seconds and can be configured via constructor.
 */
class CacheLongPollWakeStore implements LongPollWakeStore
{
    private const CACHE_PREFIX = 'workflow:long-poll-signal:';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $signalTtlSeconds = 60,
    ) {
    }

    public function snapshot(array $channels): array
    {
        $snapshot = [];

        foreach ($this->normalizeChannels($channels) as $channel) {
            $snapshot[$channel] = $this->version($channel);
        }

        return $snapshot;
    }

    public function changed(array $snapshot): bool
    {
        foreach ($snapshot as $channel => $version) {
            if ($this->version($channel) !== $version) {
                return true;
            }
        }

        return false;
    }

    public function signal(string ...$channels): void
    {
        $channels = $this->normalizeChannels($channels);

        if ($channels === []) {
            return;
        }

        $version = sprintf('%.6F:%s', microtime(true), (string) Str::ulid());

        foreach ($channels as $channel) {
            $this->cache->put($this->cacheKey($channel), $version, now() ->addSeconds($this->signalTtlSeconds));
        }
    }

    public function workflowTaskPollChannels(string $namespace, ?string $connection, ?string $queue): array
    {
        return $this->normalizeChannels([
            $this->queueChannel('workflow-tasks', null, $connection, $queue),
            $this->queueChannel('workflow-tasks', $namespace, $connection, $queue),
        ]);
    }

    public function activityTaskPollChannels(string $namespace, ?string $connection, ?string $queue): array
    {
        return $this->normalizeChannels([
            $this->queueChannel('activity-tasks', null, $connection, $queue),
            $this->queueChannel('activity-tasks', $namespace, $connection, $queue),
        ]);
    }

    public function historyRunChannel(string $runId): string
    {
        return sprintf('history:%s', $runId);
    }

    /**
     * Signal that a history event was created.
     *
     * Convenience method for history event observers.
     */
    public function signalHistoryEvent(WorkflowHistoryEvent $event): void
    {
        if (! is_string($event->workflow_run_id) || $event->workflow_run_id === '') {
            return;
        }

        $this->signal($this->historyRunChannel($event->workflow_run_id));
    }

    /**
     * Signal that a task was created/updated/deleted.
     *
     * Convenience method for task observers.
     */
    public function signalTask(WorkflowTask $task): void
    {
        $taskType = $this->taskType($task->task_type);

        if (! $taskType instanceof TaskType) {
            return;
        }

        $namespace = $this->namespaceForTask($task);
        $channels = match ($taskType) {
            TaskType::Workflow => [
                $this->queueChannel('workflow-tasks', null, $task->connection, $task->queue),
                $this->queueChannel('workflow-tasks', null, null, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('workflow-tasks', $namespace, $task->connection, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('workflow-tasks', $namespace, null, $task->queue),
            ],
            TaskType::Activity => [
                $this->queueChannel('activity-tasks', null, $task->connection, $task->queue),
                $this->queueChannel('activity-tasks', null, null, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('activity-tasks', $namespace, $task->connection, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('activity-tasks', $namespace, null, $task->queue),
            ],
            default => [],
        };

        $this->signal(...$channels);
    }

    /**
     * Signal all task queues for a given workflow instance.
     *
     * Used when canceling or terminating a workflow to wake waiting pollers.
     */
    public function signalWorkflowTaskQueuesForWorkflow(string $workflowId, ?string $namespace = null): void
    {
        $namespace ??= $this->namespaceForWorkflow($workflowId);

        $tasks = WorkflowTask::query()
            ->select('workflow_tasks.*')
            ->join('workflow_runs', 'workflow_runs.id', '=', 'workflow_tasks.workflow_run_id')
            ->where('workflow_runs.workflow_instance_id', $workflowId)
            ->whereIn('workflow_tasks.task_type', [TaskType::Workflow->value, TaskType::Activity->value])
            ->get();

        foreach ($tasks as $task) {
            if (! $task instanceof WorkflowTask) {
                continue;
            }

            $this->signalTaskWithNamespace($task, $namespace);
        }
    }

    private function signalTaskWithNamespace(WorkflowTask $task, ?string $namespace): void
    {
        $taskType = $this->taskType($task->task_type);

        if (! $taskType instanceof TaskType) {
            return;
        }

        $channels = match ($taskType) {
            TaskType::Workflow => [
                $this->queueChannel('workflow-tasks', null, $task->connection, $task->queue),
                $this->queueChannel('workflow-tasks', null, null, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('workflow-tasks', $namespace, $task->connection, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('workflow-tasks', $namespace, null, $task->queue),
            ],
            TaskType::Activity => [
                $this->queueChannel('activity-tasks', null, $task->connection, $task->queue),
                $this->queueChannel('activity-tasks', null, null, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('activity-tasks', $namespace, $task->connection, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('activity-tasks', $namespace, null, $task->queue),
            ],
            default => [],
        };

        $this->signal(...$channels);
    }

    private function namespaceForTask(WorkflowTask $task): ?string
    {
        // Tasks now carry the native namespace column — read it directly
        // instead of the previous two-step lookup (task → run → instance).
        $namespace = $task->namespace;

        if (is_string($namespace) && $namespace !== '') {
            return $namespace;
        }

        // Fallback for tasks created before the native column was populated.
        if (! is_string($task->workflow_run_id) || $task->workflow_run_id === '') {
            return null;
        }

        $workflowId = WorkflowRun::query()
            ->whereKey($task->workflow_run_id)
            ->value('workflow_instance_id');

        return is_string($workflowId) && $workflowId !== ''
            ? $this->namespaceForWorkflow($workflowId)
            : null;
    }

    private function namespaceForWorkflow(string $workflowId): ?string
    {
        $namespace = WorkflowInstance::query()
            ->whereKey($workflowId)
            ->value('namespace');

        return is_string($namespace) && $namespace !== ''
            ? $namespace
            : null;
    }

    private function version(string $channel): ?string
    {
        $version = $this->cache->get($this->cacheKey($channel));

        return is_string($version) && $version !== ''
            ? $version
            : null;
    }

    private function cacheKey(string $channel): string
    {
        return self::CACHE_PREFIX . sha1($channel);
    }

    private function queueChannel(string $plane, ?string $namespace, mixed $connection, mixed $queue): string
    {
        return implode(':', array_filter([
            $plane,
            $namespace === null ? 'shared' : 'namespace',
            $namespace,
            $this->normalizeString($connection) ?? 'any-connection',
            $this->normalizeString($queue) ?? 'any-queue',
        ], static fn (mixed $segment): bool => $segment !== null && $segment !== ''));
    }

    /**
     * @param  list<string|null>  $channels
     * @return list<string>
     */
    private function normalizeChannels(array $channels): array
    {
        $normalized = [];

        foreach ($channels as $channel) {
            if (! is_string($channel)) {
                continue;
            }

            $channel = trim($channel);

            if ($channel === '') {
                continue;
            }

            $normalized[$channel] = $channel;
        }

        return array_values($normalized);
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === ''
            ? null
            : $value;
    }

    private function taskType(mixed $value): ?TaskType
    {
        if ($value instanceof TaskType) {
            return $value;
        }

        return is_string($value)
            ? TaskType::tryFrom($value)
            : null;
    }
}
