<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;

/**
 * Caps timer task delays to fit within queue driver constraints.
 *
 * SQS limits initial DelaySeconds to 900. Other drivers may impose similar
 * ceilings. When a timer's fire_at exceeds the queue's max delay, the
 * transport dispatches the task with a capped delay. RunTimerTask detects
 * the early arrival (available_at still in the future) and re-releases
 * with the remaining seconds, repeating until the real fire_at is reached.
 *
 * ChangeMessageVisibility (used by release()) supports up to 43200 seconds
 * on SQS, so subsequent relay hops can use longer delays than the initial
 * dispatch. For truly long timers (> 12 hours), the relay chain continues
 * with capped release delays.
 */
final class TimerTransportChunker
{
    /**
     * Maximum seconds for a release() / ChangeMessageVisibility on SQS.
     * Non-SQS drivers generally have no practical ceiling on release delay.
     */
    private const MAX_RELEASE_SECONDS_SQS = 43200;

    /**
     * Conservative default used when no driver-specific limit is known.
     */
    private const DEFAULT_MAX_RELEASE_SECONDS = 43200;

    /**
     * Return the maximum initial dispatch delay in seconds for the given
     * queue connection, or null if no cap applies.
     */
    public static function maxDispatchDelaySeconds(?string $connection = null): ?int
    {
        $snapshot = BackendCapabilities::snapshot(queueConnection: $connection);
        $maxDelay = $snapshot['queue']['capabilities']['max_delay_seconds'] ?? null;

        return is_int($maxDelay) && $maxDelay > 0 ? $maxDelay : null;
    }

    /**
     * Return the maximum release delay in seconds for the given queue
     * connection, or null if no cap applies.
     */
    public static function maxReleaseDelaySeconds(?string $connection = null): ?int
    {
        $snapshot = BackendCapabilities::snapshot(queueConnection: $connection);
        $driver = $snapshot['queue']['driver'] ?? null;

        if ($driver === 'sqs') {
            return self::MAX_RELEASE_SECONDS_SQS;
        }

        return null;
    }

    /**
     * Determine the effective delay for dispatching a timer task.
     *
     * Returns the available_at as-is when it fits within the queue cap,
     * or a capped Carbon instance when chunking is required.
     */
    public static function cappedDispatchDelay(
        CarbonInterface $availableAt,
        ?string $connection = null,
    ): CarbonInterface {
        $maxDelay = self::maxDispatchDelaySeconds($connection);

        if ($maxDelay === null) {
            return $availableAt;
        }

        $now = now();
        $delaySeconds = max(0, $availableAt->getTimestamp() - $now->getTimestamp());

        if ($delaySeconds <= $maxDelay) {
            return $availableAt;
        }

        return $now->addSeconds($maxDelay);
    }

    /**
     * Cap a release delay (seconds) to the queue driver's ceiling.
     *
     * Used by RunTimerTask when it arrives before fire_at and needs
     * to re-release itself for the remaining duration.
     */
    public static function cappedReleaseDelay(int $seconds, ?string $connection = null): int
    {
        $maxRelease = self::maxReleaseDelaySeconds($connection);

        if ($maxRelease === null) {
            return $seconds;
        }

        return min($seconds, $maxRelease);
    }
}
