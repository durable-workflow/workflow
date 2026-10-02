<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Workflow\V2\Support\WorkerProtocolVersion;

/**
 * @internal Candidate protocol 1.20 local callback persistence. Existing
 * WorkflowTaskBridge implementations need not provide this optional role.
 * The caller must check the actual bound instance before advertising support.
 * This role alone does not qualify an SDK's physical callback supervisor.
 */
interface PreparedLocalActivityTaskBridge extends WorkflowTaskBridge
{
    /** @param list<array{type: string, ...}> $commands
     * @return array<string, mixed>
     */
    public function checkpointLocalActivityPrefix(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        string $checkpointId,
        int $startSequence,
        array $commands,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;

    /** @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public function prepareLocalActivity(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        int $sequence,
        string $workerAttemptId,
        array $descriptor,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;

    /** @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public function recordLocalActivityOutcome(
        string $attemptId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        array $report,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;

    /** @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public function recoverLocalActivity(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        int $sequence,
        array $descriptor,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;

    /**
     * Poll cancellation and authority independently of application heartbeats.
     * Optional renewal commits the attempt and hosting claim together.
     * @return array<string, mixed>
     */
    public function controlLocalActivity(
        string $attemptId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        bool $renewLease = false,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;

    /**
     * Record a real application heartbeat. This does not renew either lease.
     * @param array<string, mixed> $progress
     * @return array<string, mixed>
     */
    public function heartbeatLocalActivity(
        string $attemptId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        array $progress = [],
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function acknowledgeLocalActivityCancellation(
        string $attemptId,
        string $leaseOwner,
        string $requestId,
        int $workflowTaskAttempt,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;
}
