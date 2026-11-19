<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\Log;

/**
 * Warn once per worker process when its queue connection limits how long a
 * workflow task can run.
 *
 * A workflow task's job carries its own timeout ({@see WorkflowTaskJobTimeout}),
 * so the worker's `--timeout` no longer applies to it. That timeout is kept
 * below the connection's `retry_after`, so a low `retry_after` (90 seconds in
 * Laravel's default configuration) still caps workflow tasks well below their
 * lease. Only workers that serve the queue the package dispatches workflow
 * tasks to are warned, and only where pcntl enforces job timeouts.
 *
 * @internal
 */
final class WorkerTimeoutAdvisory
{
    private static bool $warned = false;

    /**
     * The warning for a worker on $connection, or null when workflow tasks
     * there can run up to their uncapped timeout.
     */
    public static function message(
        bool $pcntlLoaded,
        ?int $workflowTaskTimeout,
        int $uncappedTimeout,
        ?int $retryAfter,
        string $connection,
    ): ?string {
        if (! $pcntlLoaded || ($workflowTaskTimeout !== null && $workflowTaskTimeout >= $uncappedTimeout)) {
            return null;
        }

        return sprintf(
            '[Durable Workflow] Queue connection "%s" has retry_after = %s s, so a workflow task on it can run '
            . 'for at most %s before the worker kills it and the task is retried after its lease expires. '
            . 'Set this connection\'s retry_after above %d s (the workflow task timeout plus %d s), ideally on '
            . 'a connection dedicated to workflow tasks, so workflow tasks can run for %d s.',
            $connection,
            $retryAfter === null ? 'unset' : (string) $retryAfter,
            $workflowTaskTimeout === null ? 'the worker --timeout' : $workflowTaskTimeout . ' s',
            $uncappedTimeout + WorkflowTaskJobTimeout::RETRY_AFTER_MARGIN_SECONDS,
            WorkflowTaskJobTimeout::RETRY_AFTER_MARGIN_SECONDS,
            $uncappedTimeout,
        );
    }

    /**
     * Whether a worker started for $connection and $queues serves the queue
     * the package dispatches workflow tasks to by default.
     */
    public static function servesWorkflowQueue(?string $connection, ?string $queues): bool
    {
        $workflowConnection = config('workflows.v2.connection');
        $workflowConnection = is_string($workflowConnection) && $workflowConnection !== ''
            ? $workflowConnection
            : config('queue.default');

        if (! is_string($workflowConnection) || $connection !== $workflowConnection) {
            return false;
        }

        $workflowQueue = config('workflows.v2.queue');
        $workflowQueue = is_string($workflowQueue) && $workflowQueue !== ''
            ? $workflowQueue
            : config('queue.connections.' . $workflowConnection . '.queue', 'default');

        $served = array_map('trim', explode(',', (string) ($queues ?? '')));

        return in_array((string) $workflowQueue, $served, true);
    }

    public static function workerStarting(?string $connection, ?string $queues): void
    {
        if (self::$warned || $connection === null || ! self::servesWorkflowQueue($connection, $queues)) {
            return;
        }

        $message = self::message(
            extension_loaded('pcntl'),
            WorkflowTaskJobTimeout::seconds($connection),
            WorkflowTaskJobTimeout::uncappedSeconds(),
            WorkflowTaskJobTimeout::retryAfter($connection),
            $connection,
        );

        if ($message === null) {
            return;
        }

        self::$warned = true;
        Log::warning($message, [
            'code' => 'workflow_task_timeout_capped_by_retry_after',
            'connection' => $connection,
            'retry_after_seconds' => WorkflowTaskJobTimeout::retryAfter($connection),
            'workflow_task_timeout_seconds' => WorkflowTaskJobTimeout::seconds($connection),
            'workflow_task_lease_seconds' => WorkflowTaskLease::seconds(),
        ]);
    }

    /**
     * @internal For tests that start more than one worker in a process.
     */
    public static function reset(): void
    {
        self::$warned = false;
    }
}
