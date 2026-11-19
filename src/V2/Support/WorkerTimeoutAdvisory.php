<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\Log;

/**
 * Warn once per worker process when the timeout its workflow tasks run under
 * is shorter than the workflow task lease.
 *
 * With pcntl, a worker kills a job that outlives its timeout: the worker's
 * `--timeout` (60 seconds by default), or the workflow task job timeout when
 * `workflows.v2.workflow_task_job_timeout_seconds` is configured. A workflow
 * task killed that way is retried only after its lease expires and is counted
 * as a lost lease. Only workers that serve the queue the package dispatches
 * workflow tasks to are warned.
 *
 * @internal
 */
final class WorkerTimeoutAdvisory
{
    private static bool $warned = false;

    /**
     * The warning, or null when workflow tasks on this worker are not killed
     * before their lease ends.
     */
    public static function message(
        bool $pcntlLoaded,
        int $effectiveTimeout,
        bool $jobTimeout,
        int $leaseSeconds
    ): ?string {
        if (! $pcntlLoaded || $effectiveTimeout <= 0 || $effectiveTimeout >= $leaseSeconds) {
            return null;
        }

        return sprintf(
            '[Durable Workflow] This worker kills a workflow task after %d s (%s), less than the %d s workflow task '
            . 'lease. A workflow task killed this way is retried only after its lease expires and is counted as a '
            . 'lost lease. Raise %s to cover your longest workflow task, keeping the queue connection\'s '
            . 'retry_after above it, or set DW_V2_WORKFLOW_TASK_JOB_TIMEOUT_SECONDS to give workflow tasks their '
            . 'own timeout.',
            $effectiveTimeout,
            $jobTimeout ? 'DW_V2_WORKFLOW_TASK_JOB_TIMEOUT_SECONDS' : 'the worker --timeout',
            $leaseSeconds,
            $jobTimeout ? 'DW_V2_WORKFLOW_TASK_JOB_TIMEOUT_SECONDS' : '--timeout',
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

    public static function workerStarting(?string $connection, ?string $queues, ?int $workerTimeout): void
    {
        if (self::$warned || $connection === null || ! self::servesWorkflowQueue($connection, $queues)) {
            return;
        }

        $jobTimeout = WorkflowTaskJobTimeout::seconds($connection);
        $effective = $jobTimeout ?? (int) ($workerTimeout ?? 0);
        $message = self::message(
            extension_loaded('pcntl'),
            $effective,
            $jobTimeout !== null,
            WorkflowTaskLease::seconds()
        );

        if ($message === null) {
            return;
        }

        self::$warned = true;
        Log::warning($message, [
            'code' => 'workflow_task_timeout_below_lease',
            'connection' => $connection,
            'workflow_task_timeout_seconds' => $effective,
            'workflow_task_timeout_source' => $jobTimeout !== null ? 'workflow_task_job_timeout' : 'worker_timeout',
            'workflow_task_lease_seconds' => WorkflowTaskLease::seconds(),
            'retry_after_seconds' => WorkflowTaskJobTimeout::retryAfter($connection),
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
