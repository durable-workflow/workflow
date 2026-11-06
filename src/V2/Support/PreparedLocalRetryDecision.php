<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** @internal Select from the one locked engine sample, never a worker clock. */
final class PreparedLocalRetryDecision
{
    public const INTERRUPTION_MESSAGE = 'Local activity attempt was interrupted before a terminal event.';

    /** Validate caller-bound retained bytes without a new clock or effects.
     * Timeout kind is retained engine authority, never a worker-selected input.
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $report
     * @param array<string, mixed> $stored
     */
    public static function matchesStored(
        array $snapshot,
        int $attemptNumber,
        array $report,
        PreparedLocalRetryWindow $window,
        array $stored,
        bool $nonRetryable = false,
    ): bool {
        return self::matchesStoredSelection(
            $window,
            $stored,
            static fn (DateTimeImmutable $sample, ?string $timeout): array => self::select(
                $snapshot,
                $attemptNumber,
                $report,
                $sample,
                $window,
                $timeout,
                $nonRetryable
            )
        );
    }

    /**
     * @internal The caller has proved original claim loss under canonical
     * locks. No worker report can select this engine-owned provenance.
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public static function selectInterruption(
        array $snapshot,
        int $attemptNumber,
        DateTimeInterface $sample,
        PreparedLocalRetryWindow $window,
        ?string $dueTimeoutKind = null,
    ): array {
        $decision = self::select(
            $snapshot,
            $attemptNumber,
            [
                'outcome' => 'failed',
                'message' => self::INTERRUPTION_MESSAGE,
            ],
            $sample,
            $window,
            $dueTimeoutKind
        );
        if ($decision['failure_source'] === 'application') {
            $decision['failure_source'] = 'engine_interruption';
        }

        return $decision;
    }

    /** @param array<string, mixed> $snapshot
     * @param array<string, mixed> $stored
     */
    public static function matchesStoredInterruption(
        array $snapshot,
        int $attemptNumber,
        PreparedLocalRetryWindow $window,
        array $stored,
    ): bool {
        return self::matchesStoredSelection(
            $window,
            $stored,
            static fn (DateTimeImmutable $sample, ?string $timeout): array => self::selectInterruption(
                $snapshot,
                $attemptNumber,
                $sample,
                $window,
                $timeout
            )
        );
    }

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
        PreparedLocalRetryWindow $window,
        ?string $dueTimeoutKind = null,
        bool $nonRetryable = false,
    ): array {
        $snapshot = PreparedLocalRetryPolicy::validateSnapshot($snapshot);
        self::validateReport($report, $attemptNumber, $dueTimeoutKind);
        $sample = self::utc($sample);
        $deadline = $window->deadline === null ? null : self::utc($window->deadline);
        self::utc($window->latest);
        if ($deadline !== null && $sample >= $deadline) {
            $dueTimeoutKind = 'schedule_to_close';
        }
        self::validateClock($sample, $window, $dueTimeoutKind, $report);
        $decision = self::commonDecision($report, $sample, $window, $dueTimeoutKind);
        $terminal = self::earlyTerminal($snapshot, $attemptNumber, $report, $dueTimeoutKind, $nonRetryable);
        if ($terminal !== null) {
            return [
                ...$decision,
                'terminal_classification' => $terminal,
            ];
        }

        return self::selectedDelay($snapshot, $attemptNumber, $report, $sample, $window, $dueTimeoutKind, $decision);
    }

    /** @param array<string, mixed> $stored
     * @param Closure(DateTimeImmutable, ?string): array<string, mixed> $select
     */
    private static function matchesStoredSelection(
        PreparedLocalRetryWindow $window,
        array $stored,
        Closure $select,
    ): bool {
        $sample = self::storedSample($stored['failure_time'] ?? null);
        $timeout = $stored['timeout_kind'] ?? null;
        if ($sample === null || ($timeout !== null && ! is_string($timeout))) {
            return false;
        }
        try {
            PreparedLocalRetryTimestamp::add($sample, 0, $window->latest, $window->earliest);
            $expected = $select($sample, $timeout);
        } catch (InvalidArgumentException) {
            return false;
        }
        return self::sameFields($expected, $stored);
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function validateReport(array $report, int $attemptNumber, ?string $dueTimeoutKind): void
    {
        $refusal = PreparedLocalActivityReport::refusal($report);
        if ($refusal !== null || $attemptNumber < 1 || $attemptNumber > PreparedLocalRetryPolicy::MAX_ATTEMPTS
            || ($dueTimeoutKind !== null && ! in_array(
                $dueTimeoutKind,
                ['schedule_to_close', 'start_to_close', 'heartbeat'],
                true
            ))) {
            throw new InvalidArgumentException($refusal ?? 'Invalid prepared retry decision input.');
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function validateClock(
        DateTimeImmutable $sample,
        PreparedLocalRetryWindow $window,
        ?string $dueTimeoutKind,
        array $report,
    ): void {
        if ($sample > $window->latest) {
            throw new InvalidArgumentException('Prepared failure sample exceeds the backend timestamp range.');
        }
        if ($dueTimeoutKind === 'schedule_to_close' && ($window->deadline === null || $sample < $window->deadline)) {
            throw new InvalidArgumentException('Overall timeout requires the original due deadline.');
        }
        if ($dueTimeoutKind === null && $report['outcome'] !== 'failed') {
            throw new InvalidArgumentException(
                'A retry decision needs an application failure or an actually due timeout.'
            );
        }
    }

    /** @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private static function commonDecision(
        array $report,
        DateTimeImmutable $sample,
        PreparedLocalRetryWindow $window,
        ?string $dueTimeoutKind,
    ): array {
        $engineTimeout = $dueTimeoutKind !== null;

        return [
            'contract_version' => 2,
            'snapshot_version' => 2,
            'decision' => 'terminal',
            'failure_time' => self::format($sample),
            'original_schedule_to_close_deadline_at' => $window->deadline === null ? null : self::format(
                $window->deadline
            ),
            'failure_source' => $engineTimeout ? 'engine_timeout' : 'application',
            ...($engineTimeout ? [
                'timeout_kind' => $dueTimeoutKind,
            ] : []),
            ...(array_key_exists('next_retry_delay_seconds', $report)
                ? [
                    'requested_delay_seconds' => $report['next_retry_delay_seconds'],
                ] : []),
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @param array<string, mixed> $report
     */
    private static function earlyTerminal(
        array $snapshot,
        int $attemptNumber,
        array $report,
        ?string $dueTimeoutKind,
        bool $nonRetryable,
    ): ?string {
        $type = $report['exception_type'] ?? RuntimeException::class;

        return match (true) {
            $dueTimeoutKind === 'schedule_to_close' => 'engine_timeout',
            $dueTimeoutKind === null && ($nonRetryable || ($report['non_retryable'] ?? false)
                || in_array($type, $snapshot['non_retryable_error_types'], true)) => 'non_retryable',
            $attemptNumber >= $snapshot['max_attempts'] => 'attempts_exhausted',
            default => null,
        };
    }

    /** @param array<string, mixed> $snapshot
     * @param array<string, mixed> $report
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    private static function selectedDelay(
        array $snapshot,
        int $attemptNumber,
        array $report,
        DateTimeImmutable $sample,
        PreparedLocalRetryWindow $window,
        ?string $dueTimeoutKind,
        array $decision,
    ): array {
        $override = $dueTimeoutKind === null && array_key_exists('next_retry_delay_seconds', $report);
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
        if ($window->deadline !== null && $candidate >= intdiv(
            self::microseconds($window->deadline) - self::microseconds($sample) + 999999,
            1000000
        )) {
            return [
                ...$decision,
                'terminal_classification' => 'retry_delay_exceeds_schedule_to_close',
            ];
        }
        // Check by division before multiplication or DateInterval construction.
        // Even the largest portable integer cannot wrap or become a float.
        if ($candidate > intdiv(self::microseconds($window->latest) - self::microseconds($sample), 1000000)) {
            throw new InvalidArgumentException('Prepared retry availability exceeds the backend timestamp range.');
        }
        $available = $sample->add(new DateInterval('PT' . $candidate . 'S'));

        return [
            ...$decision,
            'decision' => 'scheduled',
            'retry_available_at' => self::format($available),
        ];
    }

    private static function storedSample(mixed $time): ?DateTimeImmutable
    {
        if (! is_string($time) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $time) !== 1) {
            return null;
        }
        $sample = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $time, new DateTimeZone('UTC'));

        return $sample instanceof DateTimeImmutable && self::format($sample) === $time ? $sample : null;
    }

    /** @param array<string, mixed> $expected
     * @param array<string, mixed> $stored
     */
    private static function sameFields(array $expected, array $stored): bool
    {
        if (count($stored) !== count($expected) || array_diff(array_keys($stored), array_keys($expected)) !== []) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if ($stored[$key] !== $value) {
                return false;
            }
        }

        return true;
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
