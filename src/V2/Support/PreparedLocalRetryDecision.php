<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** @internal Select from the one locked engine sample, never a worker clock. */
final class PreparedLocalRetryDecision
{
    /**
     * The caller owns original authority, fresh fences and actual timeout
     * detection. Only a scheduled decision is a duration to execute. Terminal
     * candidates remain portable diagnostics and need no timestamp addition.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public static function select(
        array $snapshot,
        int $attemptNumber,
        array $report,
        DateTimeInterface $sample,
        ?DateTimeInterface $originalDeadline,
        DateTimeInterface $latestBindableTimestamp,
        ?string $dueTimeoutKind = null,
        bool $nonRetryable = false,
    ): array {
        $snapshot = PreparedLocalRetryPolicy::validateSnapshot($snapshot);
        $refusal = PreparedLocalActivityReport::refusal($report);
        if ($refusal !== null || $attemptNumber < 1 || $attemptNumber > PreparedLocalRetryPolicy::MAX_ATTEMPTS
            || ($dueTimeoutKind !== null && ! in_array(
                $dueTimeoutKind,
                ['schedule_to_close', 'start_to_close', 'heartbeat'],
                true
            ))) {
            throw new InvalidArgumentException($refusal ?? 'Invalid prepared retry decision input.');
        }
        $sample = self::utc($sample);
        $deadline = $originalDeadline === null ? null : self::utc($originalDeadline);
        $latest = self::utc($latestBindableTimestamp);
        if ($sample > $latest) {
            throw new InvalidArgumentException('Prepared failure sample exceeds the backend timestamp range.');
        }
        if ($deadline !== null && $sample >= $deadline) {
            $dueTimeoutKind = 'schedule_to_close';
        }
        if ($dueTimeoutKind === 'schedule_to_close' && ($deadline === null || $sample < $deadline)) {
            throw new InvalidArgumentException('Overall timeout requires the original due deadline.');
        }
        if ($dueTimeoutKind === null && $report['outcome'] !== 'failed') {
            throw new InvalidArgumentException(
                'A retry decision needs an application failure or an actually due timeout.'
            );
        }
        $engineTimeout = $dueTimeoutKind !== null;
        $decision = [
            'contract_version' => 2,
            'snapshot_version' => 2,
            'decision' => 'terminal',
            'failure_time' => self::format($sample),
            'original_schedule_to_close_deadline_at' => $deadline === null ? null : self::format($deadline),
            'failure_source' => $engineTimeout ? 'engine_timeout' : 'application',
            ...($engineTimeout ? [
                'timeout_kind' => $dueTimeoutKind,
            ] : []),
            ...(array_key_exists('next_retry_delay_seconds', $report)
                ? [
                    'requested_delay_seconds' => $report['next_retry_delay_seconds'],
                ] : []),
        ];
        if ($dueTimeoutKind === 'schedule_to_close') {
            return [
                ...$decision,
                'terminal_classification' => 'engine_timeout',
            ];
        }
        $type = $report['exception_type'] ?? RuntimeException::class;
        if (! $engineTimeout && ($nonRetryable || ($report['non_retryable'] ?? false)
            || in_array($type, $snapshot['non_retryable_error_types'], true))) {
            return [
                ...$decision,
                'terminal_classification' => 'non_retryable',
            ];
        }
        if ($attemptNumber >= $snapshot['max_attempts']) {
            return [
                ...$decision,
                'terminal_classification' => 'attempts_exhausted',
            ];
        }
        $override = ! $engineTimeout && array_key_exists('next_retry_delay_seconds', $report);
        $candidate = $override ? $report['next_retry_delay_seconds']
            : PreparedLocalRetryPolicy::delayForRetry($snapshot, $attemptNumber);
        $decision = [
            ...$decision,
            'candidate_delay_seconds' => $candidate,
            'maximum_interval_seconds' => $snapshot['maximum_interval_seconds'],
            'delay_source' => $override ? 'failure_override' : 'policy',
        ];
        if ($candidate > $snapshot['maximum_interval_seconds']) {
            return [
                ...$decision,
                'terminal_classification' => 'retry_delay_exceeds_policy_cap',
            ];
        }
        if ($deadline !== null && $candidate >= intdiv(
            self::microseconds($deadline) - self::microseconds($sample) + 999999,
            1000000
        )) {
            return [
                ...$decision,
                'terminal_classification' => 'retry_delay_exceeds_schedule_to_close',
            ];
        }
        // Check by division before multiplication or DateInterval construction.
        // Even the largest portable integer cannot wrap or become a float.
        if ($candidate > intdiv(self::microseconds($latest) - self::microseconds($sample), 1000000)) {
            throw new InvalidArgumentException('Prepared retry availability exceeds the backend timestamp range.');
        }
        $available = $sample->add(new DateInterval('PT' . $candidate . 'S'));

        return [
            ...$decision,
            'decision' => 'scheduled',
            'retry_available_at' => self::format($available),
        ];
    }

    private static function utc(DateTimeInterface $value): DateTimeImmutable
    {
        $utc = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        $year = $utc->format('Y');
        if (strlen($year) !== 4 || (int) $year < 1) {
            throw new InvalidArgumentException('Prepared retry timestamps require a four-digit UTC year.');
        }

        return $utc;
    }

    private static function microseconds(DateTimeImmutable $value): int
    {
        return $value->getTimestamp() * 1000000 + (int) $value->format('u');
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d\TH:i:s.u\Z');
    }
}
