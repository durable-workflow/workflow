<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Activity;
use Workflow\V2\Exceptions\RestoredWorkflowException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\ActivityRetryPolicy;

final class ActivityRetryPolicyTest extends TestCase
{
    public function testActivitySnapshotPreservesUnlimitedRetriesAndNormalizesOverrides(): void
    {
        $activity = $this->activity(0, [-2, 3]);

        self::assertSame([
            'snapshot_version' => 1,
            'max_attempts' => null,
            'backoff_seconds' => [0, 3],
            'start_to_close_timeout' => null,
            'schedule_to_start_timeout' => null,
            'schedule_to_close_timeout' => null,
            'heartbeat_timeout' => null,
            'non_retryable_error_types' => [],
        ], ActivityRetryPolicy::snapshot($activity));

        $snapshot = ActivityRetryPolicy::snapshot($activity, new ActivityOptions(
            maxAttempts: 4,
            backoff: 7,
            heartbeatTimeout: 12,
            nonRetryableErrorTypes: [' Timeout ', '', 'Timeout'],
        ));

        self::assertSame(4, $snapshot['max_attempts']);
        self::assertSame([7], $snapshot['backoff_seconds']);
        self::assertSame(12, $snapshot['heartbeat_timeout']);
        self::assertSame(['Timeout'], $snapshot['non_retryable_error_types']);
    }

    public function testExternalSnapshotDefaultsToOneAttemptAndNormalizesWorkerPolicy(): void
    {
        self::assertNull(ActivityRetryPolicy::snapshotExternal(null));

        $default = ActivityRetryPolicy::snapshotExternal([], new ActivityOptions(startToCloseTimeout: 30));
        self::assertSame(1, $default['max_attempts']);
        self::assertSame(30, $default['start_to_close_timeout']);

        $snapshot = ActivityRetryPolicy::snapshotExternal([
            'max_attempts' => 0,
            'backoff_seconds' => [-4, '3', 'invalid', 5],
            'non_retryable_error_types' => [' RemoteFailure ', '', 'RemoteFailure', null],
        ]);

        self::assertSame(1, $snapshot['max_attempts']);
        self::assertSame([0, 3, 5], $snapshot['backoff_seconds']);
        self::assertSame(['RemoteFailure'], $snapshot['non_retryable_error_types']);
    }

    public function testPersistedPolicyWinsOverActivityFallbackAndEmptyBackoffDisablesIt(): void
    {
        $activity = $this->activity(3, [2, 5]);
        $execution = new ActivityExecution();
        $execution->retry_policy = [];

        self::assertSame(3, ActivityRetryPolicy::maxAttempts($execution, $activity));
        self::assertSame(1, ActivityRetryPolicy::maxAttemptsFromSnapshot($execution));
        self::assertSame(5, ActivityRetryPolicy::backoffSeconds($execution, $activity, 2));
        self::assertSame(0, ActivityRetryPolicy::backoffSecondsFromSnapshot($execution, 2));

        $execution->retry_policy = ['max_attempts' => null, 'backoff_seconds' => [1, 4]];
        self::assertSame(PHP_INT_MAX, ActivityRetryPolicy::maxAttempts($execution, $activity));
        self::assertSame(PHP_INT_MAX, ActivityRetryPolicy::maxAttemptsFromSnapshot($execution));
        self::assertSame(1, ActivityRetryPolicy::backoffSeconds($execution, $activity, 0));
        self::assertSame(4, ActivityRetryPolicy::backoffSecondsFromSnapshot($execution, 99));

        $execution->retry_policy = ['max_attempts' => 0, 'backoff_seconds' => []];
        self::assertSame(1, ActivityRetryPolicy::maxAttempts($execution, $activity));
        self::assertSame(1, ActivityRetryPolicy::maxAttemptsFromSnapshot($execution));
        self::assertSame(0, ActivityRetryPolicy::backoffSeconds($execution, $activity, 2));
    }

    public function testNonRetryableClassificationUsesRecordedClassAndPortableType(): void
    {
        $execution = new ActivityExecution();
        $execution->retry_policy = ['non_retryable_error_types' => [RuntimeException::class, 'RemoteFailure']];

        self::assertTrue(ActivityRetryPolicy::isNonRetryableFailure($execution, new RuntimeException('invalid')));
        self::assertTrue(ActivityRetryPolicy::isNonRetryableFailure($execution, new RestoredWorkflowException([
            'class' => 'OtherFailure',
            'type' => 'RemoteFailure',
            'message' => 'invalid',
        ])));
        self::assertFalse(ActivityRetryPolicy::isNonRetryableFailure($execution, new \LogicException('retry')));

        $execution->retry_policy = [];
        self::assertFalse(ActivityRetryPolicy::isNonRetryableFailure($execution, new RuntimeException('retry')));
        self::assertTrue(ActivityRetryPolicy::isNonRetryableFailure($execution, new RestoredWorkflowException([
            'class' => 'FatalFailure',
            'message' => 'stop',
            'non_retryable' => true,
        ])));
    }

    private function activity(int $tries, array $backoff): Activity
    {
        return new class($tries, $backoff) extends Activity
        {
            public function __construct(int $tries, private readonly array $delays)
            {
                $this->tries = $tries;
            }

            public function backoff(): int|array
            {
                return $this->delays;
            }
        };
    }
}
