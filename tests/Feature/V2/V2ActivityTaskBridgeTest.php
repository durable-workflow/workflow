<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\ActivityRecovery;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\Support\DefaultActivityTaskBridge;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;
use Workflow\V2\Support\RunActivityView;
use Workflow\V2\Support\RunTimelineProjector;
use Workflow\V2\Support\TaskRepair;

final class V2ActivityTaskBridgeTest extends TestCase
{
    private ActivityTaskBridge $bridge;

    private ?string $storageRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->bridge = $this->app->make(ActivityTaskBridge::class);
    }

    protected function tearDown(): void
    {
        ExternalPayloadStorage::flushVerifiedCache();

        if ($this->storageRoot !== null) {
            $this->removeDirectory($this->storageRoot);
            $this->storageRoot = null;
        }

        parent::tearDown();
    }

    public function testBridgeIsResolvableFromContainer(): void
    {
        $bridge = $this->app->make(ActivityTaskBridge::class);

        $this->assertInstanceOf(DefaultActivityTaskBridge::class, $bridge);
    }

    public function testActivityClaimUsesTheConfiguredLeaseDuration(): void
    {
        config([
            'workflows.v2.activity_task_lease_seconds' => 19,
        ]);
        Date::setTestNow('2026-10-20T19:00:00.123456Z');
        try {
            [, , $task] = $this->createActivityTask();
            $claim = $this->bridge->claimStatus($task->id, 'lease-original');

            $this->assertTrue($claim['claimed']);
            $this->assertSame(now()->addSeconds(19)->toJSON(), $claim['lease_expires_at']);
        } finally {
            Date::setTestNow();
        }
    }

    public function testRuntimeLeaseRenewalDoesNotInventApplicationHeartbeatProgress(): void
    {
        config([
            'workflows.v2.activity_task_lease_seconds' => 19,
        ]);
        Date::setTestNow('2026-10-20T19:00:00.123456Z');
        try {
            [$run, $execution, $task] = $this->createActivityTask();
            $execution->forceFill([
                'retry_policy' => [
                    'snapshot_version' => 1,
                    'max_attempts' => 1,
                    'heartbeat_timeout' => 90,
                    'start_to_close_timeout' => 180,
                ],
            ])->save();
            $claim = $this->bridge->claimStatus($task->id, 'lease-original');
            $before = $execution->fresh()
                ->getAttributes();
            $historyCount = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count();
            Date::setTestNow(now()->addSeconds(10));
            $this->assertInstanceOf(DefaultActivityTaskBridge::class, $this->bridge);

            $receipt = $this->bridge->renewLease($claim['activity_attempt_id'], 'lease-original');

            $this->assertTrue($receipt['lease_renewed']);
            $this->assertTrue($receipt['can_continue']);
            $this->assertFalse($receipt['heartbeat_recorded']);
            $this->assertSame($claim['activity_attempt_id'], $receipt['activity_attempt_id']);
            $this->assertSame($task->id, $receipt['workflow_task_id']);
            $this->assertSame(now()->toJSON(), $receipt['server_time']);
            $this->assertSame(now()->addSeconds(19)->toJSON(), $receipt['lease_expires_at']);
            $this->assertSame($execution->fresh()->heartbeat_deadline_at?->toJSON(), $receipt['heartbeat_deadline_at']);
            $this->assertSame($before, $execution->fresh()->getAttributes());
            $this->assertNull(ActivityAttempt::query()->findOrFail($claim['activity_attempt_id'])->last_heartbeat_at);
            $this->assertSame(
                $historyCount,
                WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count()
            );
            $view = RunActivityView::activitiesForRun($run->fresh())[0];
            $observedLease = $view['attempts'][0]['lease_expires_at'];
            $this->assertInstanceOf(\Carbon\CarbonInterface::class, $observedLease);
            $this->assertSame($receipt['lease_expires_at'], $observedLease->toJSON());
        } finally {
            Date::setTestNow();
        }
    }

    public function testRuntimeRenewalReportsCommittedHeartbeatWithoutRecordingAnother(): void
    {
        config([
            'workflows.v2.activity_task_lease_seconds' => 19,
        ]);
        Date::setTestNow('2026-10-21T00:00:00.123456Z');
        try {
            [$run, $execution, $task] = $this->createActivityTask();
            $execution->forceFill([
                'retry_policy' => [
                    'snapshot_version' => 1,
                    'max_attempts' => 1,
                    'heartbeat_timeout' => 90,
                    'start_to_close_timeout' => 180,
                ],
            ])->save();
            $claim = $this->bridge->claimStatus($task->id, 'lease-original');
            Date::setTestNow(now()->addSeconds(10));
            $progress = $this->bridge->heartbeat($claim['activity_attempt_id'], [
                'message' => 'working',
            ]);
            $this->assertTrue($progress['heartbeat_recorded']);
            $this->assertSame(now()->addSeconds(90)->toJSON(), $progress['heartbeat_deadline_at']);
            $before = $execution->fresh()
                ->getAttributes();
            $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count();

            Date::setTestNow(now()->addSeconds(2));
            $this->assertInstanceOf(DefaultActivityTaskBridge::class, $this->bridge);
            $receipt = $this->bridge->renewLease($claim['activity_attempt_id'], 'lease-original');
            $this->assertTrue($receipt['lease_renewed']);
            $this->assertFalse($receipt['heartbeat_recorded']);
            $this->assertSame($progress['last_heartbeat_at'], $receipt['last_heartbeat_at']);
            $this->assertSame($progress['heartbeat_deadline_at'], $receipt['heartbeat_deadline_at']);
            $this->assertSame($before, $execution->fresh()->getAttributes());
            $this->assertSame($history, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
        } finally {
            Date::setTestNow();
        }
    }

    public function testRuntimeLeaseRenewalRefusesAnotherOwnerWithoutMutation(): void
    {
        [, $execution, $task] = $this->createActivityTask();
        $claim = $this->bridge->claimStatus($task->id, 'lease-original');
        $before = [$task->fresh()->getAttributes(), $execution->fresh()->getAttributes()];
        $this->assertInstanceOf(DefaultActivityTaskBridge::class, $this->bridge);

        $receipt = $this->bridge->renewLease($claim['activity_attempt_id'], 'foreign-owner');

        $this->assertFalse($receipt['lease_renewed']);
        $this->assertFalse($receipt['can_continue']);
        $this->assertSame('lease_owner_mismatch', $receipt['reason']);
        $this->assertSame($before, [$task->fresh()->getAttributes(), $execution->fresh()->getAttributes()]);
    }

    public function testRuntimeLeaseRenewalCannotReviveAnExpiredOriginalLease(): void
    {
        [, $execution, $task] = $this->createActivityTask();
        $claim = $this->bridge->claimStatus($task->id, 'lease-original');
        $before = [$task->fresh()->getAttributes(), $execution->fresh()->getAttributes()];
        Date::setTestNow($task->fresh()->lease_expires_at);
        try {
            $this->assertInstanceOf(DefaultActivityTaskBridge::class, $this->bridge);
            $receipt = $this->bridge->renewLease($claim['activity_attempt_id'], 'lease-original');

            $this->assertFalse($receipt['lease_renewed']);
            $this->assertSame('lease_expired', $receipt['reason']);
            $this->assertSame($before, [$task->fresh()->getAttributes(), $execution->fresh()->getAttributes()]);
        } finally {
            Date::setTestNow();
        }
    }

    public function testRuntimeLeaseRenewalCannotHideAnAuthoredHeartbeatTimeout(): void
    {
        [, $execution, $task] = $this->createActivityTask();
        $execution->forceFill([
            'retry_policy' => [
                'snapshot_version' => 1,
                'max_attempts' => 1,
                'heartbeat_timeout' => 10,
            ],
        ])->save();
        $claim = $this->bridge->claimStatus($task->id, 'lease-original');
        $before = $execution->fresh()
            ->getAttributes();
        Date::setTestNow($execution->fresh()->heartbeat_deadline_at);
        try {
            $this->assertInstanceOf(DefaultActivityTaskBridge::class, $this->bridge);
            $receipt = $this->bridge->renewLease($claim['activity_attempt_id'], 'lease-original');

            $this->assertFalse($receipt['lease_renewed']);
            $this->assertSame('heartbeat_timeout', $receipt['reason']);
            $this->assertSame($before, $execution->fresh()->getAttributes());
        } finally {
            Date::setTestNow();
        }
    }

    public function testRuntimeLeaseRenewalKeepsTheOriginalLongAttemptWithoutHistoryGrowth(): void
    {
        Queue::fake();
        config([
            'workflows.v2.activity_task_lease_seconds' => 90,
        ]);
        Date::setTestNow('2026-10-20T19:00:00.123456Z');
        try {
            [$run, $execution, $task] = $this->createActivityTask();
            $claim = $this->bridge->claimStatus($task->id, 'lease-original');
            $historyCount = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count();
            $this->assertInstanceOf(DefaultActivityTaskBridge::class, $this->bridge);

            for ($minute = 0; $minute < 6; ++$minute) {
                Date::setTestNow(now()->addMinute());
                $receipt = $this->bridge->renewLease($claim['activity_attempt_id'], 'lease-original');
                $this->assertTrue($receipt['lease_renewed']);
            }

            $outcome = $this->bridge->complete(
                $claim['activity_attempt_id'],
                Serializer::serialize('one original result')
            );
            $this->assertTrue($outcome['recorded']);
            $this->assertSame(1, $execution->fresh()->attempt_count);
            $this->assertSame(1, ActivityAttempt::query()->where('activity_execution_id', $execution->id)->count());
            $this->assertSame(
                $historyCount + 1,
                WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count()
            );
            $this->assertSame(
                0,
                WorkflowHistoryEvent::query()->where(
                    'event_type',
                    HistoryEventType::ActivityHeartbeatRecorded->value
                )->count()
            );
        } finally {
            Date::setTestNow();
        }
    }

    public function testRuntimeLeaseRenewalDoesNotAcknowledgeACancelledCallback(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();
        $claim = $this->bridge->claimStatus($task->id, 'lease-original');
        $run->forceFill([
            'status' => RunStatus::Cancelled,
        ])->save();
        $before = [$task->fresh()->getAttributes(), $execution->fresh()->getAttributes()];
        $historyCount = WorkflowHistoryEvent::query()->count();
        $this->assertInstanceOf(DefaultActivityTaskBridge::class, $this->bridge);

        $receipt = $this->bridge->renewLease($claim['activity_attempt_id'], 'lease-original');

        $this->assertFalse($receipt['lease_renewed']);
        $this->assertTrue($receipt['cancel_requested']);
        $this->assertSame('run_cancelled', $receipt['reason']);
        $this->assertSame($before, [$task->fresh()->getAttributes(), $execution->fresh()->getAttributes()]);
        $this->assertSame($historyCount, WorkflowHistoryEvent::query()->count());
    }

    #[DataProvider('invalidActivityLeaseSettings')]
    public function testInvalidActivityLeaseConfigurationUsesThePublishedDefault(mixed $value): void
    {
        config([
            'workflows.v2.activity_task_lease_seconds' => $value,
        ]);
        Date::setTestNow('2026-10-20T19:00:00.123456Z');
        try {
            [, , $task] = $this->createActivityTask();
            $claim = $this->bridge->claimStatus($task->id, 'lease-original');
            $this->assertSame(now()->addSeconds(300)->toJSON(), $claim['lease_expires_at']);
            $snapshot = \Workflow\V2\Support\MatchingRoleSnapshot::current();
            $this->assertSame(300, $snapshot['discovery_limits']['activity_task_lease_seconds']);
        } finally {
            Date::setTestNow();
        }
    }

    public static function invalidActivityLeaseSettings(): array
    {
        return [[0], [-1], [true], [1.5], ['forever'], [null], [86_401], ['99999999999999999999999']];
    }

    public function testExpiredCurrentLeaseCannotCompleteBeforeRepair(): void
    {
        Queue::fake();
        [$run, $execution, $task] = $this->createActivityTask();
        $claim = $this->bridge->claimStatus($task->id, 'expiry-original');
        $before = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count();

        Date::setTestNow($task->fresh()->lease_expires_at->addMicrosecond());
        try {
            $outcome = $this->bridge->complete($claim['activity_attempt_id'], 'late result');

            $this->assertFalse($outcome['recorded']);
            $this->assertSame('lease_expired', $outcome['reason']);
            $this->assertSame($before, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
            $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
            $this->assertSame(ActivityStatus::Running, $execution->fresh()->status);
            $this->assertSame(
                ActivityAttemptStatus::Running,
                ActivityAttempt::query()->findOrFail($claim['activity_attempt_id'])->status
            );
        } finally {
            Date::setTestNow();
        }
    }

    public function testExpiredCurrentLeaseCannotRecordApplicationFailureBeforeRepair(): void
    {
        Queue::fake();
        [$run, $execution, $task] = $this->createActivityTask();
        $claim = $this->bridge->claimStatus($task->id, 'expiry-original');
        $before = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count();

        Date::setTestNow($task->fresh()->lease_expires_at);
        try {
            $outcome = $this->bridge->fail($claim['activity_attempt_id'], new \RuntimeException('late failure'));

            $this->assertFalse($outcome['recorded']);
            $this->assertSame('lease_expired', $outcome['reason']);
            $this->assertSame($before, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
            $this->assertSame(ActivityStatus::Running, $execution->fresh()->status);
            $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
        } finally {
            Date::setTestNow();
        }
    }

    public function testExpiredLeaseCannotBeRevivedByAnActivityHeartbeat(): void
    {
        Queue::fake();
        [$run, $execution, $task] = $this->createActivityTask();
        $claim = $this->bridge->claimStatus($task->id, 'expiry-original');
        $expiry = $task->fresh()
->lease_expires_at;
        $before = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count();

        Date::setTestNow($expiry->copy()->addSecond());
        try {
            $status = $this->bridge->heartbeat($claim['activity_attempt_id'], [
                'message' => 'late heartbeat',
            ]);

            $this->assertFalse($status['can_continue']);
            $this->assertFalse($status['heartbeat_recorded']);
            $this->assertSame('lease_expired', $status['reason']);
            $this->assertTrue($task->fresh()->lease_expires_at->equalTo($expiry));
            $this->assertSame($before, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
            $this->assertNull($execution->fresh()->last_heartbeat_at);
        } finally {
            Date::setTestNow();
        }
    }

    public function testStatusStopsExactlyAtCurrentLeaseBoundary(): void
    {
        Queue::fake();
        [, , $task] = $this->createActivityTask();
        $claim = $this->bridge->claimStatus($task->id, 'expiry-original');

        Date::setTestNow($task->fresh()->lease_expires_at);
        try {
            $status = $this->bridge->status($claim['activity_attempt_id']);

            $this->assertFalse($status['can_continue']);
            $this->assertFalse($status['heartbeat_recorded']);
            $this->assertSame('lease_expired', $status['reason']);
        } finally {
            Date::setTestNow();
        }
    }

    public function testResultPreparationCrossingLeaseExpiryCannotCommit(): void
    {
        Queue::fake();
        [$run, $execution, $task] = $this->createActivityTask();
        $claim = $this->bridge->claimStatus($task->id, 'expiry-original');
        $before = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count();
        $policy = new class($task->fresh()->lease_expires_at) implements ExternalPayloadStoragePolicy {
            public function __construct(
                private readonly \Carbon\CarbonInterface $expiry
            ) {
            }

            public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
            {
                Date::setTestNow($this->expiry);

                return null;
            }

            public function thresholdBytesFor(?string $namespace): ?int
            {
                return null;
            }
        };
        $this->app->instance(ExternalPayloadStoragePolicy::class, $policy);

        try {
            $outcome = $this->bridge->complete($claim['activity_attempt_id'], 'result prepared across expiry');

            $this->assertFalse($outcome['recorded']);
            $this->assertSame('lease_expired', $outcome['reason']);
            $this->assertSame($before, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
            $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
            $this->assertSame(ActivityStatus::Running, $execution->fresh()->status);
        } finally {
            Date::setTestNow();
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function executionDeadlines(): iterable
    {
        yield 'schedule-to-close' => ['schedule_to_close_deadline_at', 'schedule_to_close_timeout'];
        yield 'start-to-close' => ['close_deadline_at', 'start_to_close_timeout'];
        yield 'heartbeat' => ['heartbeat_deadline_at', 'heartbeat_timeout'];
    }

    #[DataProvider('executionDeadlines')]
    public function testExpiredExecutionDeadlineFencesOutcomeAndRenewalWithLiveLease(
        string $field,
        string $reason
    ): void {
        Queue::fake();
        [$run, $execution, $task] = $this->createActivityTask();
        $claim = $this->bridge->claimStatus($task->id, 'deadline-original');
        $deadline = now()
            ->addSecond();
        $execution->forceFill([
            $field => $deadline,
        ])->save();
        $expiry = $task->fresh()
->lease_expires_at;
        $before = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count();

        Date::setTestNow($deadline);
        try {
            $this->assertTrue(now()->lt($expiry));
            $status = $this->bridge->status($claim['activity_attempt_id']);
            $heartbeat = $this->bridge->heartbeat($claim['activity_attempt_id']);
            $complete = $this->bridge->complete($claim['activity_attempt_id'], 'late result');
            $fail = $this->bridge->fail($claim['activity_attempt_id'], new \RuntimeException('late failure'));

            $this->assertFalse($status['can_continue']);
            $this->assertSame($reason, $status['reason']);
            $this->assertFalse($heartbeat['heartbeat_recorded']);
            $this->assertSame($reason, $heartbeat['reason']);
            $this->assertFalse($complete['recorded']);
            $this->assertSame($reason, $complete['reason']);
            $this->assertFalse($fail['recorded']);
            $this->assertSame($reason, $fail['reason']);
            $this->assertTrue($task->fresh()->lease_expires_at->equalTo($expiry));
            $this->assertSame($before, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());

            $timeout = ActivityTimeoutEnforcer::enforce($execution->id);
            $this->assertTrue($timeout['enforced']);
            $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ActivityTimedOut)->count());
        } finally {
            Date::setTestNow();
        }
    }

    public function testValidHeartbeatRenewalAllowsCompletionBeyondInitialLease(): void
    {
        Queue::fake();
        [, , $task] = $this->createActivityTask();
        $claim = $this->bridge->claimStatus($task->id, 'renewal-original');
        $initialExpiry = $task->fresh()
->lease_expires_at;

        Date::setTestNow($initialExpiry->copy()->subSecond());
        try {
            $heartbeat = $this->bridge->heartbeat($claim['activity_attempt_id']);
            $this->assertTrue($heartbeat['heartbeat_recorded']);
            $this->assertTrue($task->fresh()->lease_expires_at->gt($initialExpiry));

            Date::setTestNow($initialExpiry->copy()->addSecond());
            $this->assertTrue($this->bridge->status($claim['activity_attempt_id'])['can_continue']);
            $this->assertTrue($this->bridge->complete($claim['activity_attempt_id'], 'renewed result')['recorded']);
        } finally {
            Date::setTestNow();
        }
    }

    public function testExpiredAttemptRecoveryCompletesSameExecutionWithOneCompletion(): void
    {
        Queue::fake();
        [$run, $execution, $task] = $this->createActivityTask();
        $execution->forceFill([
            'retry_policy' => [
                'snapshot_version' => 1,
                'max_attempts' => 2,
                'backoff_seconds' => [0],
            ],
        ])->save();
        $original = $this->bridge->claimStatus($task->id, 'lost-original');

        Date::setTestNow($task->fresh()->lease_expires_at->copy()->addSecond());
        try {
            $this->assertFalse($this->bridge->complete($original['activity_attempt_id'], 'late original')['recorded']);
            $retry = TaskRepair::recoverExistingTask($task->fresh(), $run->fresh());
            $this->assertInstanceOf(WorkflowTask::class, $retry);
            $this->assertSame(TaskType::Activity, $retry->task_type);
            $this->assertNotSame($task->id, $retry->id);
            $replacement = $this->bridge->claimStatus($retry->id, 'replacement');
            $this->assertTrue($replacement['claimed']);
            $this->assertSame($execution->id, $replacement['activity_execution_id']);
            $this->assertSame($run->id, $replacement['workflow_run_id']);
            $this->assertNotSame($original['activity_attempt_id'], $replacement['activity_attempt_id']);
            $this->assertFalse($this->bridge->complete($original['activity_attempt_id'], 'stale original')['recorded']);
            $this->assertTrue(
                $this->bridge->complete($replacement['activity_attempt_id'], 'replacement result')['recorded']
            );
            $this->assertFalse($this->bridge->complete($replacement['activity_attempt_id'], 'duplicate')['recorded']);
            $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ActivityCompleted)->count());
        } finally {
            Date::setTestNow();
        }
    }

    public function testPollReturnsReadyActivityTasks(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $results = $this->bridge->poll('redis', 'default');

        $this->assertCount(1, $results);
        $this->assertSame($task->id, $results[0]['task_id']);
        $this->assertSame($run->id, $results[0]['workflow_run_id']);
        $this->assertSame($execution->id, $results[0]['activity_execution_id']);
        $this->assertSame('redis', $results[0]['connection']);
        $this->assertSame('default', $results[0]['queue']);
    }

    public function testPollExcludesNonActivityTasks(): void
    {
        $run = $this->createWaitingRun();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $results = $this->bridge->poll('redis', 'default');

        $this->assertCount(0, $results);
    }

    public function testPollExcludesFutureAvailableTasks(): void
    {
        [$run, $execution] = $this->createActivityExecution();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->addMinutes(5),
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $results = $this->bridge->poll('redis', 'default');

        $this->assertCount(0, $results);
    }

    public function testPollFiltersByQueue(): void
    {
        $this->createActivityTask();

        $results = $this->bridge->poll('redis', 'other-queue');

        $this->assertCount(0, $results);
    }

    public function testPollFiltersByCompatibility(): void
    {
        $this->createActivityTask([
            'compatibility' => 'build-a',
        ]);

        $results = $this->bridge->poll(null, null, 1, 'build-b');

        $this->assertCount(0, $results);
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function unpinnedActivityBuilds(): array
    {
        return [
            'null pin, first build' => [null, 'build-a'],
            'null pin, second build' => [null, 'build-b'],
            'empty pin, first build' => ['', 'build-a'],
            'empty pin, second build' => ['', 'build-b'],
        ];
    }

    #[DataProvider('unpinnedActivityBuilds')]
    public function testVersionedWorkerCanPollAndClaimAnUnpinnedActivity(?string $compatibility, string $build): void
    {
        [$run, $execution, $task] = $this->createActivityTask([
            'compatibility' => $compatibility,
        ]);
        $run->forceFill([
            'compatibility' => null,
        ])->save();
        config()
            ->set('workflows.v2.compatibility.current', $build);
        config()
            ->set('workflows.v2.compatibility.supported', [$build]);

        $results = $this->bridge->poll('redis', 'default', 1, $build);

        $this->assertCount(1, $results);
        $this->assertSame($task->id, $results[0]['task_id']);
        $this->assertSame($run->id, $results[0]['workflow_run_id']);
        $this->assertSame($execution->id, $results[0]['activity_execution_id']);
        $claim = $this->bridge->claimStatus($results[0]['task_id'], $build . '-worker');
        $this->assertTrue($claim['claimed']);
        $this->assertSame($task->id, $claim['task_id']);
        $this->assertSame($execution->id, $claim['activity_execution_id']);
        $this->assertSame(1, $claim['attempt_number']);
        $this->assertSame($build . '-worker', $claim['lease_owner']);
        $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
        $this->assertNull($run->fresh()->compatibility);
        $this->assertSame($compatibility, $task->fresh()->compatibility);
    }

    public function testVersionedActivityPollPreservesItsScopeAndBuildFence(): void
    {
        $scope = [
            'namespace' => 'tenant-main',
            'queue' => 'work',
        ];
        [, , $unpinned] = $this->createActivityTask($scope);
        [, , $pinned] = $this->createActivityTask(array_merge($scope, [
            'compatibility' => 'build-a',
        ]));
        foreach ([
            [
                'compatibility' => 'build-b',
            ],
            [
                'namespace' => 'tenant-other',
            ],
            [
                'queue' => 'other',
            ],
            [
                'connection' => 'other',
            ],
            [
                'task_type' => TaskType::Workflow->value,
            ],
            [
                'status' => TaskStatus::Leased->value,
            ],
            [
                'available_at' => now()
                    ->addMinutes(5),
            ],
        ] as $excluded) {
            $this->createActivityTask(array_merge($scope, $excluded));
        }
        [, $otherType] = $this->createActivityTask($scope);
        $otherType->forceFill([
            'activity_type' => 'other-activity',
        ])->save();

        $results = $this->bridge->poll('redis', 'work', 20, 'build-a', 'tenant-main', ['test-greeting-activity']);

        $this->assertEqualsCanonicalizing([$unpinned->id, $pinned->id], array_column($results, 'task_id'));
        $this->assertSame(0, $unpinned->fresh()->attempt_count);
        $this->assertSame(0, $pinned->fresh()->attempt_count);
    }

    public function testPollWithNullFiltersReturnsAllReadyTasks(): void
    {
        $this->createActivityTask();

        $results = $this->bridge->poll(null, null);

        $this->assertCount(1, $results);
    }

    public function testPollRespectsLimit(): void
    {
        $this->createActivityTask();
        $this->createActivityTask();
        $this->createActivityTask();

        $results = $this->bridge->poll(null, null, 2);

        $this->assertCount(2, $results);
    }

    public function testPollFiltersByActivityTypeBeforeApplyingLimit(): void
    {
        [, $firstExecution] = $this->createActivityExecution();
        $firstExecution->forceFill([
            'activity_type' => 'other-activity-one',
        ])->save();

        WorkflowTask::query()->create([
            'workflow_run_id' => $firstExecution->workflow_run_id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinutes(3),
            'payload' => [
                'activity_execution_id' => $firstExecution->id,
            ],
            'connection' => 'redis',
            'queue' => 'default',
            'attempt_count' => 0,
        ]);

        [, $secondExecution] = $this->createActivityExecution();
        $secondExecution->forceFill([
            'activity_type' => 'other-activity-two',
        ])->save();

        WorkflowTask::query()->create([
            'workflow_run_id' => $secondExecution->workflow_run_id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinutes(2),
            'payload' => [
                'activity_execution_id' => $secondExecution->id,
            ],
            'connection' => 'redis',
            'queue' => 'default',
            'attempt_count' => 0,
        ]);

        [$matchingRun, $matchingExecution, $matchingTask] = $this->createActivityTask([
            'available_at' => now()
                ->subMinute(),
        ]);

        $results = $this->bridge->poll(null, null, 2, null, null, ['test-greeting-activity']);

        $this->assertCount(1, $results);
        $this->assertSame($matchingTask->id, $results[0]['task_id']);
        $this->assertSame($matchingRun->id, $results[0]['workflow_run_id']);
        $this->assertSame($matchingExecution->id, $results[0]['activity_execution_id']);
        $this->assertSame('test-greeting-activity', $results[0]['activity_type']);
    }

    public function testClaimStatusClaimsReadyTask(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $result = $this->bridge->claimStatus($task->id, 'server-worker-1');

        $this->assertTrue($result['claimed']);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame($execution->id, $result['activity_execution_id']);
        $this->assertNotNull($result['activity_attempt_id']);
        $this->assertSame(1, $result['attempt_number']);
        $this->assertSame('server-worker-1', $result['lease_owner']);
        $this->assertIsString($result['arguments']);
        $this->assertSame(['World'], Serializer::unserializeWithCodec(
            $result['payload_codec'],
            $result['arguments'],
        ));
        $this->assertIsArray($result['arguments_envelope']);
        $this->assertSame(['World'], Serializer::unserializeWithCodec(
            $result['arguments_envelope']['codec'],
            $result['arguments_envelope']['blob'],
        ));
        $this->assertNotNull($result['lease_expires_at']);
        $this->assertNull($result['reason']);
    }

    public function testClaimStatusUsesHistoryProjectionRoleBinding(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): \Workflow\V2\Models\WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): \Workflow\V2\Models\WorkflowRunSummary {
                $this->calls[] = ['recordActivityStarted', $run->id, $execution->id, $attempt->id, $task->id];

                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);

        $result = $this->bridge->claimStatus($task->id, 'server-worker-1');

        $this->assertTrue($result['claimed']);
        $this->assertSame(
            [['recordActivityStarted', $run->id, $execution->id, $result['activity_attempt_id'], $task->id]],
            $customRole->calls,
        );
    }

    public function testClaimStatusRejectsNonExistentTask(): void
    {
        $result = $this->bridge->claimStatus('nonexistent-task-id');

        $this->assertFalse($result['claimed']);
        $this->assertNotNull($result['reason']);
    }

    public function testClaimStatusRejectsAlreadyLeasedTask(): void
    {
        [$run, $execution, $task] = $this->createActivityTask([
            'status' => TaskStatus::Leased->value,
            'lease_owner' => 'other-worker',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        $result = $this->bridge->claimStatus($task->id);

        $this->assertFalse($result['claimed']);
    }

    public function testClaimReturnsNullOnFailure(): void
    {
        $result = $this->bridge->claim('nonexistent-task-id');

        $this->assertNull($result);
    }

    public function testClaimReturnsPayloadOnSuccess(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $result = $this->bridge->claim($task->id, 'server-worker-1');

        $this->assertNotNull($result);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertSame($run->id, $result['workflow_run_id']);
        $this->assertSame($execution->id, $result['activity_execution_id']);
        $this->assertNotNull($result['activity_attempt_id']);
        $this->assertSame(1, $result['attempt_number']);
        $this->assertSame('server-worker-1', $result['lease_owner']);
        $this->assertArrayNotHasKey('reason', $result);
    }

    public function testClaimSurfacesExternalizedArgumentsAsResolvableEnvelope(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());

        [, $execution, $task] = $this->createActivityTask();
        $this->externalizeActivityArguments($execution, $driver);

        $status = $this->bridge->claimStatus($task->id, 'server-worker-1');

        $this->assertTrue($status['claimed']);
        $this->assertSame($execution->arguments, $status['arguments']);
        $this->assertExternalArgumentsEnvelope($status['arguments_envelope']);

        [, $execution, $task] = $this->createActivityTask();
        $this->externalizeActivityArguments($execution, $driver);

        $claim = $this->bridge->claim($task->id, 'server-worker-2');

        $this->assertIsArray($claim);
        $this->assertSame($execution->arguments, $claim['arguments']);
        $this->assertExternalArgumentsEnvelope($claim['arguments_envelope']);
    }

    public function testActivityRecoveryPreservesExternalizedArgumentsFromHistory(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());

        [$run, $execution] = $this->createActivityExecution();
        $this->externalizeActivityArguments($execution, $driver);

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]);

        $activityId = $execution->id;
        $payloadCodec = $execution->payload_codec;
        $storedArguments = $execution->arguments;
        $execution->delete();

        $restored = ActivityRecovery::restore($run->fresh(['historyEvents', 'activityExecutions']), $activityId);

        $this->assertInstanceOf(ActivityExecution::class, $restored);
        $this->assertSame($payloadCodec, $restored->payload_codec);
        $this->assertSame($storedArguments, $restored->arguments);
        $this->assertExternalArgumentsEnvelope(
            ExternalPayloads::historyValue($restored->arguments, $restored->payload_codec, null),
        );
    }

    public function testRunActivityViewDecodesExternalizedActivityPayloads(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $this->bindExternalPayloadPolicy($driver);

        [$run, $execution] = $this->createActivityExecution();
        $codec = CodecRegistry::defaultCodec();
        $result = 'Hello, World!';

        $execution->forceFill([
            'payload_codec' => $codec,
            'arguments' => ExternalPayloads::externalize(
                Serializer::serializeWithCodec($codec, ['World']),
                $codec,
                $driver,
                1,
            ),
            'result' => ExternalPayloads::externalize(
                Serializer::serializeWithCodec($codec, $result),
                $codec,
                $driver,
                1,
            ),
            'status' => ActivityStatus::Completed->value,
            'attempt_count' => 1,
            'closed_at' => now(),
        ])->save();

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::ActivityCompleted, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => 1,
            'payload_codec' => $codec,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]);

        $activities = RunActivityView::activitiesForRun($run->fresh(['historyEvents', 'activityExecutions.attempts']));

        $this->assertCount(1, $activities);
        $this->assertSame(['World'], $activities[0]['arguments']);
        $this->assertSame($result, $activities[0]['result']);
    }

    public function testRunActivityViewCanReadProjectionsWithoutLoadingDurableHistory(): void
    {
        [$run, $execution] = $this->createActivityExecution();
        $codec = CodecRegistry::defaultCodec();

        $execution->forceFill([
            'status' => ActivityStatus::Completed->value,
            'attempt_count' => 1,
            'result' => Serializer::serializeWithCodec($codec, 'projection-result'),
            'closed_at' => now(),
        ])->save();

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::ActivityCompleted, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => 1,
            'payload_codec' => $codec,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]);

        $projectionRun = $run->fresh();
        DB::enableQueryLog();

        try {
            $projectedActivities = RunActivityView::activitiesForRun($projectionRun, useDurableHistory: false);
            $historySelects = array_values(array_filter(
                DB::getQueryLog(),
                static function (array $query): bool {
                    $sql = strtolower((string) ($query['query'] ?? ''));

                    return str_starts_with(ltrim($sql), 'select')
                        && str_contains($sql, 'workflow_history_events');
                },
            ));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame([], $historySelects);
        $this->assertFalse($projectionRun->relationLoaded('historyEvents'));
        $this->assertCount(1, $projectedActivities);
        $this->assertSame('unsupported', $projectedActivities[0]['status']);
        $this->assertSame(ActivityStatus::Completed->value, $projectedActivities[0]['row_status']);
        $this->assertSame(
            RunActivityView::UNSUPPORTED_TERMINAL_REASON,
            $projectedActivities[0]['history_unsupported_reason'],
        );
        $this->assertNull($projectedActivities[0]['result']);

        $completeActivities = RunActivityView::activitiesForRun($run->fresh());

        $this->assertCount(1, $completeActivities);
        $this->assertSame('completed', $completeActivities[0]['status']);
        $this->assertSame(RunActivityView::HISTORY_AUTHORITY_TYPED, $completeActivities[0]['history_authority']);
        $this->assertSame('projection-result', $completeActivities[0]['result']);
    }

    public function testProjectionOnlyActivityViewDoesNotManufactureHistoryOnlyActivities(): void
    {
        [$run, $execution] = $this->createActivityExecution();

        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]);

        $execution->delete();

        $this->assertSame([], RunActivityView::activitiesForRun($run->fresh(), useDurableHistory: false));

        $completeActivities = RunActivityView::activitiesForRun($run->fresh());

        $this->assertCount(1, $completeActivities);
        $this->assertSame(RunActivityView::HISTORY_AUTHORITY_TYPED, $completeActivities[0]['history_authority']);
    }

    public function testCompleteRecordsActivityResult(): void
    {
        Queue::fake();

        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $result = [];
        $calls = $this->historyProjectionCallsDuring(function () use ($claim, &$result): void {
            $result = $this->bridge->complete($claim['activity_attempt_id'], 'Hello, World!');
        });

        $this->assertTrue($result['recorded']);
        $this->assertNull($result['reason']);

        $this->assertIsString($result['next_task_id']);

        /** @var WorkflowTask $resumeTask */
        $resumeTask = WorkflowTask::query()->findOrFail($result['next_task_id']);

        $this->assertSame('activity', $resumeTask->payload['workflow_wait_kind'] ?? null);
        $this->assertSame(sprintf('activity:%s', $execution->id), $resumeTask->payload['open_wait_id'] ?? null);
        $this->assertSame('activity_execution', $resumeTask->payload['resume_source_kind'] ?? null);
        $this->assertSame($execution->id, $resumeTask->payload['resume_source_id'] ?? null);
        $this->assertSame($execution->id, $resumeTask->payload['activity_execution_id'] ?? null);
        $this->assertSame($claim['activity_attempt_id'], $resumeTask->payload['activity_attempt_id'] ?? null);
        $this->assertSame('test-greeting-activity', $resumeTask->payload['activity_type'] ?? null);
        $this->assertSame(1, $resumeTask->payload['workflow_sequence'] ?? null);
        $this->assertSame(
            HistoryEventType::ActivityCompleted->value,
            $resumeTask->payload['workflow_event_type'] ?? null
        );
        $this->assertContains(['projectRun', $run->id], $calls);
    }

    public function testCompleteRejectsUnknownAttempt(): void
    {
        $result = $this->bridge->complete('nonexistent-attempt', 'result');

        $this->assertFalse($result['recorded']);
    }

    public function testFailRecordsActivityFailure(): void
    {
        Queue::fake();

        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $result = $this->bridge->fail($claim['activity_attempt_id'], 'Something went wrong');

        $this->assertTrue($result['recorded']);
        $this->assertIsString($result['next_task_id']);

        /** @var WorkflowTask $resumeTask */
        $resumeTask = WorkflowTask::query()->findOrFail($result['next_task_id']);

        $this->assertSame('activity', $resumeTask->payload['workflow_wait_kind'] ?? null);
        $this->assertSame(sprintf('activity:%s', $execution->id), $resumeTask->payload['open_wait_id'] ?? null);
        $this->assertSame('activity_execution', $resumeTask->payload['resume_source_kind'] ?? null);
        $this->assertSame($execution->id, $resumeTask->payload['resume_source_id'] ?? null);
        $this->assertSame($execution->id, $resumeTask->payload['activity_execution_id'] ?? null);
        $this->assertSame($claim['activity_attempt_id'], $resumeTask->payload['activity_attempt_id'] ?? null);
        $this->assertSame('test-greeting-activity', $resumeTask->payload['activity_type'] ?? null);
        $this->assertSame(1, $resumeTask->payload['workflow_sequence'] ?? null);
        $this->assertSame(
            HistoryEventType::ActivityFailed->value,
            $resumeTask->payload['workflow_event_type'] ?? null
        );

        /** @var WorkflowHistoryEvent $failed */
        $failed = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityFailed->value)
            ->firstOrFail();

        $this->assertExternalFailurePayloadIsPublic($failed, 'Something went wrong');
    }

    public function testFailAcceptsArrayPayload(): void
    {
        Queue::fake();

        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $result = $this->bridge->fail($claim['activity_attempt_id'], [
            'message' => 'External failure',
            'class' => \RuntimeException::class,
        ]);

        $this->assertTrue($result['recorded']);

        /** @var WorkflowHistoryEvent $failed */
        $failed = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityFailed->value)
            ->firstOrFail();

        $this->assertExternalFailurePayloadIsPublic($failed, 'External failure');
    }

    #[DataProvider('largeFailureMessages')]
    public function testLargeFailureDeliveryPreservesAuthoritativePayloadAndBoundsOnlyTimeline(string $message): void
    {
        Queue::fake();
        [$run, $execution, $task] = $this->createActivityTask();
        $claim = $this->bridge->claim($task->id, 'worker-large-failure');
        $this->assertNotNull($claim);

        $result = $this->bridge->fail($claim['activity_attempt_id'], [
            'message' => $message,
            'type' => 'OriginalFailureType',
            'non_retryable' => true,
        ]);

        $this->assertTrue($result['recorded']);
        $this->assertIsString($result['next_task_id']);
        $failed = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityFailed->value)
            ->firstOrFail();
        $this->assertExternalFailurePayloadIsPublic($failed, $message);
        $this->assertSame($message, $failed->payload['message']);
        $this->assertSame('OriginalFailureType', $failed->payload['exception_type']);
        $this->assertTrue($failed->payload['non_retryable']);
        $this->assertSame(ActivityStatus::Failed, $execution->fresh()->status);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertDatabaseHas((new WorkflowTask())->getTable(), [
            'id' => $result['next_task_id'],
            'workflow_run_id' => $run->id,
            'status' => TaskStatus::Ready->value,
        ]);

        $entry = WorkflowTimelineEntry::query()->where('history_event_id', $failed->id)->firstOrFail();
        $this->assertLessThanOrEqual(65_535, strlen($entry->summary));
        $this->assertTrue(mb_check_encoding($entry->summary, 'UTF-8'));
        $this->assertStringContainsString('[truncated]', $entry->summary);
        $this->assertSame($message, $entry->toTimelinePayload()['failure']['message']);

        $originalPayload = $failed->getRawOriginal('payload');
        $originalSummary = $entry->summary;
        RunTimelineProjector::project($run->fresh());
        $this->assertSame($originalPayload, $failed->fresh()->getRawOriginal('payload'));
        $this->assertSame($originalSummary, $entry->fresh()->summary);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function largeFailureMessages(): iterable
    {
        yield 'ASCII at TEXT ceiling' => [str_repeat('x', 65_535)];
        yield 'UTF-8 at TEXT ceiling' => [str_repeat('界', 21_845)];
        yield 'mixed multibyte at TEXT ceiling' => [str_repeat('界🦀', 9_362) . 'x'];
    }

    public function testCompleteAfterCancelledRunClosesAttemptAndReportsIgnoredOutcome(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $run->forceFill([
            'status' => RunStatus::Cancelled->value,
        ])->save();

        $result = [];
        $calls = $this->historyProjectionCallsDuring(function () use ($claim, &$result): void {
            $result = $this->bridge->complete($claim['activity_attempt_id'], 'too late');
        });

        $this->assertFalse($result['recorded']);
        $this->assertSame('run_cancelled', $result['reason']);
        $this->assertNull($result['next_task_id']);

        /** @var ActivityExecution $execution */
        $execution = $execution->fresh();
        /** @var WorkflowTask $task */
        $task = $task->fresh();
        /** @var ActivityAttempt $attempt */
        $attempt = ActivityAttempt::query()->findOrFail($claim['activity_attempt_id']);

        $this->assertSame(ActivityStatus::Cancelled, $execution->status);
        $this->assertSame(ActivityAttemptStatus::Cancelled, $attempt->status);
        $this->assertSame(TaskStatus::Cancelled, $task->status);

        $this->assertDatabaseHas((new WorkflowHistoryEvent())->getTable(), [
            'workflow_run_id' => $run->id,
            'event_type' => HistoryEventType::ActivityCancelled->value,
        ]);
        $this->assertContains(['projectRun', $run->id], $calls);
    }

    public function testCompleteAfterClosedRunUsesHistoryProjectionRoleBinding(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $closedAt = now()
            ->subSecond();
        $run->forceFill([
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'closed_at' => $closedAt,
        ])->save();

        $result = [];
        $calls = $this->historyProjectionCallsDuring(function () use ($claim, &$result): void {
            $result = $this->bridge->complete($claim['activity_attempt_id'], 'late completion');
        });

        $this->assertTrue($result['recorded']);
        $this->assertNull($result['reason']);
        $this->assertNull($result['next_task_id']);

        /** @var ActivityExecution $execution */
        $execution = $execution->fresh();
        /** @var WorkflowTask $task */
        $task = $task->fresh();
        /** @var ActivityAttempt $attempt */
        $attempt = ActivityAttempt::query()->findOrFail($claim['activity_attempt_id']);

        $this->assertSame(ActivityStatus::Completed, $execution->status);
        $this->assertSame(ActivityAttemptStatus::Completed, $attempt->status);
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertContains(['projectRun', $run->id], $calls);
    }

    public function testFailAfterTerminatedRunClosesAttemptAndReportsIgnoredOutcome(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $run->forceFill([
            'status' => RunStatus::Terminated->value,
        ])->save();

        $result = $this->bridge->fail($claim['activity_attempt_id'], [
            'message' => 'too late',
            'type' => 'ExternalError',
        ]);

        $this->assertFalse($result['recorded']);
        $this->assertSame('run_terminated', $result['reason']);
        $this->assertNull($result['next_task_id']);

        /** @var ActivityExecution $execution */
        $execution = $execution->fresh();
        /** @var WorkflowTask $task */
        $task = $task->fresh();
        /** @var ActivityAttempt $attempt */
        $attempt = ActivityAttempt::query()->findOrFail($claim['activity_attempt_id']);

        $this->assertSame(ActivityStatus::Cancelled, $execution->status);
        $this->assertSame(ActivityAttemptStatus::Cancelled, $attempt->status);
        $this->assertSame(TaskStatus::Cancelled, $task->status);

        $this->assertDatabaseHas((new WorkflowHistoryEvent())->getTable(), [
            'workflow_run_id' => $run->id,
            'event_type' => HistoryEventType::ActivityCancelled->value,
        ]);
    }

    public function testStatusReturnsAttemptState(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $status = $this->bridge->status($claim['activity_attempt_id']);

        $this->assertTrue($status['can_continue']);
        $this->assertFalse($status['cancel_requested']);
        $this->assertNull($status['reason']);
        $this->assertFalse($status['heartbeat_recorded']);
        $this->assertSame($claim['activity_attempt_id'], $status['activity_attempt_id']);
        $this->assertSame($run->id, $status['workflow_run_id']);
    }

    public function testStatusRejectsUnknownAttempt(): void
    {
        $status = $this->bridge->status('nonexistent-attempt');

        $this->assertFalse($status['can_continue']);
        $this->assertSame('attempt_not_found', $status['reason']);
    }

    public function testHeartbeatRenewsLeaseAndRecords(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $result = $this->bridge->heartbeat($claim['activity_attempt_id']);

        $this->assertTrue($result['can_continue']);
        $this->assertFalse($result['cancel_requested']);
        $this->assertTrue($result['heartbeat_recorded']);
        $this->assertNotNull($result['last_heartbeat_at']);
        $this->assertNotNull($result['lease_expires_at']);
    }

    public function testHeartbeatWithProgress(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $result = $this->bridge->heartbeat($claim['activity_attempt_id'], [
            'current' => 50,
            'total' => 100,
            'message' => 'Halfway done',
        ]);

        $this->assertTrue($result['can_continue']);
        $this->assertTrue($result['heartbeat_recorded']);
    }

    public function testHeartbeatDetectsCancelledRun(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $closedAt = now()
            ->subSecond();
        $run->forceFill([
            'status' => RunStatus::Cancelled->value,
            'closed_reason' => 'cancelled',
            'closed_at' => $closedAt,
        ])->save();

        $result = $this->bridge->heartbeat($claim['activity_attempt_id']);

        $this->assertFalse($result['can_continue']);
        $this->assertTrue($result['cancel_requested']);
        $this->assertSame('cancelled', $result['run_closed_reason']);
        $this->assertSame($closedAt->toJSON(), $result['run_closed_at']);
    }

    public function testHeartbeatDetectsTerminatedRun(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $closedAt = now()
            ->subSecond();
        $run->forceFill([
            'status' => RunStatus::Terminated->value,
            'closed_reason' => 'terminated',
            'closed_at' => $closedAt,
        ])->save();

        $result = $this->bridge->heartbeat($claim['activity_attempt_id']);

        $this->assertFalse($result['can_continue']);
        $this->assertTrue($result['cancel_requested']);
        $this->assertSame('terminated', $result['run_closed_reason']);
        $this->assertSame($closedAt->toJSON(), $result['run_closed_at']);
    }

    public function testCompleteUsesHistoryProjectionRoleBinding(): void
    {
        Queue::fake();

        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->complete($claim['activity_attempt_id'], 'Hello, World!');

        $this->assertTrue($result['recorded']);
        $this->assertContains(['projectRun', $run->id], $customRole->calls);
    }

    public function testFailSchedulesRetryThroughHistoryProjectionRoleBinding(): void
    {
        Queue::fake();

        [$run, $execution, $task] = $this->createActivityTask();

        $execution->forceFill([
            'retry_policy' => [
                'max_attempts' => 2,
                'backoff_seconds' => [0],
            ],
        ])->save();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->fail($claim['activity_attempt_id'], 'retry me');

        $this->assertTrue($result['recorded']);
        $this->assertNotNull($result['next_task_id']);
        $this->assertContains(['projectRun', $run->id], $customRole->calls);
    }

    public function testHeartbeatCancelledRunUsesHistoryProjectionRoleBinding(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $run->forceFill([
            'status' => RunStatus::Cancelled->value,
            'closed_reason' => 'cancelled',
            'closed_at' => now()
                ->subSecond(),
        ])->save();

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->heartbeat($claim['activity_attempt_id']);

        $this->assertFalse($result['can_continue']);
        $this->assertTrue($result['cancel_requested']);
        $this->assertContains(['projectRun', $run->id], $customRole->calls);
    }

    public function testHeartbeatRenewLeaseUsesHistoryProjectionRoleBinding(): void
    {
        [$run, $execution, $task] = $this->createActivityTask();

        $claim = $this->bridge->claim($task->id, 'worker-1');
        $this->assertNotNull($claim);

        $customRole = $this->bindHistoryProjectionSpy();

        $result = $this->bridge->heartbeat($claim['activity_attempt_id']);

        $this->assertTrue($result['can_continue']);
        $this->assertTrue($result['heartbeat_recorded']);
        $this->assertContains(['projectRun', $run->id], $customRole->calls);
    }

    private function bindHistoryProjectionSpy()
    {
        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): \Workflow\V2\Models\WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): \Workflow\V2\Models\WorkflowRunSummary {
                $this->calls[] = ['recordActivityStarted', $run->id, $execution->id, $attempt->id, $task->id];

                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);

        return $customRole;
    }

    // -- Helpers --

    /**
     * @param array<string, mixed> $taskOverrides
     * @return array{0: WorkflowRun, 1: ActivityExecution, 2: WorkflowTask}
     */
    private function createActivityTask(array $taskOverrides = []): array
    {
        [$run, $execution] = $this->createActivityExecution();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create(array_merge([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => 'redis',
            'queue' => 'default',
            'attempt_count' => 0,
        ], $taskOverrides));

        return [$run, $execution, $task];
    }

    /**
     * @return array{0: WorkflowRun, 1: ActivityExecution}
     */
    private function createActivityExecution(): array
    {
        $run = $this->createWaitingRun();

        $codec = CodecRegistry::defaultCodec();

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => 'test-greeting-activity',
            'sequence' => 1,
            'status' => ActivityStatus::Pending->value,
            'payload_codec' => $codec,
            'arguments' => Serializer::serializeWithCodec($codec, ['World']),
            'connection' => 'redis',
            'queue' => 'default',
            'attempt_count' => 0,
            'started_at' => now(),
        ]);

        return [$run, $execution];
    }

    /**
     * @param callable(): void $callback
     * @return list<array<int, int|string>>
     */
    private function historyProjectionCallsDuring(callable $callback): array
    {
        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            /**
             * @var list<array<int, int|string>>
             */
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): \Workflow\V2\Models\WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): \Workflow\V2\Models\WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);

        $callback();

        return $customRole->calls;
    }

    private function createWaitingRun(): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function externalizeActivityArguments(
        ActivityExecution $execution,
        LocalFilesystemExternalPayloadStorage $driver,
    ): void {
        $codec = CodecRegistry::defaultCodec();

        $execution->forceFill([
            'payload_codec' => $codec,
            'arguments' => ExternalPayloads::externalize(
                Serializer::serializeWithCodec($codec, ['World']),
                $codec,
                $driver,
                1,
            ),
        ])->save();
    }

    private function bindExternalPayloadPolicy(ExternalPayloadStorageDriver $driver): void
    {
        $this->app->instance(
            ExternalPayloadStoragePolicy::class,
            new class($driver) implements ExternalPayloadStoragePolicy {
                public function __construct(
                    private readonly ExternalPayloadStorageDriver $driver,
                ) {
                }

                public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
                {
                    return $this->driver;
                }

                public function thresholdBytesFor(?string $namespace): ?int
                {
                    return 1;
                }
            },
        );
    }

    private function assertExternalArgumentsEnvelope(mixed $arguments): void
    {
        $this->assertIsArray($arguments);
        $this->assertSame(CodecRegistry::defaultCodec(), $arguments['codec']);
        $this->assertArrayHasKey('external_storage', $arguments);
        $this->assertArrayNotHasKey('blob', $arguments);
        $this->assertSame(ExternalPayloadReference::SCHEMA, $arguments['external_storage']['schema']);
    }

    private function assertExternalFailurePayloadIsPublic(WorkflowHistoryEvent $failed, string $message): void
    {
        $exception = $failed->payload['exception'] ?? null;
        $activityException = $failed->payload['activity']['exception'] ?? null;

        $this->assertIsArray($exception);
        $this->assertSame($message, $exception['message'] ?? null);
        $this->assertArrayNotHasKey('class', $exception);
        $this->assertArrayNotHasKey('file', $exception);
        $this->assertArrayNotHasKey('line', $exception);
        $this->assertArrayNotHasKey('trace', $exception);
        $this->assertArrayNotHasKey('properties', $exception);

        $this->assertIsArray($activityException);
        $this->assertSame($message, $activityException['message'] ?? null);
        $this->assertArrayNotHasKey('class', $activityException);
        $this->assertArrayNotHasKey('file', $activityException);
        $this->assertArrayNotHasKey('line', $activityException);
        $this->assertArrayNotHasKey('trace', $activityException);
        $this->assertArrayNotHasKey('properties', $activityException);
    }

    private function makeStorageRoot(): string
    {
        $this->storageRoot = sys_get_temp_dir() . '/dw-activity-task-bridge-' . bin2hex(random_bytes(6));

        return $this->storageRoot;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
