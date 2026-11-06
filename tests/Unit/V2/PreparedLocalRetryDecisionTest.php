<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PreparedLocalRetryDecision;
use Workflow\V2\Support\PreparedLocalRetryPolicy;
use Workflow\V2\Support\PreparedLocalRetryWindow;

final class PreparedLocalRetryDecisionTest extends TestCase
{
    public function testEngineInterruptionUsesOnlyTheRetainedPolicyAndItsCommittedSample(): void
    {
        $sample = new DateTimeImmutable('2026-11-05T00:00:00.123456Z');
        $window = new PreparedLocalRetryWindow($sample->modify('+10 seconds'), $sample->modify('+100 seconds'));
        foreach ([0, 2] as $delay) {
            $snapshot = [
                ...$this->snapshot(),
                'backoff_seconds' => [$delay],
                'maximum_interval_seconds' => $delay,
            ];
            $decision = PreparedLocalRetryDecision::selectInterruption($snapshot, 1, $sample, $window);
            self::assertSame('engine_interruption', $decision['failure_source']);
            self::assertSame('scheduled', $decision['decision']);
            self::assertSame('policy', $decision['delay_source']);
            self::assertSame($delay, $decision['candidate_delay_seconds']);
            self::assertSame(
                $sample->modify('+' . $delay . ' seconds')->format('Y-m-d\TH:i:s.u\Z'),
                $decision['retry_available_at']
            );
            self::assertArrayNotHasKey('requested_delay_seconds', $decision);
            self::assertArrayNotHasKey('timeout_kind', $decision);
            self::assertTrue(
                PreparedLocalRetryDecision::matchesStoredInterruption($snapshot, 1, $window, array_reverse(
                    $decision,
                    true
                ))
            );
        }
    }

    public function testInterruptionKeepsNonRetryableBudgetAndOriginalDeadlinePrecedence(): void
    {
        $sample = new DateTimeImmutable('2026-11-05T00:00:00.000000Z');
        $window = new PreparedLocalRetryWindow($sample->modify('+2 seconds'), $sample->modify('+100 seconds'));
        foreach ([
            [
                'snapshot' => [
                    ...$this->snapshot(),
                    'non_retryable_error_types' => [\RuntimeException::class],
                ],
                'attempt' => 3,
                'reason' => 'non_retryable',
            ],
            [
                'snapshot' => $this->snapshot(),
                'attempt' => 3,
                'reason' => 'attempts_exhausted',
            ],
            [
                'snapshot' => $this->snapshot(),
                'attempt' => 1,
                'reason' => 'retry_delay_exceeds_schedule_to_close',
            ],
        ] as $case) {
            $decision = PreparedLocalRetryDecision::selectInterruption(
                $case['snapshot'],
                $case['attempt'],
                $sample,
                $window
            );
            self::assertSame('terminal', $decision['decision']);
            self::assertSame('engine_interruption', $decision['failure_source']);
            self::assertSame($case['reason'], $decision['terminal_classification']);
            self::assertArrayNotHasKey('retry_available_at', $decision);
            self::assertTrue(
                PreparedLocalRetryDecision::matchesStoredInterruption(
                    $case['snapshot'],
                    $case['attempt'],
                    $window,
                    $decision
                )
            );
            if ($case['reason'] !== 'retry_delay_exceeds_schedule_to_close') {
                self::assertArrayNotHasKey('candidate_delay_seconds', $decision);
                self::assertArrayNotHasKey('delay_source', $decision);
            }
        }
    }

    public function testActualDueTimeoutKeepsAuthorityAheadOfInterruption(): void
    {
        $sample = new DateTimeImmutable('2026-11-05T00:00:00.000000Z');
        $latest = $sample->modify('+100 seconds');
        $snapshot = [
            ...$this->snapshot(),
            'non_retryable_error_types' => [\RuntimeException::class],
        ];
        foreach (['start_to_close', 'heartbeat'] as $kind) {
            $window = new PreparedLocalRetryWindow($sample->modify('+10 seconds'), $latest);
            $decision = PreparedLocalRetryDecision::selectInterruption($snapshot, 1, $sample, $window, $kind);
            self::assertSame('engine_timeout', $decision['failure_source']);
            self::assertSame($kind, $decision['timeout_kind']);
            self::assertSame('scheduled', $decision['decision']);
            self::assertSame('policy', $decision['delay_source']);
            self::assertTrue(PreparedLocalRetryDecision::matchesStoredInterruption($snapshot, 1, $window, $decision));
        }
        $window = new PreparedLocalRetryWindow($sample, $latest);
        $decision = PreparedLocalRetryDecision::selectInterruption($snapshot, 3, $sample, $window);
        self::assertSame('engine_timeout', $decision['failure_source']);
        self::assertSame('schedule_to_close', $decision['timeout_kind']);
        self::assertSame('engine_timeout', $decision['terminal_classification']);
        self::assertArrayNotHasKey('candidate_delay_seconds', $decision);
    }

