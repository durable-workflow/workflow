<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Workflow\V2\Support\WorkerProtocolVersion;

/**
 * @internal Optional prepared-local retry contract 2. This declaration binds
 * no implementation. Consumers must inspect the actual bridge instance and
 * retain original claim capability, contract 2 and snapshot 2 authority.
 * The type alone grants neither local mode nor physical callback admission.
 */
interface PreparedLocalActivityRetryTaskBridge extends PreparedLocalActivityTaskBridge
{
    /** @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public function prepareLocalActivityWithRetryPolicy(
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
    public function recordLocalActivityRetryOutcome(
        string $attemptId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        array $report,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function controlLocalActivityWithRetryPolicy(
        string $attemptId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        bool $renewLease = false,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;

    /** @param array<string, mixed> $progress
     * @return array<string, mixed>
     */
    public function heartbeatLocalActivityWithRetryPolicy(
        string $attemptId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        array $progress = [],
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;

    /** @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public function recoverLocalActivityWithRetryPolicy(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        int $sequence,
        array $descriptor,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array;
}
