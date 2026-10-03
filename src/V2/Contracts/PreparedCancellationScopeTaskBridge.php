<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Workflow\V2\Support\WorkerProtocolVersion;

/**
 * @internal Optional authenticated preparation/recording role for unfrozen 1.20.
 * This role does not advertise scope execution or supervise callbacks.
 */
interface PreparedCancellationScopeTaskBridge extends CancellationScopeTaskBridge
{
    /**
     * @return array<string, mixed>
     */
    public function prepareCancellationScopeDelivery(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        string $scopeId,
        string $requestId,
        int $sequence,
        string $callKind,
        int $sequenceSpan = 1,
        ?int $operationSequence = null,
        int $operationSequenceSpan = 1,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;

    /**
     * Record delivery only after the original members' policy proofs exist.
     * Pending stop proof retains the preparation and workflow claim.
     * @return array<string, mixed>
     */
    public function deliverCancellationScope(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        string $scopeId,
        string $requestId,
        int $sequence,
        string $callKind,
        int $sequenceSpan = 1,
        ?int $operationSequence = null,
        int $operationSequenceSpan = 1,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;
}
