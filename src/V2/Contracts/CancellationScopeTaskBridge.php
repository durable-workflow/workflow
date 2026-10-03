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
     * Commit ordinary commands preceding a scope without releasing the claim.
     * The SDK must replay the canonical history before opening the scope.
     * No local callback admission or scope delivery capability is implied.
     *
     * @param list<array{type: string, ...}> $commands
     * @return array<string, mixed>
     */
    public function checkpointCancellationScopePrefix(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        string $checkpointId,
        int $startSequence,
        array $commands,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;

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
