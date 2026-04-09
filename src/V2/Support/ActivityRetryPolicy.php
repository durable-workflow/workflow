<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Activity;
use Workflow\V2\Models\ActivityExecution;

final class ActivityRetryPolicy
{
    public const SNAPSHOT_VERSION = 1;

    /**
     * @return array{snapshot_version: int, max_attempts: int|null, backoff_seconds: list<int>}
     */
    public static function snapshot(Activity $activity): array
    {
        $maxAttempts = self::maxAttemptsFromActivity($activity);

        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'max_attempts' => $maxAttempts === PHP_INT_MAX ? null : $maxAttempts,
            'backoff_seconds' => self::backoffSecondsFromActivity($activity),
        ];
    }

    public static function maxAttempts(ActivityExecution $execution, Activity $fallback): int
    {
        $policy = self::policy($execution);
        $maxAttempts = $policy['max_attempts'] ?? null;

        if (is_int($maxAttempts)) {
            return max(1, $maxAttempts);
        }

        if ($maxAttempts === null && array_key_exists('max_attempts', $policy)) {
            return PHP_INT_MAX;
        }

        return self::maxAttemptsFromActivity($fallback);
    }

    public static function backoffSeconds(ActivityExecution $execution, Activity $fallback, int $attemptCount): int
    {
        $policy = self::policy($execution);
        $backoff = is_array($policy['backoff_seconds'] ?? null)
            ? self::normalizeBackoff($policy['backoff_seconds'])
            : [];

        if ($backoff === [] && ! array_key_exists('backoff_seconds', $policy)) {
            $backoff = self::backoffSecondsFromActivity($fallback);
        }

        if ($backoff === []) {
            return 0;
        }

        $index = max(0, $attemptCount - 1);

        return $backoff[min($index, count($backoff) - 1)];
    }

    public static function maxAttemptsFromSnapshot(ActivityExecution $execution): int
    {
        $policy = self::policy($execution);
        $maxAttempts = $policy['max_attempts'] ?? null;

        if (is_int($maxAttempts)) {
            return max(1, $maxAttempts);
        }

        if ($maxAttempts === null && array_key_exists('max_attempts', $policy)) {
            return PHP_INT_MAX;
        }

        return 1;
    }

    public static function backoffSecondsFromSnapshot(ActivityExecution $execution, int $attemptCount): int
    {
        $policy = self::policy($execution);
        $backoff = is_array($policy['backoff_seconds'] ?? null)
            ? self::normalizeBackoff($policy['backoff_seconds'])
            : [];

        if ($backoff === []) {
            return 0;
        }

        $index = max(0, $attemptCount - 1);

        return $backoff[min($index, count($backoff) - 1)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function policy(ActivityExecution $execution): array
    {
        return is_array($execution->retry_policy) ? $execution->retry_policy : [];
    }

    private static function maxAttemptsFromActivity(Activity $activity): int
    {
        $tries = $activity->tries;

        return $tries <= 0 ? PHP_INT_MAX : $tries;
    }

    /**
     * @return list<int>
     */
    private static function backoffSecondsFromActivity(Activity $activity): array
    {
        return self::normalizeBackoff($activity->backoff());
    }

    /**
     * @param int|array<int, int|string> $backoff
     * @return list<int>
     */
    private static function normalizeBackoff(int|array $backoff): array
    {
        if (is_int($backoff)) {
            return [max(0, $backoff)];
        }

        return array_values(array_map(
            static fn (int|string $value): int => max(0, (int) $value),
            array_filter(
                $backoff,
                static fn (mixed $value): bool => is_int($value) || (is_string($value) && is_numeric($value)),
            ),
        ));
    }
}
