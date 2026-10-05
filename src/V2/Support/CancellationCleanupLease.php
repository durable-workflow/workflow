<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Models\WorkflowRun;

/** @internal Renewable cleanup ownership, separate from the immutable budget. */
final class CancellationCleanupLease
{
    public const MAXIMUM_SECONDS = 10;

    public static function deadline(?WorkflowRun $run): ?CarbonInterface
    {
        return $run?->cancellation_request_command_id !== null
            ? $run->cancellation_deadline_at : null;
    }

    public static function expiresAt(?WorkflowRun $run): CarbonInterface
    {
        $deadline = self::deadline($run);

        if ($deadline !== null) {
            return self::forDeadline($deadline);
        }

        return self::forScopes($run);
    }

    public static function forScopes(?WorkflowRun $run): CarbonInterface
    {
        $deadline = self::deadline($run);
        if ($deadline !== null) {
            return self::forDeadline($deadline);
        }
        // A scoped budget bounds callback authority, not the lifetime of its
        // unaffected siblings. Keep shared ownership recoverable while that
        // original budget is live without ending the whole claim at its deadline.
        if ($run?->cancellation_scope_recovery_until !== null
            && now()
                ->lt($run->cancellation_scope_recovery_until)) {
            return self::renewableExpiry();
        }

        return WorkflowTaskLease::expiresAt();
    }

    public static function forDeadline(CarbonInterface $deadline): CarbonInterface
    {
        $expiry = self::renewableExpiry();

        return $deadline->lt($expiry) ? $deadline->copy() : $expiry;
    }

    /**
     * A renewable ownership interval, never another cancellation budget.
     */
    public static function renewableExpiry(): CarbonInterface
    {
        $now = now();
        $expiry = WorkflowTaskLease::expiresAt($now);
        $maximum = $now->copy()
            ->addSeconds(self::MAXIMUM_SECONDS);
        if ($maximum->lt($expiry)) {
            $expiry = $maximum;
        }

        return $expiry;
    }
}
