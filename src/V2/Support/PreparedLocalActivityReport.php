<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;

/**
 * @internal Pure shape preflight for an explicitly admitted prepared-v2 mode.
 * Override presence may select early validation, but never grants mode authority.
 * Completed payload resolution remains a separate operation after admission.
 */
final class PreparedLocalActivityReport
{
    public const INVALID_OVERRIDE = 'invalid_activity_retry_delay';

    public const INVALID_REPORT = 'invalid_local_activity_outcome';

    /**
     * Call before scoped lookup only when the new override member is present.
     * Without that member, the caller preserves legacy precedence until its
     * original stored authority establishes the complete v2 conjunction.
     *
     * @param array<string, mixed> $report
     */
    public static function refusal(array $report): ?string
    {
        if (array_key_exists('next_retry_delay_seconds', $report)
            && (($report['outcome'] ?? null) !== 'failed'
                || ! is_int($report['next_retry_delay_seconds'])
                || $report['next_retry_delay_seconds'] < 0
                || $report['next_retry_delay_seconds'] > PreparedLocalRetryPolicy::MAX_DELAY_SECONDS)) {
            return self::INVALID_OVERRIDE;
        }

        return match ($report['outcome'] ?? null) {
            'failed' => self::failed($report),
            'completed' => self::completed($report),
            'timed_out' => array_keys($report) === ['outcome'] ? null : self::INVALID_REPORT,
            default => self::INVALID_REPORT,
        };
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function failed(array $report): ?string
    {
        $allowed = ['outcome', 'message', 'exception_type', 'non_retryable', 'next_retry_delay_seconds'];
        if (array_diff(array_keys($report), $allowed) !== []
            || ! is_string($report['message'] ?? null)
            || preg_match('//u', $report['message']) !== 1
            || (array_key_exists('non_retryable', $report) && ! is_bool($report['non_retryable']))) {
            return self::INVALID_REPORT;
        }
        if (array_key_exists('exception_type', $report)) {
            $type = $report['exception_type'];
            if (! is_string($type) || trim($type) === '' || strlen($type) > 255 || preg_match('//u', $type) !== 1) {
                return self::INVALID_REPORT;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function completed(array $report): ?string
    {
        if (array_diff(array_keys($report), ['outcome', 'result', 'payload_codec']) !== []
            || (array_key_exists('payload_codec', $report) && $report['payload_codec'] !== 'avro')) {
            return self::INVALID_REPORT;
        }
        $result = $report['result'] ?? null;
        if (is_string($result)) {
            return $result !== '' && ($report['payload_codec'] ?? null) === 'avro' ? null : self::INVALID_REPORT;
        }
        if (! is_array($result) || ($result['codec'] ?? null) !== 'avro') {
            return self::INVALID_REPORT;
        }
        $keys = array_keys($result);
        sort($keys);
        if ($keys === ['blob', 'codec']) {
            return is_string($result['blob']) && $result['blob'] !== '' ? null : self::INVALID_REPORT;
        }
        if ($keys !== ['codec', 'external_storage'] || ! is_array($result['external_storage'])) {
            return self::INVALID_REPORT;
        }
        try {
            // This value object validates metadata only. It does not construct
            // a storage driver, fetch bytes, decode Avro or invoke callbacks.
            $reference = ExternalPayloadReference::fromArray($result['external_storage']);
        } catch (InvalidArgumentException) {
            return self::INVALID_REPORT;
        }

        return $reference->codec === 'avro' ? null : self::INVALID_REPORT;
    }
}
