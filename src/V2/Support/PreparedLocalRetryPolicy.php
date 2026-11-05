<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;

/**
 * @internal Pure version-2 prepared-policy normalization. A normalized policy
 * does not grant prepared-role, original-claim or capability authority.
 */
final class PreparedLocalRetryPolicy
{
    public const SNAPSHOT_VERSION = 2;

    public const MAX_ATTEMPTS = 1024;

    public const MAX_DELAY_SECONDS = 9007199254740991;

    public const MAX_TIMEOUT_SECONDS = 2147483647;

    private const TIMEOUT_FIELDS = ['start_to_close_timeout', 'schedule_to_start_timeout',
        'schedule_to_close_timeout', 'heartbeat_timeout'];

    /**
     * The prepared wire carries an already-flattened schedule. Authoring
     * generator descriptors belong to a separate bounded expansion phase.
     *
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $timeouts
     * @return array<string, mixed>
     */
    public static function normalize(array $policy, array $timeouts = []): array
    {
        self::fields(
            $policy,
            ['contract_version', 'mode', 'backoff_seconds'],
            ['max_attempts', 'maximum_interval_seconds', 'non_retryable_error_types']
        );
        if ($policy['contract_version'] !== 2 || ! in_array($policy['mode'], ['explicit', 'generated'], true)) {
            throw new InvalidArgumentException('Expected an explicit version-2 prepared retry policy.');
        }
        $attempts = array_key_exists('max_attempts', $policy) ? $policy['max_attempts'] : 1;
        self::integer($attempts, 1, self::MAX_ATTEMPTS, 'max_attempts');
        $backoff = $policy['backoff_seconds'];
        if (! is_array($backoff) || ! array_is_list($backoff) || count($backoff) > 1024) {
            throw new InvalidArgumentException('backoff_seconds must be a list of at most 1024 integers.');
        }
        foreach ($backoff as $delay) {
            self::integer($delay, 0, self::MAX_DELAY_SECONDS, 'backoff_seconds');
        }
        $largest = $backoff === [] ? 0 : max($backoff);
        $maximum = array_key_exists('maximum_interval_seconds', $policy)
            ? $policy['maximum_interval_seconds'] : ($policy['mode'] === 'generated' ? 60 : $largest);
        self::integer($maximum, 0, self::MAX_DELAY_SECONDS, 'maximum_interval_seconds');
        if ($maximum < $largest) {
            throw new InvalidArgumentException('The policy cap cannot be smaller than a supplied delay.');
        }
        self::fields($timeouts, [], self::TIMEOUT_FIELDS);
        foreach (self::TIMEOUT_FIELDS as $field) {
            $value = $timeouts[$field] ?? null;
            if ($field === 'schedule_to_start_timeout') {
                if ($value !== null) {
                    throw new InvalidArgumentException('Prepared local activities bypass the queue.');
                }
            } elseif ($value !== null) {
                self::integer($value, 1, self::MAX_TIMEOUT_SECONDS, $field);
            }
        }
        $types = array_key_exists('non_retryable_error_types', $policy) ? $policy['non_retryable_error_types'] : [];

        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'mode' => $policy['mode'],
            'max_attempts' => $attempts,
            'backoff_seconds' => $backoff,
            'maximum_interval_seconds' => $maximum,
            'start_to_close_timeout' => $timeouts['start_to_close_timeout'] ?? null,
            'schedule_to_start_timeout' => null,
            'schedule_to_close_timeout' => $timeouts['schedule_to_close_timeout'] ?? null,
            'heartbeat_timeout' => $timeouts['heartbeat_timeout'] ?? null,
            'non_retryable_error_types' => self::errorTypes($types),
        ];
    }

    /**
     * Read the exact retained format without repairing or upgrading it.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public static function validateSnapshot(array $snapshot): array
    {
        self::fields($snapshot, array_merge(['snapshot_version', 'mode', 'max_attempts',
            'backoff_seconds', 'maximum_interval_seconds', 'non_retryable_error_types'], self::TIMEOUT_FIELDS));
        if ($snapshot['snapshot_version'] !== self::SNAPSHOT_VERSION) {
            throw new InvalidArgumentException('Expected retained prepared retry snapshot version 2.');
        }
        $policy = array_intersect_key($snapshot, array_flip(['mode', 'max_attempts', 'backoff_seconds',
            'maximum_interval_seconds', 'non_retryable_error_types']));
        $normalized = self::normalize(
            [
                'contract_version' => 2,
                ...$policy,
            ],
            array_intersect_key($snapshot, array_flip(self::TIMEOUT_FIELDS))
        );
        foreach ($normalized as $field => $value) {
            if ($snapshot[$field] !== $value) {
                throw new InvalidArgumentException('Retained retry snapshot is not normalized: ' . $field);
            }
        }

        return $normalized;
    }

    /**
     * Only select after the failed attempt still has retry budget. The caller
     * separately owns failure precedence, clock/deadline checks and effects.
     *
     * @param array<string, mixed> $snapshot
     */
    public static function delayForRetry(array $snapshot, int $failedAttempt): int
    {
        $snapshot = self::validateSnapshot($snapshot);
        if ($failedAttempt < 1 || $failedAttempt >= $snapshot['max_attempts']) {
            throw new InvalidArgumentException('The failed attempt has no retry budget.');
        }
        $backoff = $snapshot['backoff_seconds'];

        return $backoff === [] ? 0 : $backoff[min($failedAttempt - 1, count($backoff) - 1)];
    }

    /**
     * @return list<string>
     */
    private static function errorTypes(mixed $types): array
    {
        if (! is_array($types) || ! array_is_list($types) || count($types) > 128) {
            throw new InvalidArgumentException('Expected at most 128 non-retryable error types.');
        }
        $normalized = [];
        foreach ($types as $type) {
            if (! is_string($type) || strlen($type) > 255 || preg_match('//u', $type) !== 1 || trim($type) === '') {
                throw new InvalidArgumentException('Invalid non-retryable error type.');
            }
            $type = trim($type);
            if (! in_array($type, $normalized, true)) {
                $normalized[] = $type;
            }
        }

        return $normalized;
    }

    private static function integer(mixed $value, int $minimum, int $maximum, string $field): void
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('Invalid prepared retry integer: ' . $field);
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function fields(array $value, array $required, array $optional = []): void
    {
        $keys = array_keys($value);
        if (array_diff($required, $keys) !== [] || array_diff($keys, array_merge($required, $optional)) !== []) {
            throw new InvalidArgumentException('Missing or unknown prepared retry field.');
        }
    }
}
