<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class WorkflowStartGate
{
    public const BLOCKED_COMPATIBILITY = 'compatibility_blocked';

    public static function blockedReason(
        ?string $required,
        ?string $connection = null,
        ?string $queue = null,
    ): ?string {
        if (config('workflows.v2.fleet.validation_mode') !== 'fail') {
            return null;
        }

        if ($required === null) {
            return null;
        }

        if (WorkerCompatibilityFleet::activeWorkerCount($connection, $queue) === 0) {
            return null;
        }

        if (WorkerCompatibilityFleet::supports($required, $connection, $queue)) {
            return null;
        }

        return self::BLOCKED_COMPATIBILITY;
    }

    public static function blockedMessage(
        string $prefix,
        ?string $required,
        ?string $connection = null,
        ?string $queue = null,
    ): ?string {
        if (self::blockedReason($required, $connection, $queue) === null) {
            return null;
        }

        $reason = WorkerCompatibilityFleet::mismatchReason($required, $connection, $queue)
            ?? 'No compatible worker is live for the requested queue.';

        return sprintf('%s Start blocked under fail validation mode. %s', $prefix, $reason);
    }
}
