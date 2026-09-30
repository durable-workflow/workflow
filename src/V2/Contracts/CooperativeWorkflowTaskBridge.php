<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

interface CooperativeWorkflowTaskBridge extends WorkflowTaskBridge
{
    /**
     * Persist delivery at an authored call while retaining the workflow lease.
     * A retry must name the same request, call kind and operation range.
     *
     * @return array{
     *     delivered: bool,
     *     task_id: string,
     *     workflow_run_id: string|null,
     *     request_id: string|null,
     *     sequence: int|null,
     *     call_kind: string|null,
     *     sequence_span: int|null,
     *     operation_sequence: int|null,
     *     operation_sequence_span: int|null,
     *     reason: string|null
     * }
     */
    public function deliverCancellation(
        string $taskId,
        string $requestId,
        int $sequence,
        string $callKind,
        int $sequenceSpan = 1,
        ?int $operationSequence = null,
        int $operationSequenceSpan = 1,
    ): array;
}
