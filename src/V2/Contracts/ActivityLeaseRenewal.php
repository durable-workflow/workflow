<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

/**
 * Optional runtime liveness, independent of application progress heartbeats.
 *
 * Adapters must discover this contract rather than assuming that every custom
 * ActivityTaskBridge implementation supports lease renewal.
 */
interface ActivityLeaseRenewal
{
    /**
     * Renew only the live original owner/attempt, without advancing business
     * deadlines or adding history. An expired lease cannot be revived.
     *
     * A successful acknowledgement includes the original task, run, execution,
     * attempt, attempt number and lease owner. server_time and lease_expires_at
     * use UTC ISO 8601 with six fractional digits. Consumers capture their
     * monotonic request clock before calling so response latency consumes the
     * acknowledged lease window.
     *
     * @return array<string, mixed>
     */
    public function renewLease(string $attemptId, string $leaseOwner): array;
}
