<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PreparedLocalRetryPolicy;

final class PreparedLocalRetryPolicyTest extends TestCase
{
    public function testCanonicalVersionTwoNormalizationProjections(): void
    {
        $vectors = json_decode(file_get_contents(dirname(__DIR__, 3)
            . '/resources/protocol/activity-retry-policy-v2-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
        $consumed = [];
        foreach ($vectors['normalization_cases'] as $case) {
            if (($case['input']['contract_version'] ?? null) !== 2) {
                continue;
            }
            $policy = $case['input'];
            if (isset($policy['generator'])) {
                // These fixtures describe authoring expansion. The prepared
                // wire accepts only its flattened result, never the generator.
                unset($policy['generator']);
                $policy['backoff_seconds'] = $case['expected']['snapshot']['backoff_seconds'];
            }
            $consumed[] = $case['id'];
            if ($case['expected']['result'] !== 'accepted') {
                $this->assertRefused($policy, $case['id']);
                continue;
            }
            $snapshot = PreparedLocalRetryPolicy::normalize($policy);
            foreach ($case['expected']['snapshot'] as $field => $value) {
                self::assertSame($value, $snapshot[$field], $case['id'] . ':' . $field);
            }
            self::assertCount(10, $snapshot);
            self::assertSame($snapshot, PreparedLocalRetryPolicy::validateSnapshot($snapshot));
        }
        self::assertCount(10, $consumed);
    }

    public function testDefaultsAndTimeoutsRemainExplicitInTheRetainedSnapshot(): void
    {
        self::assertSame([
            'snapshot_version' => 2,
            'mode' => 'explicit',
            'max_attempts' => 1,
            'backoff_seconds' => [],
            'maximum_interval_seconds' => 0,
            'start_to_close_timeout' => null,
            'schedule_to_start_timeout' => null,
            'schedule_to_close_timeout' => null,
            'heartbeat_timeout' => null,
            'non_retryable_error_types' => [],
        ], PreparedLocalRetryPolicy::normalize($this->policy()));

        $timeouts = [
            'start_to_close_timeout' => 1,
            'schedule_to_start_timeout' => null,
            'schedule_to_close_timeout' => 2147483647,
            'heartbeat_timeout' => 30,
        ];
        $snapshot = PreparedLocalRetryPolicy::normalize($this->policy(), $timeouts);
        foreach ($timeouts as $field => $value) {
            self::assertSame($value, $snapshot[$field]);
        }
        self::assertSame($snapshot, PreparedLocalRetryPolicy::validateSnapshot($snapshot));
    }

    public function testFlattenedSchedulesRetainUnusedTailsAndPortableIntegerBoundaries(): void
    {
        $backoff = array_fill(0, 1024, 0);
        $backoff[1023] = 9007199254740991;
        $snapshot = PreparedLocalRetryPolicy::normalize($this->policy([
            'max_attempts' => 2,
            'backoff_seconds' => $backoff,
        ]));
        self::assertSame($backoff, $snapshot['backoff_seconds']);
        self::assertSame(9007199254740991, $snapshot['maximum_interval_seconds']);
        self::assertSame(0, PreparedLocalRetryPolicy::delayForRetry($snapshot, 1));

        $snapshot = PreparedLocalRetryPolicy::normalize($this->policy([
            'max_attempts' => 1024,
            'backoff_seconds' => [9007199254740991],
        ]));
        self::assertSame(9007199254740991, PreparedLocalRetryPolicy::delayForRetry($snapshot, 1023));
    }

    public function testRetryDelayRepeatsTheLastEntryOnlyWhileBudgetRemains(): void
    {
        $snapshot = PreparedLocalRetryPolicy::normalize($this->policy([
            'max_attempts' => 5,
            'backoff_seconds' => [10, 2],
        ]));
        self::assertSame(10, PreparedLocalRetryPolicy::delayForRetry($snapshot, 1));
        self::assertSame(2, PreparedLocalRetryPolicy::delayForRetry($snapshot, 2));
        self::assertSame(2, PreparedLocalRetryPolicy::delayForRetry($snapshot, 4));
        $empty = PreparedLocalRetryPolicy::normalize($this->policy([
            'max_attempts' => 2,
        ]));
        self::assertSame(0, PreparedLocalRetryPolicy::delayForRetry($empty, 1));
        foreach ([0, -1, 5, 6] as $attempt) {
            try {
                PreparedLocalRetryPolicy::delayForRetry($snapshot, $attempt);
                self::fail('Selected a delay without retry budget: ' . $attempt);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        PreparedLocalRetryPolicy::delayForRetry(PreparedLocalRetryPolicy::normalize($this->policy()), 1);
    }

    public function testWirePolicyRefusesCoercionUnknownFieldsAndInvalidCaps(): void
    {
        foreach (['contract_version', 'mode', 'backoff_seconds'] as $field) {
            $policy = $this->policy();
            unset($policy[$field]);
            $this->assertRefused($policy, 'missing ' . $field);
        }
        $invalid = [
            [
                'contract_version' => 1,
            ], [
                'contract_version' => '2',
            ], [
                'contract_version' => true,
            ],
            [
                'mode' => null,
            ], [
                'mode' => 'legacy',
            ], [
                'snapshot_version' => 2,
            ], [
                'generator' => [],
            ],
            [
                'max_attempts' => null,
            ], [
                'max_attempts' => true,
            ], [
                'max_attempts' => '2',
            ],
            [
                'max_attempts' => 2.0,
            ], [
                'max_attempts' => 0,
            ], [
                'max_attempts' => 1025,
            ],
            [
                'backoff_seconds' => null,
            ], [
                'backoff_seconds' => [
                    'delay' => 1,
                ],
            ],
            [
                'backoff_seconds' => array_fill(0, 1025, 0),
            ],
            [
                'maximum_interval_seconds' => null,
            ], [
                'maximum_interval_seconds' => false,
            ],
            [
                'maximum_interval_seconds' => '1',
            ], [
                'maximum_interval_seconds' => 1.0,
            ],
            [
                'maximum_interval_seconds' => -1,
            ], [
                'maximum_interval_seconds' => 9007199254740992,
            ],
            [
                'backoff_seconds' => [2],
                'maximum_interval_seconds' => 1,
            ],
            [
                'mode' => 'generated',
                'backoff_seconds' => [61],
            ],
        ];
        foreach ([null, false, '1', 1.0, -1, 9007199254740992] as $delay) {
            $invalid[] = [
                'backoff_seconds' => [$delay],
            ];
        }
        foreach ($invalid as $index => $changes) {
            $this->assertRefused($this->policy($changes), 'invalid policy ' . $index);
        }
    }

    public function testTimeoutBoundsAndQueueBypassAreStrict(): void
    {
        foreach (['start_to_close_timeout', 'schedule_to_close_timeout', 'heartbeat_timeout'] as $field) {
            foreach ([0, -1, 2147483648, true, '1', 1.0] as $value) {
                $this->assertRefused($this->policy(), $field, [
                    $field => $value,
                ]);
            }
        }
        foreach ([0, 1, false, '1'] as $value) {
            $this->assertRefused($this->policy(), 'queue bypass', [
                'schedule_to_start_timeout' => $value,
            ]);
        }
        $this->assertRefused($this->policy(), 'unknown timeout', [
            'worker_deadline' => 1,
        ]);
    }

    public function testErrorTypesAreBoundedBeforeTrimmingAndDeduplication(): void
    {
        $snapshot = PreparedLocalRetryPolicy::normalize($this->policy([
            'non_retryable_error_types' => [' Failure ', 'Other', 'Failure', 'Other'],
        ]));
        self::assertSame(['Failure', 'Other'], $snapshot['non_retryable_error_types']);
        self::assertSame([str_repeat('a', 255)], PreparedLocalRetryPolicy::normalize($this->policy([
            'non_retryable_error_types' => array_fill(0, 128, str_repeat('a', 255)),
        ]))['non_retryable_error_types']);
        self::assertSame([str_repeat('é', 127)], PreparedLocalRetryPolicy::normalize($this->policy([
            'non_retryable_error_types' => [str_repeat('é', 127)],
        ]))['non_retryable_error_types']);
        foreach ([null, [
            'type' => 'Failure',
        ], array_fill(0, 129, 'Failure'), [null], [true], [1],
            [''], [" \t "], ["\xff"], [str_repeat('é', 128)], [' ' . str_repeat('a', 255)]] as $types) {
            $this->assertRefused($this->policy([
                'non_retryable_error_types' => $types,
            ]), 'error types');
        }
    }

    public function testRetainedSnapshotsAreValidatedWithoutRepairOrDowngrade(): void
    {
        $snapshot = PreparedLocalRetryPolicy::normalize($this->policy([
            'non_retryable_error_types' => ['Failure'],
        ]));
        $invalid = [];
        foreach (array_keys($snapshot) as $field) {
            $missing = $snapshot;
            unset($missing[$field]);
            $invalid[] = $missing;
        }
        foreach ([[
            'snapshot_version' => 1,
        ], [
            'snapshot_version' => 3,
        ], [
            'snapshot_version' => '2',
        ],
            [
                'extra' => false,
            ], [
                'non_retryable_error_types' => [' Failure '],
            ],
            [
                'non_retryable_error_types' => ['Failure', 'Failure'],
            ], [
                'max_attempts' => null,
            ]] as $change) {
            $invalid[] = array_replace($snapshot, $change);
        }
        foreach ($invalid as $index => $value) {
            try {
                PreparedLocalRetryPolicy::validateSnapshot($value);
                self::fail('Repaired invalid stored snapshot ' . $index);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function policy(array $changes = []): array
    {
        return array_replace([
            'contract_version' => 2,
            'mode' => 'explicit',
            'backoff_seconds' => [],
        ], $changes);
    }

    private function assertRefused(array $policy, string $label, array $timeouts = []): void
    {
        try {
            PreparedLocalRetryPolicy::normalize($policy, $timeouts);
            self::fail('Accepted invalid ' . $label);
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}
