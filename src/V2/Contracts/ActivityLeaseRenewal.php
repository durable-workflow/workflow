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
     * @return array<string, mixed>
     */
    public function renewLease(string $attemptId, string $leaseOwner): array;
}
