<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

/**
 * A run failed because its workflow task kept losing its lease without
 * completing, with workflows.v2.workflow_task_lost_leases.fail_run enabled
 * (#1231). The usual cause is a queue worker job timeout shorter than the
 * workflow task.
 */
final class WorkflowTaskLeaseLostException extends RuntimeException
{
    public function __construct(
        public readonly string $taskId,
        public readonly int $lostLeases,
        public readonly int $leaseSeconds,
    ) {
        parent::__construct(sprintf(
            'Workflow task %s lost its lease %d times without completing. Its queue worker may be killing it '
            . 'at the job timeout; run workers with --timeout=0 or above the longest workflow task.',
            $taskId,
            $lostLeases,
        ));
    }
}
