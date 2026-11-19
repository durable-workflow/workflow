<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

/**
 * A run failed because its workflow task kept losing its lease without
 * completing, with workflows.v2.workflow_task_lost_leases.fail_run enabled.
 * A lease is lost when the worker running the task is killed, times out, or
 * loses its connection before the task completes.
 */
final class WorkflowTaskLeaseLostException extends RuntimeException
{
    public function __construct(
        public readonly string $taskId,
        public readonly int $lostLeases,
        public readonly int $leaseSeconds,
    ) {
        parent::__construct(sprintf(
            'Workflow task %s lost its lease %d times in a row without completing (its worker was killed, '
            . 'timed out, or lost its connection).',
            $taskId,
            $lostLeases,
        ));
    }
}
