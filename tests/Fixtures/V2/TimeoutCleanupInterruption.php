<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Exceptions\WorkflowTimeoutCleanupRequested;

/**
 * What a timeout cleanup fixture saw when the interruption reached workflow
 * code (#863), recorded in its memo so a test reads it from durable state.
 */
final class TimeoutCleanupInterruption
{
    /**
     * @return array<string, string|null>
     */
    public static function describe(WorkflowTimeoutCleanupRequested $interruption): array
    {
        $context = $interruption->cancellation;

        return [
            'class' => $interruption::class,
            'cause' => $context?->cause,
            'request_id' => $context?->requestId,
            'root_request_id' => $context?->rootRequestId,
            'root_workflow_instance_id' => $context?->rootWorkflowInstanceId,
            'root_workflow_run_id' => $context?->rootWorkflowRunId,
            'parent_request_id' => $context?->parentRequestId,
            'cleanup_deadline_at' => $context?->deadline()
                ->toIso8601String(),
        ];
    }
}
