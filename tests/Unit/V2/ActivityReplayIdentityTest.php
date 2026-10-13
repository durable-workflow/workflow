<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Exceptions\ActivityRetryPolicyMismatchException;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\ActivityReplayIdentity;
use Workflow\V2\Support\ActivityRetryPolicy;

final class ActivityReplayIdentityTest extends TestCase
{
    public function testFullUnusedListMustMatchWithoutChangingTheSnapshot(): void
    {
        $original = new ActivityOptions(maxAttempts: 1, backoff: [1, 2, 5, 10, 15, 30, 60, 120]);
        $snapshot = ActivityRetryPolicy::snapshotExternal(null, $original);
        self::assertNotNull($snapshot);
        ActivityReplayIdentity::assertPolicy($snapshot, $original, 2);
        $before = $snapshot;

        try {
            ActivityReplayIdentity::assertPolicy(
                $snapshot,
                new ActivityOptions(maxAttempts: 1, backoff: [1, 2, 5, 10, 15, 30, 60, 121]),
                2,
            );
            self::fail('The full explicit backoff list must match even for one attempt.');
        } catch (ActivityRetryPolicyMismatchException $exception) {
            self::assertSame('backoff_seconds', $exception->policyField);
            self::assertSame(2, $exception->workflowSequence);
            self::assertSame($before, $snapshot);
        }
    }

    public function testAbsentLegacyFieldsAndClassDefaultsAreNotInvented(): void
    {
        ActivityReplayIdentity::assertPolicy([], new ActivityOptions(maxAttempts: 8, backoff: [9]), 1);
        ActivityReplayIdentity::assertPolicy([
            'max_attempts' => 1,
            'backoff_seconds' => [120],
        ], null, 1);
        ActivityReplayIdentity::assertPolicy([
            'max_attempts' => null,
            'backoff_seconds' => [120],
        ], new ActivityOptions(queue: 'changed-routing'), 1);
        self::assertTrue(true);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    #[DataProvider('changedFields')]
    public function testKnownFieldsRejectChangedOverrides(
        array $snapshot,
        ActivityOptions $options,
        string $field
    ): void {
        try {
            ActivityReplayIdentity::assertPolicy($snapshot, $options, 4);
            self::fail('Changed explicit field must be refused.');
        } catch (ActivityRetryPolicyMismatchException $exception) {
            self::assertSame($field, $exception->policyField);
        }
    }

    public static function changedFields(): iterable
    {
        yield 'attempts' => [[
            'max_attempts' => 1,
        ], new ActivityOptions(maxAttempts: 2), 'max_attempts'];
        yield 'empty backoff' => [[
            'backoff_seconds' => [120],
        ], new ActivityOptions(backoff: []), 'backoff_seconds'];
        yield 'start to close' => [[
            'start_to_close_timeout' => 120,
        ], new ActivityOptions(startToCloseTimeout: 121), 'start_to_close_timeout'];
        yield 'schedule to start' => [[
            'schedule_to_start_timeout' => 120,
        ], new ActivityOptions(scheduleToStartTimeout: 121), 'schedule_to_start_timeout'];
        yield 'schedule to close' => [[
            'schedule_to_close_timeout' => 600,
        ], new ActivityOptions(scheduleToCloseTimeout: 601), 'schedule_to_close_timeout'];
        yield 'heartbeat' => [[
            'heartbeat_timeout' => 30,
        ], new ActivityOptions(heartbeatTimeout: 31), 'heartbeat_timeout'];
        yield 'failure types' => [[
            'non_retryable_error_types' => ['Old'],
        ], new ActivityOptions(nonRetryableErrorTypes: ['New']), 'non_retryable_error_types'];
        yield 'version' => [[
            'snapshot_version' => 2,
        ], new ActivityOptions(), 'snapshot_version'];
        yield 'string attempt' => [[
            'max_attempts' => '1',
        ], new ActivityOptions(), 'max_attempts'];
        yield 'negative list' => [[
            'backoff_seconds' => [-1],
        ], new ActivityOptions(), 'backoff_seconds'];
        yield 'non-list' => [[
            'backoff_seconds' => [
                1 => 120,
            ],
        ], new ActivityOptions(), 'backoff_seconds'];
        yield 'string timeout' => [[
            'heartbeat_timeout' => '30',
        ], new ActivityOptions(), 'heartbeat_timeout'];
        yield 'malformed types' => [[
            'non_retryable_error_types' => [''],
        ], new ActivityOptions(), 'non_retryable_error_types'];
    }

    public function testSchedulingNormalizationIsPreservedWithoutPrefixComparison(): void
    {
        ActivityReplayIdentity::assertPolicy(
            [
                'backoff_seconds' => [2],
                'non_retryable_error_types' => ['Timeout'],
            ],
            new ActivityOptions(backoff: 2, nonRetryableErrorTypes: [' Timeout ', 'Timeout']),
            1,
        );
        self::assertTrue(true);
    }
}
