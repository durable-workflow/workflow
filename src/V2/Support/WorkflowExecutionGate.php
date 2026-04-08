<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Models\WorkflowRun;

final class WorkflowExecutionGate
{
    public const BLOCKED_WORKFLOW_DEFINITION_UNAVAILABLE = 'workflow_definition_unavailable';

    public static function blockedReason(WorkflowRun $run): ?string
    {
        try {
            TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return self::BLOCKED_WORKFLOW_DEFINITION_UNAVAILABLE;
        }

        return null;
    }

    public static function blockedMessage(
        WorkflowRun $run,
        string $operation,
        string $targetName,
    ): ?string {
        return match (self::blockedReason($run)) {
            self::BLOCKED_WORKFLOW_DEFINITION_UNAVAILABLE => sprintf(
                'Workflow %s [%s] cannot execute %s [%s] because the workflow definition is unavailable for durable type [%s].',
                $run->id,
                $run->workflow_instance_id,
                $operation,
                $targetName,
                $run->workflow_type ?? 'unknown',
            ),
            default => null,
        };
    }
}
