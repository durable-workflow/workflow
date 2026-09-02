<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum FailureCategory: string
{
    /**
     * Business logic exception thrown by workflow or activity code.
     */
    case Application = 'application';

    /**
     * Failure resulting from an explicit cancellation command.
     */
    case Cancelled = 'cancelled';

    /**
     * Failure resulting from an explicit termination command.
     */
    case Terminated = 'terminated';

    /**
     * Failure caused by a timeout expiration (activity, workflow, or task-level).
     */
    case Timeout = 'timeout';

    /**
     * Terminal activity failure propagated to the workflow.
     */
    case Activity = 'activity';

    /**
     * Terminal child workflow failure propagated to the parent.
     */
    case ChildWorkflow = 'child_workflow';

    /**
     * Workflow-task execution failure (replay, determinism, invalid command shape).
     */
    case TaskFailure = 'task_failure';

    /**
     * Server or infrastructure failure (database, queue, worker crash).
     */
    case Internal = 'internal';

    /**
     * Failure caused by exceeding a structural limit (payload size,
     * pending fan-out count, command batch size, etc.).
     */
    case StructuralLimit = 'structural_limit';
}
