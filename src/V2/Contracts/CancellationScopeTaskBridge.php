<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Workflow\V2\Support\WorkerProtocolVersion;

/**
 * @internal Optional scope-opening role for the unfrozen 1.20 candidate.
 * Opening commits before the SDK enters a scope body and retains the claim.
 * This role alone does not advertise scope delivery or physical supervision.
 */
interface CancellationScopeTaskBridge extends WorkflowTaskBridge
{
    /**
     * @return array<string, mixed>
     */
    public function openCancellationScope(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        int $sequence,
        string $parentScopeId = 'root',
        bool $shieldParent = false,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;
}
