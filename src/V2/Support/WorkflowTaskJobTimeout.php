<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * The queue job timeout a workflow task carries.
 *
 * With pcntl, Laravel's worker kills a job that runs longer than its timeout,
 * and a job's own timeout replaces the worker's `--timeout`. A workflow task
 * killed that way loses its lease and is retried, so a short worker timeout
 * (60 seconds by default) used to make long workflow tasks impossible.
 *
 * A workflow task's job therefore carries its own timeout: the configured
 * `workflows.v2.workflow_task_job_timeout_seconds`, or by default the
 * workflow task lease less a margin, because work past the lease cannot
 * complete. It is also kept below the queue connection's `retry_after`, so
 * the queue never hands a still-running job to another worker. Activity jobs
 * keep the worker's own bounded `--timeout`.
 */
final class WorkflowTaskJobTimeout
{
    public const CONFIG_KEY = 'workflows.v2.workflow_task_job_timeout_seconds';

    /**
     * Seconds kept between the job timeout and the task lease.
     */
    public const LEASE_MARGIN_SECONDS = 30;

    /**
     * Seconds kept between `retry_after` and the job timeout.
     */
    public const RETRY_AFTER_MARGIN_SECONDS = 10;

    /**
     * The job timeout, in seconds, for a workflow task on $connection, or
     * null to leave the worker's `--timeout` in force.
     */
    public static function seconds(?string $connection = null): ?int
    {
        $timeout = self::uncappedSeconds();
        $retryAfter = self::retryAfter($connection);

        if ($retryAfter !== null) {
            $timeout = min($timeout, $retryAfter - self::RETRY_AFTER_MARGIN_SECONDS);
        }

        return $timeout >= 1 ? $timeout : null;
    }

    /**
     * The timeout before any `retry_after` cap: the configured value, or the
     * lease less its margin.
     */
    public static function uncappedSeconds(): int
    {
        $configured = config(self::CONFIG_KEY);
        $ceiling = max(1, WorkflowTaskLease::seconds() - self::LEASE_MARGIN_SECONDS);

        if (is_numeric($configured) && (int) $configured >= 1) {
            return min((int) $configured, $ceiling);
        }

        return $ceiling;
    }

    /**
     * The queue connection's `retry_after`, or null when it has none.
     */
    public static function retryAfter(?string $connection = null): ?int
    {
        $connection ??= config('queue.default');

        if (! is_string($connection) || $connection === '') {
            return null;
        }

        $retryAfter = config("queue.connections.{$connection}.retry_after");

        return is_numeric($retryAfter) && (int) $retryAfter > 0 ? (int) $retryAfter : null;
    }
}
