<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Activity;
use Workflow\V2\Models\ActivityExecution;

final class ActivityRetryPolicy
{
    public const SNAPSHOT_VERSION = 1;

    /**
     * @return array{
     *     snapshot_version: int,
     *     max_attempts: int|null,
     *     backoff_seconds: list<int>,
     *     start_to_close_timeout: int|null,
     *     schedule_to_start_timeout: int|null,
     *     schedule_to_close_timeout: int|null,
     *     heartbeat_timeout: int|null,
     *     non_retryable_error_types: list<string>
     * }
     */
    public static function snapshot(Activity $activity, ?ActivityOptions $options = null): array
    {
        $maxAttempts = $options?->maxAttempts ?? null;

        if ($maxAttempts === null) {
            $maxAttempts = self::maxAttemptsFromActivity($activity);
            $maxAttempts = $maxAttempts === PHP_INT_MAX ? null : $maxAttempts;
        }

        $backoff = $options?->backoff !== null
            ? self::normalizeBackoff($options->backoff)
            : self::backoffSecondsFromActivity($activity);

        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'max_attempts' => $maxAttempts,
            'backoff_seconds' => $backoff,
            'start_to_close_timeout' => $options?->startToCloseTimeout,
            'schedule_to_start_timeout' => $options?->scheduleToStartTimeout,
            'schedule_to_close_timeout' => $options?->scheduleToCloseTimeout,
            'heartbeat_timeout' => $options?->heartbeatTimeout,
            'non_retryable_error_types' => self::normalizeErrorTypes($options?->nonRetryableErrorTypes ?? []),
        ];
    }

    /**
     * Snapshot retry and timeout options supplied by an external worker command.
     *
     * External workers do not have a PHP activity class to fall back to, so the
     * default retry budget is one attempt unless the command supplies a policy.
     *
     * @param array<string, mixed>|null $retryPolicy
     * @return array{
     *     snapshot_version: int,
     *     max_attempts: int|null,
     *     backoff_seconds: list<int>,
     *     start_to_close_timeout: int|null,
     *     schedule_to_start_timeout: int|null,
     *     schedule_to_close_timeout: int|null,
     *     heartbeat_timeout: int|null,
     *     non_retryable_error_types: list<string>
     * }|null
     */
    public static function snapshotExternal(?array $retryPolicy, ?ActivityOptions $options = null): ?array
    {
        $retryPolicy ??= [];

        if ($retryPolicy === [] && $options === null) {
            return null;
        }

        $maxAttempts = is_int($retryPolicy['max_attempts'] ?? null)
            ? max(1, (int) $retryPolicy['max_attempts'])
            : ($options?->maxAttempts ?? 1);

        $backoff = is_array($retryPolicy['backoff_seconds'] ?? null)
            ? self::normalizeBackoff($retryPolicy['backoff_seconds'])
            : ($options?->backoff !== null ? self::normalizeBackoff($options->backoff) : []);

        $nonRetryableErrorTypes = is_array($retryPolicy['non_retryable_error_types'] ?? null)
            ? self::normalizeErrorTypes($retryPolicy['non_retryable_error_types'])
            : self::normalizeErrorTypes($options?->nonRetryableErrorTypes ?? []);

        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'max_attempts' => $maxAttempts,
            'backoff_seconds' => $backoff,
            'start_to_close_timeout' => $options?->startToCloseTimeout,
            'schedule_to_start_timeout' => $options?->scheduleToStartTimeout,
            'schedule_to_close_timeout' => $options?->scheduleToCloseTimeout,
            'heartbeat_timeout' => $options?->heartbeatTimeout,
            'non_retryable_error_types' => $nonRetryableErrorTypes,
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

    public static function isNonRetryableFailure(ActivityExecution $execution, \Throwable $throwable): bool
    {
        if (FailureFactory::isNonRetryable($throwable)) {
            return true;
        }

        $policy = self::policy($execution);
        $nonRetryableErrorTypes = is_array($policy['non_retryable_error_types'] ?? null)
            ? self::normalizeErrorTypes($policy['non_retryable_error_types'])
            : [];

        if ($nonRetryableErrorTypes === []) {
            return false;
        }

        $payload = FailureFactory::payload($throwable);
        $candidates = array_filter([
            is_string($payload['class'] ?? null) ? $payload['class'] : null,
            is_string($payload['type'] ?? null) ? $payload['type'] : null,
            $throwable::class,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $nonRetryableErrorTypes, true)) {
                return true;
            }
        }

        return false;
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

    /**
     * @param array<int, mixed> $types
     * @return list<string>
     */
    private static function normalizeErrorTypes(array $types): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                    ? trim($value)
                    : null,
                $types,
            ),
            static fn (?string $value): bool => $value !== null,
        )));
    }
}
