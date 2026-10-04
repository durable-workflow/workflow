<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Workflow\V2\Support\WorkerProtocolVersion;

/**
 * @internal Optional, unfrozen protocol 1.20 complete-group admission.
 * A checkpoint commits pending local executions, never callback execution.
 * Consumers must inspect the actual bound bridge before advertising this role.
 */
interface PreparedLocalActivityGroupTaskBridge extends PreparedLocalActivityTaskBridge
{
    /** @param list<array{type: string, ...}> $commands
     * @return array<string, mixed>
     */
    public function checkpointLocalActivityGroup(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        string $checkpointId,
        int $startSequence,
        array $commands,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;
}