    public function testRetainedInterruptionCannotGainWorkerOverrideOrTimeoutAuthority(): void
    {
        $sample = new DateTimeImmutable('2026-11-05T00:00:00.000000Z');
        $window = new PreparedLocalRetryWindow(null, $sample->modify('+100 seconds'));
        $stored = PreparedLocalRetryDecision::selectInterruption($this->snapshot(), 1, $sample, $window);
        foreach ([
            [
                'failure_source' => 'application',
            ], [
                'requested_delay_seconds' => 1,
            ],
            [
                'timeout_kind' => 'heartbeat',
            ], [
                'delay_source' => 'failure_override',
            ],
            [
                'callback_stop_state' => 'stopped',
            ], [
                'candidate_delay_seconds' => 0,
            ],
        ] as $mutation) {
            self::assertFalse(
                PreparedLocalRetryDecision::matchesStoredInterruption($this->snapshot(), 1, $window, [
                    ...$stored,
                    ...$mutation,
                ])
            );
        }
        $this->expectException(InvalidArgumentException::class);
        PreparedLocalRetryDecision::selectInterruption([
            ...$this->snapshot(),
            'maximum_interval_seconds' => 1,
        ], 1, $sample, $window);
    }

    public function testOriginalWindowCopiesMutableTimestampsWithoutReadingAClock(): void
    {
        $deadline = new DateTime('2026-11-05T01:01:00.123456+01:00');
        $latest = new DateTime('9999-12-31T23:59:59.999999Z');
        $earliest = new DateTime('1970-01-01T00:00:01.000000Z');
        $window = new PreparedLocalRetryWindow($deadline, $latest, $earliest);
        $deadline->modify('+1 hour');
        $latest->modify('-1 day');
        $earliest->modify('+1 second');
        self::assertSame('2026-11-05T00:01:00.123456+00:00', $window->deadline->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('9999-12-31T23:59:59.999999+00:00', $window->latest->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('1970-01-01T00:00:01.000000+00:00', $window->earliest->format('Y-m-d\TH:i:s.uP'));
        $unbounded = new PreparedLocalRetryWindow(null, $window->latest);
        self::assertNull($unbounded->deadline);
        self::assertNull($unbounded->earliest);
    }

    public function testCanonicalApplicationSelectionProjections(): void
    {
        $vectors = json_decode(file_get_contents(dirname(__DIR__, 3)
            . '/resources/protocol/activity-retry-policy-v2-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
        $count = 0;
        foreach ($vectors['decision_cases'] as $case) {
            if (! in_array($case['expected']['decision'], ['scheduled', 'terminal'], true)
                || $case['id'] === 'zero_at_original_deadline_is_terminal') {
                continue;
            }
            // Authority/fence/retained-receipt rows are not selector tests.
            // The abstract at-deadline failure is preempted in prepared mode.
            $input = $case['input'];
            $snapshot = [
                ...$input['snapshot'],
                'start_to_close_timeout' => null,
                'schedule_to_start_timeout' => null,
                'schedule_to_close_timeout' => null,
                'heartbeat_timeout' => null,
                'non_retryable_error_types' => [],
            ];
            $failure = $input['failure'];
            $report = [
                'outcome' => 'failed',
                'message' => 'canonical failure',
                'exception_type' => $failure['type'],
                'non_retryable' => $failure['non_retryable'],
                ...(array_key_exists('next_retry_delay_seconds', $failure)
                    ? [
                        'next_retry_delay_seconds' => $failure['next_retry_delay_seconds'],
                    ] : []),
            ];
            $sample = new DateTimeImmutable('@' . $input['failure_time_seconds']);
            $deadline = $input['original_schedule_to_close_seconds'] === null ? null
                : new DateTimeImmutable('@' . $input['original_schedule_to_close_seconds']);
            $decision = PreparedLocalRetryDecision::select(
                $snapshot,
                $input['completed_attempts'],
                $report,
                $sample,
                new PreparedLocalRetryWindow($deadline, new DateTimeImmutable('9999-12-31T23:59:59.999999Z')),
            );
            self::assertSame($case['expected']['decision'], $decision['decision'], $case['id']);
            if ($decision['decision'] === 'terminal') {
                self::assertSame(
                    $case['expected']['reason'] ?? $case['expected']['terminal_classification'],
                    $decision['terminal_classification'],
                    $case['id']
                );
                self::assertArrayNotHasKey('retry_available_at', $decision);
            } else {
                self::assertSame(
                    $case['expected']['retry_available_at_seconds'],
                    (new DateTimeImmutable($decision['retry_available_at']))->getTimestamp(),
                    $case['id']
                );
            }
            if (array_key_exists('selected_delay_seconds', $case['expected'])) {
                self::assertSame(
                    $case['expected']['selected_delay_seconds'],
                    $decision['candidate_delay_seconds'],
                    $case['id']
                );
                self::assertSame(
                    $case['expected']['effective_cap_seconds'],
                    $decision['maximum_interval_seconds'],
                    $case['id']
                );
            }
            ++$count;
        }
        self::assertSame(18, $count);
    }

    public function testSelectionUsesTheSuppliedEngineSampleAndPreservesMicroseconds(): void
    {
        $sample = new DateTimeImmutable('2026-11-05T01:00:00.123456+01:00');
        $decision = $this->select(sample: $sample);
        self::assertSame([
            'contract_version' => 2,
            'snapshot_version' => 2,
            'decision' => 'scheduled',
            'failure_time' => '2026-11-05T00:00:00.123456Z',
            'original_schedule_to_close_deadline_at' => null,
            'failure_source' => 'application',
            'candidate_delay_seconds' => 2,
            'maximum_interval_seconds' => 5,
            'delay_source' => 'policy',
            'retry_available_at' => '2026-11-05T00:00:02.123456Z',
        ], $decision);
        self::assertSame('2026-11-05T01:00:00.123456+01:00', $sample->format('Y-m-d\TH:i:s.uP'));
        self::assertSame($decision, $this->select(sample: $sample));
    }

    public function testZeroOverrideIsSelectedAndAnOverCapOverrideRemainsTerminalDiagnosticData(): void
    {
        $zero = $this->select(report: [
            'outcome' => 'failed',
            'message' => 'failure',
            'next_retry_delay_seconds' => 0,
        ]);
        self::assertSame(0, $zero['requested_delay_seconds']);
        self::assertSame(0, $zero['candidate_delay_seconds']);
        self::assertSame($zero['failure_time'], $zero['retry_available_at']);
        self::assertSame('failure_override', $zero['delay_source']);

        $large = $this->select(report: [
            'outcome' => 'failed',
            'message' => 'failure',
            'next_retry_delay_seconds' => 9007199254740991,
        ]);
        self::assertSame('retry_delay_exceeds_policy_cap', $large['terminal_classification']);
        self::assertSame(9007199254740991, $large['candidate_delay_seconds']);
        self::assertArrayNotHasKey('retry_available_at', $large);
    }

    public function testDeadlineComparisonDoesNotFloorTheOriginalMicroseconds(): void
    {
        $sample = new DateTimeImmutable('2026-11-05T00:00:00.000000Z');
        $report = [
            'outcome' => 'failed',
            'message' => 'original failure',
            'next_retry_delay_seconds' => 1,
        ];
        foreach (['00:00:00.999999', '00:00:01.000000'] as $time) {
            $decision = $this->select(
                report: $report,
                sample: $sample,
                deadline: new DateTimeImmutable('2026-11-05T' . $time . 'Z')
            );
            self::assertSame('retry_delay_exceeds_schedule_to_close', $decision['terminal_classification']);
            self::assertSame('application', $decision['failure_source']);
            self::assertArrayNotHasKey('retry_available_at', $decision);
        }
        $scheduled = $this->select(
            report: $report,
            sample: $sample,
            deadline: new DateTimeImmutable('2026-11-05T00:00:01.000001Z')
        );
        self::assertSame('scheduled', $scheduled['decision']);
        self::assertSame('2026-11-05T00:00:01.000000Z', $scheduled['retry_available_at']);
    }

    public function testActuallyDueOverallTimeoutPreemptsApplicationInputAndCompletedOutcome(): void
    {
        $sample = new DateTimeImmutable('2026-11-05T00:00:01.000000Z');
        $deadline = new DateTimeImmutable('2026-11-05T00:00:01.000000Z');
        foreach ([[
            'outcome' => 'failed',
            'message' => 'failure',
            'non_retryable' => true,
            'next_retry_delay_seconds' => 0,
        ],
            [
                'outcome' => 'completed',
                'result' => 'opaque-avro',
                'payload_codec' => 'avro',
            ],
            [
                'outcome' => 'timed_out',
            ]] as $report) {
            $decision = $this->select(report: $report, sample: $sample, deadline: $deadline);
            self::assertSame('engine_timeout', $decision['failure_source']);
            self::assertSame('engine_timeout', $decision['terminal_classification']);
            self::assertSame('schedule_to_close', $decision['timeout_kind']);
            foreach ([
                'candidate_delay_seconds',
                'maximum_interval_seconds',
                'delay_source',
                'retry_available_at',
            ] as $field) {
                self::assertArrayNotHasKey($field, $decision);
            }
        }
        self::assertSame(0, $this->select(
            report: [
                'outcome' => 'failed',
                'message' => '',
                'next_retry_delay_seconds' => 0,
            ],
            sample: $sample,
            deadline: $deadline
        )['requested_delay_seconds']);
    }

    public function testNonRetryableAndExhaustedBranchesDoNotSelectADelay(): void
    {
        foreach ([
            $this->select(report: [
                'outcome' => 'failed',
                'message' => '',
                'non_retryable' => true,
            ]),
            $this->select(report: [
                'outcome' => 'failed',
                'message' => '',
                'exception_type' => 'PermanentFailure',
            ]),
            $this->select(nonRetryable: true),
            $this->select(attempt: 3),
        ] as $index => $decision) {
            self::assertSame(
                $index === 3 ? 'attempts_exhausted' : 'non_retryable',
                $decision['terminal_classification']
            );
            foreach ([
                'candidate_delay_seconds',
                'maximum_interval_seconds',
                'delay_source',
                'retry_available_at',
            ] as $field) {
                self::assertArrayNotHasKey($field, $decision);
            }
        }
    }

    public function testHeartbeatAndStartTimeoutsUsePolicyUntilTheTotalDeadline(): void
    {
        foreach (['heartbeat', 'start_to_close'] as $kind) {
            $decision = $this->select(report: [
                'outcome' => 'failed',
                'message' => '',
                'non_retryable' => true,
                'next_retry_delay_seconds' => 4,
            ], timeout: $kind);
            self::assertSame('scheduled', $decision['decision']);
            self::assertSame('engine_timeout', $decision['failure_source']);
            self::assertSame($kind, $decision['timeout_kind']);
            self::assertSame('policy', $decision['delay_source']);
            self::assertSame(2, $decision['candidate_delay_seconds']);
            self::assertSame(4, $decision['requested_delay_seconds']);
            self::assertSame(
                'attempts_exhausted',
                $this->select(attempt: 3, timeout: $kind)['terminal_classification']
            );
            self::assertSame('scheduled', $this->select(report: [
                'outcome' => 'timed_out',
            ], timeout: $kind)['decision']);
        }
    }

    public function testOnlyExecutableAvailabilityMustFitTheProvidedStorageBoundary(): void
    {
        $sample = new DateTimeImmutable('9999-12-31T23:59:58.999999Z');
        $latest = new DateTimeImmutable('9999-12-31T23:59:59.999999Z');
        $report = [
            'outcome' => 'failed',
            'message' => '',
            'next_retry_delay_seconds' => 1,
        ];
        self::assertSame(
            '9999-12-31T23:59:59.999999Z',
            $this->select(report: $report, sample: $sample, latest: $latest)['retry_available_at']
        );
        $huge = $this->select(
            report: array_replace($report, [
                'next_retry_delay_seconds' => 9007199254740991,
            ]),
            sample: $sample,
            latest: $latest
        );
        self::assertSame('retry_delay_exceeds_policy_cap', $huge['terminal_classification']);
        $this->expectException(InvalidArgumentException::class);
        $this->select(sample: $sample, latest: $latest);
    }

    public function testInvalidInputOrUnprovenTimeoutCannotCreateADecision(): void
    {
        foreach ([[
            'attempt' => 0,
        ], [
            'attempt' => 1025,
        ], [
            'report' => [
                'outcome' => 'timed_out',
            ],
        ],
            [
                'report' => [
                    'outcome' => 'completed',
                    'result' => 'opaque-avro',
                    'payload_codec' => 'avro',
                ],
            ],
            [
                'report' => [
                    'outcome' => 'failed',
                    'message' => '',
                    'next_retry_delay_seconds' => null,
                ],
            ],
            [
                'timeout' => 'worker_selected',
            ], [
                'timeout' => 'schedule_to_close',
            ],
            [
                'timeout' => 'schedule_to_close',
                'deadline' => new DateTimeImmutable('2030-01-01T00:00:00Z'),
            ],
            [
                'sample' => new DateTimeImmutable('2026-11-06T00:00:00Z'),
                'latest' => new DateTimeImmutable('2026-11-05T00:00:00Z'),
            ],
            [
                'sample' => new DateTimeImmutable('0000-01-01T00:00:00Z'),
            ],
        ] as $index => $changes) {
            try {
                $this->select(...$changes);
                self::fail('Admitted invalid decision input ' . $index);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRetainedDecisionRequiresTheExactTypedClosedSelection(): void
    {
        $report = [
            'outcome' => 'failed',
            'message' => 'failure',
            'next_retry_delay_seconds' => 0,
        ];
        $deadline = new DateTimeImmutable('2026-11-05T00:01:00.000000Z');
        $latest = new DateTimeImmutable('9999-12-31T23:59:59.999999Z');
        $stored = $this->select(report: $report, deadline: $deadline);
        $matches = fn (array $value): bool => PreparedLocalRetryDecision::matchesStored(
            $this->snapshot(),
            1,
            $report,
            new PreparedLocalRetryWindow($deadline, $latest),
            $value,
        );
        self::assertTrue($matches(array_reverse($stored, true)));
        $missing = $stored;
        unset($missing['candidate_delay_seconds']);
        foreach ([
            $missing,
            [
                ...$stored,
                'unexpected' => true,
            ],
            [
                ...$stored,
                'contract_version' => '2',
            ],
            [
                ...$stored,
                'snapshot_version' => 1,
            ],
            [
                ...$stored,
                'candidate_delay_seconds' => '0',
            ],
            [
                ...$stored,
                'requested_delay_seconds' => null,
            ],
            [
                ...$stored,
                'maximum_interval_seconds' => 6,
            ],
            [
                ...$stored,
                'retry_available_at' => '2026-11-05T00:00:01.000000Z',
            ],
            [
                ...$stored,
                'original_schedule_to_close_deadline_at' => null,
            ],
            [
                ...$stored,
                'failure_time' => [],
            ],
            [
                ...$stored,
                'failure_time' => '2026-02-30T00:00:00.000000Z',
            ],
            [
                ...$stored,
                'failure_time' => '2026-11-05T24:00:00.000000Z',
            ],
            [
                ...$stored,
                'failure_time' => '2026-11-05T00:00:00Z',
            ],
            [
                ...$stored,
                'failure_time' => '٢٠٢٦-11-05T00:00:00.000000Z',
            ],
            [
                ...$stored,
                'timeout_kind' => [],
            ],
            [
                ...$stored,
                'timeout_kind' => 'worker_selected',
            ],
        ] as $index => $changed) {
            self::assertFalse($matches($changed), 'Retained selection substitution ' . $index);
        }
        self::assertFalse(PreparedLocalRetryDecision::matchesStored(
            $this->snapshot(),
            1,
            [
                ...$report,
                'next_retry_delay_seconds' => 1,
            ],
            new PreparedLocalRetryWindow($deadline, $latest),
            $stored,
        ));
    }

    public function testRetainedTerminalDecisionsCannotGainAnExecutableTimestampOrUnselectedDelay(): void
    {
        $latest = new DateTimeImmutable('9999-12-31T23:59:59.999999Z');
        $report = [
            'outcome' => 'failed',
            'message' => 'failure',
            'next_retry_delay_seconds' => 6,
        ];
        $stored = $this->select(report: $report);
        self::assertTrue(PreparedLocalRetryDecision::matchesStored(
            $this->snapshot(),
            1,
            $report,
            new PreparedLocalRetryWindow(null, $latest),
            $stored,
        ));
        self::assertFalse(PreparedLocalRetryDecision::matchesStored(
            $this->snapshot(),
            1,
            $report,
            new PreparedLocalRetryWindow(null, $latest),
            [
                ...$stored,
                'retry_available_at' => '2026-11-05T00:00:06.000000Z',
            ],
        ));
        $report = [
            'outcome' => 'timed_out',
        ];
        $deadline = new DateTimeImmutable('2026-11-05T00:00:00.000000Z');
        $stored = $this->select(report: $report, deadline: $deadline);
        self::assertTrue(PreparedLocalRetryDecision::matchesStored(
            $this->snapshot(),
            1,
            $report,
            new PreparedLocalRetryWindow($deadline, $latest),
            $stored,
        ));
        self::assertFalse(PreparedLocalRetryDecision::matchesStored(
            $this->snapshot(),
            1,
            $report,
            new PreparedLocalRetryWindow($deadline, $latest),
            [
                ...$stored,
                'delay_source' => 'policy',
            ],
        ));
        self::assertFalse(PreparedLocalRetryDecision::matchesStored(
            $this->snapshot(),
            1,
            $report,
            new PreparedLocalRetryWindow($deadline->modify('+1 second'), $latest),
            $stored,
        ));
    }

    public function testRetainedDecisionKeepsBackendBoundsAndEngineNonRetryableClassification(): void
    {
        $report = [
            'outcome' => 'failed',
            'message' => 'failure',
        ];
        $sample = new DateTimeImmutable('2026-11-05T00:00:00.000000Z');
        $latest = new DateTimeImmutable('9999-12-31T23:59:59.999999Z');
        $stored = $this->select(report: $report, nonRetryable: true);
        self::assertTrue(PreparedLocalRetryDecision::matchesStored(
            $this->snapshot(),
            1,
            $report,
            new PreparedLocalRetryWindow(null, $latest),
            $stored,
            true,
        ));
        self::assertFalse(PreparedLocalRetryDecision::matchesStored(
            $this->snapshot(),
            1,
            $report,
            new PreparedLocalRetryWindow(null, $latest),
            $stored,
        ));
        self::assertFalse(PreparedLocalRetryDecision::matchesStored(
            $this->snapshot(),
            1,
            $report,
            new PreparedLocalRetryWindow(null, $sample->modify('-1 microsecond')),
            $stored,
            true,
        ));
        self::assertFalse(PreparedLocalRetryDecision::matchesStored(
            $this->snapshot(),
            1,
            $report,
            new PreparedLocalRetryWindow(null, $latest, $sample->modify('+1 microsecond')),
            $stored,
            true,
        ));
        $snapshot = $this->snapshot();
        $snapshot['snapshot_version'] = 1;
        self::assertFalse(PreparedLocalRetryDecision::matchesStored(
            $snapshot,
            1,
            $report,
            new PreparedLocalRetryWindow(null, $latest),
            $stored,
            true,
        ));
    }

    private function snapshot(): array
    {
        return PreparedLocalRetryPolicy::normalize([
            'contract_version' => 2,
            'mode' => 'explicit',
            'max_attempts' => 3,
            'backoff_seconds' => [2, 5],
            'non_retryable_error_types' => ['PermanentFailure'],
        ]);
    }

    private function select(
        array $report = [
            'outcome' => 'failed',
            'message' => 'failure',
        ],
        int $attempt = 1,
        ?DateTimeImmutable $sample = null,
        ?DateTimeImmutable $deadline = null,
        ?DateTimeImmutable $latest = null,
        ?string $timeout = null,
        bool $nonRetryable = false,
    ): array {
        $snapshot = $this->snapshot();

        return PreparedLocalRetryDecision::select(
            $snapshot,
            $attempt,
            $report,
            $sample ?? new DateTimeImmutable('2026-11-05T00:00:00.000000Z'),
            new PreparedLocalRetryWindow($deadline, $latest ?? new DateTimeImmutable('9999-12-31T23:59:59.999999Z')),
            $timeout,
            $nonRetryable,
        );
    }
}
