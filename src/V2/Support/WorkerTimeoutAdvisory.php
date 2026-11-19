<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\Log;

/**
 * Warn once per worker process when Laravel's job timeout can kill a
 * workflow task before its lease ends (#1231).
 *
 * With pcntl loaded, `queue:work` kills a job after `--timeout` seconds (60 by
 * default). A workflow task killed there rolls back its work and is retried
 * after its lease expires, so a task longer than the timeout never finishes.
 *
 * @internal
 */
final class WorkerTimeoutAdvisory
{
    private static bool $warned = false;

    /**
     * The warning for a worker started with $timeoutSeconds, or null when the
     * job timeout cannot end a workflow task before its lease.
     */
    public static function message(int $timeoutSeconds, bool $pcntlLoaded, int $leaseSeconds): ?string
    {
        if (! $pcntlLoaded || $timeoutSeconds <= 0 || $timeoutSeconds >= $leaseSeconds) {
            return null;
        }

        return sprintf(
            '[Durable Workflow] This queue worker kills a job after %d s (--timeout), before the %d s workflow task '
            . 'lease. A workflow task that runs longer, such as a wide all() fan-out, is killed, rolled back and '
            . 'retried after its lease expires, and can never finish. Run workers with --timeout=0 or a value above '
            . 'your longest workflow task, and keep workflows.v2.workflow_task_lease_seconds above --timeout.',
            $timeoutSeconds,
            $leaseSeconds,
        );
    }

    public static function workerStarting(object $workerOptions): void
    {
        if (self::$warned || ! property_exists($workerOptions, 'timeout')) {
            return;
        }

        $message = self::message(
            (int) $workerOptions->timeout,
            extension_loaded('pcntl'),
            WorkflowTaskLease::seconds(),
        );

        if ($message === null) {
            return;
        }

        self::$warned = true;
        Log::warning($message, [
            'code' => 'worker_timeout_below_workflow_task_lease',
            'worker_timeout_seconds' => (int) $workerOptions->timeout,
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
