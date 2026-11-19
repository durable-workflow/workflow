<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * An optional queue job timeout for workflow tasks.
 *
 * With pcntl, Laravel's worker kills a job that runs longer than its timeout,
 * and a job's own timeout replaces the worker's `--timeout` for that job. By
 * default workflow task jobs carry no timeout, so the worker's `--timeout`
 * applies to them as it does to every other job.
 *
 * When `workflows.v2.workflow_task_job_timeout_seconds` is configured, every
 * workflow task job carries that timeout instead, so workflow tasks can run
 * longer (or shorter) than the activities on the same workers. It is kept
 * 10 seconds below the queue connection's `retry_after`, which Laravel
 * requires to exceed a job's timeout so the queue never hands a running job
 * to another worker.
 */
final class WorkflowTaskJobTimeout
{
    public const CONFIG_KEY = 'workflows.v2.workflow_task_job_timeout_seconds';

    /**
     * Seconds kept between `retry_after` and the job timeout.
     */
    public const RETRY_AFTER_MARGIN_SECONDS = 10;

    /**
     * The job timeout, in seconds, for a workflow task on $connection, or
     * null (the default) to leave the worker's `--timeout` in force.
     */
    public static function seconds(?string $connection = null): ?int
    {
        $configured = self::configuredSeconds();

        if ($configured === null) {
            return null;
        }

        $retryAfter = self::retryAfter($connection);
        $timeout = $retryAfter === null
            ? $configured
            : min($configured, $retryAfter - self::RETRY_AFTER_MARGIN_SECONDS);

        return $timeout >= 1 ? $timeout : null;
    }

    /**
     * The explicitly configured timeout, or null when none is configured.
     */
    public static function configuredSeconds(): ?int
    {
        $configured = config(self::CONFIG_KEY);

        return is_numeric($configured) && (int) $configured >= 1 ? (int) $configured : null;
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
