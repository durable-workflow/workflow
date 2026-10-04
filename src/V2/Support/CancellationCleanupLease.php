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
            && $run?->cancellation_delivered_at !== null
            ? $run->cancellation_deadline_at : null;
    }

    public static function expiresAt(?WorkflowRun $run): CarbonInterface
    {
        $deadline = self::deadline($run);

        return $deadline === null ? WorkflowTaskLease::expiresAt() : self::forDeadline($deadline);
    }

    public static function forDeadline(CarbonInterface $deadline): CarbonInterface
    {
        $now = now();
        $expiry = WorkflowTaskLease::expiresAt($now);
        $maximum = $now->copy()
            ->addSeconds(self::MAXIMUM_SECONDS);
        if ($maximum->lt($expiry)) {
            $expiry = $maximum;
        }

        return $deadline->lt($expiry) ? $deadline->copy() : $expiry;
    }
}
