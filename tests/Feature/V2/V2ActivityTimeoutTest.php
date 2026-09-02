<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\Support\FailureSnapshots;
use Workflow\V2\Support\RunActivityView;
use Workflow\V2\Support\RuntimeObjectFactory;
use Workflow\V2\TaskWatchdog;

final class V2ActivityTimeoutTest extends TestCase
{
    public function testScheduleToStartDeadlineStoredOnScheduling(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        // Create the workflow infrastructure directly to control activity options.
        [$run, $execution] = $this->createPendingActivity(
            instanceId: 'act-timeout-sts-store-1',
            scheduleDeadlineAt: $startedAt->copy()
                ->addSeconds(30),
        );

        $this->assertNotNull($execution->schedule_deadline_at);
        $this->assertEquals(
            $startedAt->copy()
                ->addSeconds(30)
                ->toIso8601String(),
            $execution->schedule_deadline_at->toIso8601String(),
        );

        Carbon::setTestNow();
    }

    public function testScheduleToStartTimeoutEnforcedByWatchdog(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask] = $this->createPendingActivity(
            instanceId: 'act-timeout-sts-enforce-1',
            scheduleDeadlineAt: $startedAt->copy()
                ->addSeconds(30),
        );

        // Activity is pending — not yet claimed.
        $this->assertSame(ActivityStatus::Pending, $execution->status);

        // Advance past the schedule-to-start deadline.
        Carbon::setTestNow($startedAt->copy()->addSeconds(60));

        $result = ActivityTimeoutEnforcer::enforce($execution->id);
        $this->assertTrue($result['enforced']);

        $execution->refresh();
        $this->assertSame(ActivityStatus::Failed, $execution->status);
        $this->assertNotNull($execution->closed_at);

        $activityTask->refresh();
        $this->assertSame(TaskStatus::Cancelled, $activityTask->status);

        // Verify history event.
        $timedOutEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityTimedOut->value)
            ->firstOrFail();

        $this->assertSame('schedule_to_start', $timedOutEvent->payload['timeout_kind']);
        $this->assertSame(FailureCategory::Timeout->value, $timedOutEvent->payload['failure_category']);
        $this->assertSame($execution->id, $timedOutEvent->payload['activity_execution_id']);

        // Verify failure row.
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $run->id)
            ->where('source_id', $execution->id)
            ->firstOrFail();

        $this->assertSame(FailureCategory::Timeout->value, $failure->failure_category->value);
        $this->assertSame('timeout', $failure->propagation_kind);
        $this->assertStringContainsString('schedule-to-start deadline expired', $failure->message);

        // Verify a workflow task was created to wake the workflow.
        $resumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->first();
        $this->assertNotNull($resumeTask);

        Carbon::setTestNow();
    }

    public function testScheduleToStartTimeoutUsesHistoryProjectionRoleBinding(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution] = $this->createPendingActivity(
            instanceId: 'act-timeout-history-role-1',
            scheduleDeadlineAt: $startedAt->copy()
                ->addSeconds(30),
        );

        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);
        Carbon::setTestNow($startedAt->copy()->addSeconds(60));

        $result = ActivityTimeoutEnforcer::enforce($execution->id);

        $this->assertTrue($result['enforced']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);

        Carbon::setTestNow();
    }

    public function testStartToCloseDeadlineStoredOnClaim(): void
    {
        \Workflow\V2\WorkflowStub::fake();

        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask] = $this->createPendingActivity(
            instanceId: 'act-timeout-stc-store-1',
            retryPolicy: [
                'snapshot_version' => 1,
                'max_attempts' => 3,
                'backoff_seconds' => [1],
                'start_to_close_timeout' => 60,
                'schedule_to_start_timeout' => null,
            ],
        );

        // Claim the activity task.
        $claimResult = \Workflow\V2\Support\ActivityTaskClaimer::claimDetailed($activityTask->id);
        $this->assertNotNull($claimResult['claim']);

        $execution->refresh();
        $this->assertSame(ActivityStatus::Running, $execution->status);
        $this->assertNotNull($execution->close_deadline_at);
        $this->assertEquals(
            $startedAt->copy()
                ->addSeconds(60)
                ->toIso8601String(),
            $execution->close_deadline_at->toIso8601String(),
        );

        Carbon::setTestNow();
    }

    public function testStartToCloseTimeoutEnforcedByWatchdog(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask, $attempt] = $this->createRunningActivity(
            instanceId: 'act-timeout-stc-enforce-1',
            closeDeadlineAt: $startedAt->copy()
                ->addSeconds(60),
        );

        $this->assertSame(ActivityStatus::Running, $execution->status);

        // Advance past the start-to-close deadline.
        Carbon::setTestNow($startedAt->copy()->addSeconds(120));

        $result = ActivityTimeoutEnforcer::enforce($execution->id);
        $this->assertTrue($result['enforced']);

        $execution->refresh();
        $this->assertSame(ActivityStatus::Failed, $execution->status);

        $attempt->refresh();
        $this->assertSame(ActivityAttemptStatus::Failed, $attempt->status);

        $activityViews = RunActivityView::activitiesForRun(
            $run->fresh(['historyEvents', 'activityExecutions.attempts'])
        );
        $this->assertSame(ActivityAttemptStatus::Expired->value, $activityViews[0]['attempts'][0]['status']);
        $this->assertSame('attempt_expired', $activityViews[0]['attempts'][0]['stop_reason']);

        $activityTask->refresh();
        $this->assertSame(TaskStatus::Cancelled, $activityTask->status);

        // Verify history event.
        $timedOutEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityTimedOut->value)
            ->firstOrFail();

        $this->assertSame('start_to_close', $timedOutEvent->payload['timeout_kind']);
        $this->assertSame(FailureCategory::Timeout->value, $timedOutEvent->payload['failure_category']);

        // Verify failure row.
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $run->id)
            ->where('source_id', $execution->id)
            ->firstOrFail();

        $this->assertSame(FailureCategory::Timeout->value, $failure->failure_category->value);
        $this->assertStringContainsString('start-to-close deadline expired', $failure->message);

        Carbon::setTestNow();
    }

    public function testActivityTimeoutRetriesWhenAttemptsRemain(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask, $attempt] = $this->createRunningActivity(
            instanceId: 'act-timeout-retry-1',
            closeDeadlineAt: $startedAt->copy()
                ->addSeconds(30),
            maxAttempts: 3,
        );

        // Advance past deadline.
        Carbon::setTestNow($startedAt->copy()->addSeconds(60));

        $result = ActivityTimeoutEnforcer::enforce($execution->id);
        $this->assertTrue($result['enforced']);
        $this->assertNotNull($result['next_task']);

        $execution->refresh();
        // When retrying, the execution should go back to pending.
        $this->assertSame(ActivityStatus::Pending, $execution->status);

        // Verify a retry task was created (activity task, not workflow).
        $retryTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Ready->value)
            ->whereKeyNot($activityTask->id)
            ->first();
        $this->assertNotNull($retryTask);
        $this->assertSame($execution->id, $retryTask->payload['activity_execution_id']);
        $this->assertSame('start_to_close', $retryTask->payload['timeout_kind']);

        // Verify retry history event.
        $retryEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityRetryScheduled->value)
            ->firstOrFail();
        $this->assertSame('start_to_close', $retryEvent->payload['timeout_kind']);

        Carbon::setTestNow();
    }

    public function testExpiredExecutionIdsFindsCorrectCandidates(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        // Create a pending activity with expired schedule deadline.
        [$run1, $execution1] = $this->createPendingActivity(
            instanceId: 'act-timeout-find-1',
            scheduleDeadlineAt: $startedAt->copy()
                ->subSeconds(10),
        );

        // Create a running activity with expired close deadline.
        [$run2, $execution2] = $this->createRunningActivity(
            instanceId: 'act-timeout-find-2',
            closeDeadlineAt: $startedAt->copy()
                ->subSeconds(10),
        );

        // Create a pending activity with future deadline (should NOT be found).
        [$run3, $execution3] = $this->createPendingActivity(
            instanceId: 'act-timeout-find-3',
            scheduleDeadlineAt: $startedAt->copy()
                ->addSeconds(300),
        );

        $expiredIds = ActivityTimeoutEnforcer::expiredExecutionIds();

        $this->assertContains($execution1->id, $expiredIds);
        $this->assertContains($execution2->id, $expiredIds);
        $this->assertNotContains($execution3->id, $expiredIds);

        Carbon::setTestNow();
    }

    public function testExpiredExecutionIdsFindsSameSecondMicrosecondDeadlines(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00.100000');
        Carbon::setTestNow($startedAt);

        [, $startToCloseExecution] = $this->createRunningActivity(
            instanceId: 'act-timeout-find-same-second-stc-1',
            closeDeadlineAt: Carbon::parse('2026-01-15 10:00:01.100000'),
        );

        [, $scheduleToCloseExecution] = $this->createPendingActivity(
            instanceId: 'act-timeout-find-same-second-s2c-1',
        );
        $scheduleToCloseExecution->forceFill([
            'schedule_to_close_deadline_at' => Carbon::parse('2026-01-15 10:00:01.200000'),
        ])->save();

        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:01.700000'));

        $expiredIds = ActivityTimeoutEnforcer::expiredExecutionIds();

        $this->assertContains($startToCloseExecution->id, $expiredIds);
        $this->assertContains($scheduleToCloseExecution->id, $expiredIds);

        Carbon::setTestNow();
    }

    public function testWatchdogPassDetectsAndEnforcesActivityTimeouts(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask] = $this->createPendingActivity(
            instanceId: 'act-timeout-watchdog-1',
            scheduleDeadlineAt: $startedAt->copy()
                ->subSeconds(10),
        );

        $report = TaskWatchdog::runPass();

        $this->assertGreaterThanOrEqual(1, $report['activity_timeout_candidates']);
        $this->assertGreaterThanOrEqual(1, $report['activity_timeouts_enforced']);

        $execution->refresh();
        $this->assertSame(ActivityStatus::Failed, $execution->status);

        Carbon::setTestNow();
    }

    public function testFailureSnapshotsIncludeActivityTimedOut(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask] = $this->createPendingActivity(
            instanceId: 'act-timeout-snapshots-1',
            scheduleDeadlineAt: $startedAt->copy()
                ->addSeconds(30),
        );

        Carbon::setTestNow($startedAt->copy()->addSeconds(60));

        ActivityTimeoutEnforcer::enforce($execution->id);

        $run = $run->fresh(['failures', 'historyEvents']);
        $snapshots = FailureSnapshots::forRun($run);

        $this->assertNotEmpty($snapshots);

        $timeoutSnapshot = collect($snapshots)
            ->first(
                static fn (array $s): bool => ($s['failure_category'] ?? null) === 'timeout'
                    && ($s['source_kind'] ?? null) === 'activity_execution',
            );

        $this->assertNotNull($timeoutSnapshot);
        $this->assertSame($execution->id, $timeoutSnapshot['source_id'] ?? null);

        Carbon::setTestNow();
    }

    public function testNoEnforcementWhenDeadlineNotExpired(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution] = $this->createPendingActivity(
            instanceId: 'act-timeout-no-enforce-1',
            scheduleDeadlineAt: $startedAt->copy()
                ->addSeconds(300),
        );

        $result = ActivityTimeoutEnforcer::enforce($execution->id);
        $this->assertFalse($result['enforced']);
        $this->assertSame('no_deadline_expired', $result['reason']);

        $execution->refresh();
        $this->assertSame(ActivityStatus::Pending, $execution->status);

        Carbon::setTestNow();
    }

    public function testNoEnforcementWhenRunAlreadyTerminal(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution] = $this->createPendingActivity(
            instanceId: 'act-timeout-terminal-1',
            scheduleDeadlineAt: $startedAt->copy()
                ->subSeconds(10),
        );

        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_at' => now(),
        ])->save();

        $result = ActivityTimeoutEnforcer::enforce($execution->id);
        $this->assertFalse($result['enforced']);
        $this->assertSame('run_already_terminal', $result['reason']);

        Carbon::setTestNow();
    }

    public function testScheduleToCloseTimeoutEnforced(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        // Create a running activity with schedule-to-close deadline.
        [$run, $execution, $activityTask, $attempt] = $this->createRunningActivity(
            instanceId: 'act-timeout-s2c-enforce-1',
            closeDeadlineAt: $startedAt->copy()
                ->addSeconds(120),
            maxAttempts: 3,
        );

        // Set the schedule-to-close deadline on the execution.
        $execution->forceFill([
            'schedule_to_close_deadline_at' => $startedAt->copy()
                ->addSeconds(60),
        ])->save();

        // Advance past the schedule-to-close deadline but before close deadline.
        Carbon::setTestNow($startedAt->copy()->addSeconds(90));

        $result = ActivityTimeoutEnforcer::enforce($execution->id);
        $this->assertTrue($result['enforced']);

        $execution->refresh();
        // Schedule-to-close is always terminal — no retries.
        $this->assertSame(ActivityStatus::Failed, $execution->status);

        $timedOutEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityTimedOut->value)
            ->firstOrFail();

        $this->assertSame('schedule_to_close', $timedOutEvent->payload['timeout_kind']);
        $this->assertSame(FailureCategory::Timeout->value, $timedOutEvent->payload['failure_category']);
        $this->assertNotNull($timedOutEvent->payload['schedule_to_close_deadline_at']);

        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $run->id)
            ->where('source_id', $execution->id)
            ->firstOrFail();

        $this->assertSame(FailureCategory::Timeout->value, $failure->failure_category->value);
        $this->assertStringContainsString('schedule-to-close deadline expired', $failure->message);

        Carbon::setTestNow();
    }

    public function testScheduleToCloseDoesNotRetryEvenWhenAttemptsRemain(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask, $attempt] = $this->createRunningActivity(
            instanceId: 'act-timeout-s2c-no-retry-1',
            closeDeadlineAt: $startedAt->copy()
                ->addSeconds(300),
            maxAttempts: 10,
        );

        $execution->forceFill([
            'schedule_to_close_deadline_at' => $startedAt->copy()
                ->addSeconds(30),
        ])->save();

        Carbon::setTestNow($startedAt->copy()->addSeconds(60));

        $result = ActivityTimeoutEnforcer::enforce($execution->id);
        $this->assertTrue($result['enforced']);

        $execution->refresh();
        // Should be terminal, NOT retried.
        $this->assertSame(ActivityStatus::Failed, $execution->status);

        // No retry task should be created.
        $retryTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Ready->value)
            ->whereKeyNot($activityTask->id)
            ->first();
        $this->assertNull($retryTask);

        // A workflow resume task should be created.
        $resumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->first();
        $this->assertNotNull($resumeTask);

        Carbon::setTestNow();
    }

    public function testScheduleToCloseDetectedOnPendingActivity(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask] = $this->createPendingActivity(instanceId: 'act-timeout-s2c-pending-1');

        $execution->forceFill([
            'schedule_to_close_deadline_at' => $startedAt->copy()
                ->addSeconds(30),
        ])->save();

        Carbon::setTestNow($startedAt->copy()->addSeconds(60));

        $expiredIds = ActivityTimeoutEnforcer::expiredExecutionIds();
        $this->assertContains($execution->id, $expiredIds);

        $result = ActivityTimeoutEnforcer::enforce($execution->id);
        $this->assertTrue($result['enforced']);

        $execution->refresh();
        $this->assertSame(ActivityStatus::Failed, $execution->status);

        $timedOutEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityTimedOut->value)
            ->firstOrFail();

        $this->assertSame('schedule_to_close', $timedOutEvent->payload['timeout_kind']);

        Carbon::setTestNow();
    }

    public function testHeartbeatTimeoutEnforced(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask, $attempt] = $this->createRunningActivity(
            instanceId: 'act-timeout-hb-enforce-1',
            closeDeadlineAt: $startedAt->copy()
                ->addSeconds(300),
        );

        // Set heartbeat deadline.
        $execution->forceFill([
            'heartbeat_deadline_at' => $startedAt->copy()
                ->addSeconds(30),
            'last_heartbeat_at' => $startedAt,
        ])->save();

        // Advance past the heartbeat deadline.
        Carbon::setTestNow($startedAt->copy()->addSeconds(60));

        $result = ActivityTimeoutEnforcer::enforce($execution->id);
        $this->assertTrue($result['enforced']);

        $execution->refresh();
        $this->assertSame(ActivityStatus::Failed, $execution->status);

        $timedOutEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityTimedOut->value)
            ->firstOrFail();

        $this->assertSame('heartbeat', $timedOutEvent->payload['timeout_kind']);
        $this->assertSame(FailureCategory::Timeout->value, $timedOutEvent->payload['failure_category']);
        $this->assertNotNull($timedOutEvent->payload['heartbeat_deadline_at']);

        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $run->id)
            ->where('source_id', $execution->id)
            ->firstOrFail();

        $this->assertStringContainsString('heartbeat deadline expired', $failure->message);
        $this->assertStringContainsString('last heartbeat:', $failure->message);

        /** @var ActivityTaskBridge $bridge */
        $bridge = app(ActivityTaskBridge::class);
        $heartbeat = $bridge->heartbeat($attempt->id);
        $completion = $bridge->complete($attempt->id, 'too late');
        $failure = $bridge->fail($attempt->id, 'also too late');

        $this->assertFalse($heartbeat['can_continue']);
        $this->assertSame('attempt_closed', $heartbeat['reason']);
        $this->assertFalse($completion['recorded']);
        $this->assertSame('stale_attempt', $completion['reason']);
        $this->assertFalse($failure['recorded']);
        $this->assertSame('stale_attempt', $failure['reason']);

        Carbon::setTestNow();
    }

    public function testAcceptedHeartbeatFencesExpiredScannerSnapshot(): void
    {
        \Workflow\V2\WorkflowStub::fake();

        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask] = $this->createPendingActivity(
            instanceId: 'act-timeout-hb-race-1',
            retryPolicy: [
                'snapshot_version' => 1,
                'max_attempts' => 1,
                'backoff_seconds' => [],
                'start_to_close_timeout' => 120,
                'schedule_to_start_timeout' => null,
                'schedule_to_close_timeout' => null,
                'heartbeat_timeout' => 10,
            ],
        );

        /** @var ActivityTaskBridge $bridge */
        $bridge = app(ActivityTaskBridge::class);
        $claim = $bridge->claim($activityTask->id, 'heartbeat-race-worker');

        $this->assertIsArray($claim);
        $this->assertSame(
            $startedAt->copy()
                ->addSeconds(10)
                ->toIso8601String(),
            $execution->fresh()
                ->heartbeat_deadline_at?->toIso8601String(),
        );

        Carbon::setTestNow($startedAt->copy()->addSeconds(11));

        $expiredSnapshot = ActivityTimeoutEnforcer::expiredExecutionIds();
        $this->assertContains($execution->id, $expiredSnapshot);

        $heartbeat = $bridge->heartbeat($claim['activity_attempt_id']);
        $this->assertTrue($heartbeat['can_continue']);
        $this->assertTrue($heartbeat['heartbeat_recorded']);

        $execution->refresh();
        $this->assertSame(
            $startedAt->copy()
                ->addSeconds(21)
                ->toIso8601String(),
            $execution->heartbeat_deadline_at?->toIso8601String(),
        );

        $result = ActivityTimeoutEnforcer::enforce($execution->id);

        $this->assertFalse($result['enforced']);
        $this->assertSame('no_deadline_expired', $result['reason']);
        $this->assertSame(ActivityStatus::Running, $execution->fresh()->status);
        $this->assertSame(
            0,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ActivityTimedOut->value)
                ->count(),
        );

        Carbon::setTestNow();
    }

    public function testAcceptedEmbeddedHeartbeatFencesExpiredScannerSnapshot(): void
    {
        \Workflow\V2\WorkflowStub::fake();

        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask] = $this->createPendingActivity(
            instanceId: 'act-timeout-embedded-hb-race-1',
            retryPolicy: [
                'snapshot_version' => 1,
                'max_attempts' => 1,
                'backoff_seconds' => [],
                'start_to_close_timeout' => 120,
                'schedule_to_start_timeout' => null,
                'schedule_to_close_timeout' => null,
                'heartbeat_timeout' => 10,
            ],
        );

        /** @var ActivityTaskBridge $bridge */
        $bridge = app(ActivityTaskBridge::class);
        $claim = $bridge->claim($activityTask->id, 'embedded-heartbeat-race-worker');

        $this->assertIsArray($claim);
        /** @var ActivityAttempt $attempt */
        $attempt = ActivityAttempt::query()->findOrFail($claim['activity_attempt_id']);
        $previousLeaseExpiry = $attempt->lease_expires_at;
        $this->assertNotNull($previousLeaseExpiry);

        Carbon::setTestNow($startedAt->copy()->addSeconds(11));

        $expiredSnapshot = ActivityTimeoutEnforcer::expiredExecutionIds();
        $this->assertContains($execution->id, $expiredSnapshot);

        $activity = RuntimeObjectFactory::activity(
            TestGreetingActivity::class,
            $execution->fresh(),
            $run->fresh(),
            $activityTask->id,
        );
        $activity->heartbeat([
            'message' => 'still working',
        ]);

        $execution->refresh();
        $attempt->refresh();
        $this->assertSame(
            $startedAt->copy()
                ->addSeconds(21)
                ->toIso8601String(),
            $execution->heartbeat_deadline_at?->toIso8601String(),
        );
        $this->assertSame(now()->toIso8601String(), $attempt->last_heartbeat_at?->toIso8601String());
        $this->assertTrue($attempt->lease_expires_at?->gt($previousLeaseExpiry) ?? false);
        $this->assertSame(
            1,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded->value)
                ->count(),
        );

        $result = ActivityTimeoutEnforcer::enforce($execution->id);

        $this->assertFalse($result['enforced']);
        $this->assertSame('no_deadline_expired', $result['reason']);
        $this->assertSame(ActivityStatus::Running, $execution->fresh()->status);
        $this->assertSame(
            0,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ActivityTimedOut->value)
                ->count(),
        );

        Carbon::setTestNow();
    }

    public function testEmbeddedHeartbeatAndTimeoutEnforcementUseOneConcurrentLockOrder(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('A database with row-level locking is required.');
        }

        if (
            ! function_exists('pcntl_fork')
            || ! function_exists('posix_kill')
            || ! function_exists('stream_socket_pair')
        ) {
            $this->markTestSkipped('Process control and local sockets are required for concurrency coverage.');
        }

        self::stopWorkers();
        \Workflow\V2\WorkflowStub::fake();

        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask] = $this->createPendingActivity(
            instanceId: 'act-timeout-embedded-hb-concurrent-1',
            retryPolicy: [
                'snapshot_version' => 1,
                'max_attempts' => 1,
                'backoff_seconds' => [],
                'start_to_close_timeout' => 120,
                'schedule_to_start_timeout' => null,
                'schedule_to_close_timeout' => null,
                'heartbeat_timeout' => 10,
            ],
        );

        /** @var ActivityTaskBridge $bridge */
        $bridge = app(ActivityTaskBridge::class);
        $claim = $bridge->claim($activityTask->id, 'embedded-heartbeat-concurrent-worker');

        $this->assertIsArray($claim);
        $attemptId = $claim['activity_attempt_id'];
        $this->assertIsString($attemptId);

        Carbon::setTestNow($startedAt->copy()->addSeconds(11));
        $expiredSnapshot = ActivityTimeoutEnforcer::expiredExecutionIds();
        $this->assertContains($execution->id, $expiredSnapshot);

        $children = [];
        $lockReleased = false;

        // Fork before opening the coordinating transaction so no child
        // inherits its database socket or transaction state.
        DB::purge();

        try {
            $children['enforcer'] = $this->forkDatabaseOperation(
                static function () use ($execution): array {
                    $result = ActivityTimeoutEnforcer::enforce($execution->id);

                    return [
                        'enforced' => $result['enforced'],
                        'reason' => $result['reason'],
                    ];
                },
            );
            $this->awaitDatabaseOperationReady($children['enforcer']);

            $children['heartbeat'] = $this->forkDatabaseOperation(
                static function () use ($execution, $run, $activityTask): array {
                    $activity = RuntimeObjectFactory::activity(
                        TestGreetingActivity::class,
                        ActivityExecution::query()->findOrFail($execution->id),
                        WorkflowRun::query()->findOrFail($run->id),
                        $activityTask->id,
                    );
                    $activity->heartbeat([
                        'message' => 'concurrent work',
                    ]);

                    return [
                        'completed' => true,
                    ];
                },
            );
            $this->awaitDatabaseOperationReady($children['heartbeat']);

            DB::reconnect();
            DB::beginTransaction();
            ActivityAttempt::query()
                ->lockForUpdate()
                ->findOrFail($attemptId);

            $this->releaseDatabaseOperation($children['enforcer']);

            // Let enforcement queue on the canonical first row before the
            // embedded heartbeat enters the same lock queue.
            usleep(150_000);

            $this->releaseDatabaseOperation($children['heartbeat']);
            usleep(150_000);
            DB::commit();
            $lockReleased = true;

            $enforcerResult = $this->awaitDatabaseOperation($children['enforcer']);
            $heartbeatResult = $this->awaitDatabaseOperation($children['heartbeat']);
            $children = [];
        } finally {
            if (! $lockReleased && DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($children as $child) {
                $this->terminateDatabaseOperation($child);
            }

            Carbon::setTestNow();
        }

        $this->assertTrue($heartbeatResult['ok'], $heartbeatResult['error'] ?? 'Embedded heartbeat failed.');
        $this->assertSame([
            'completed' => true,
        ], $heartbeatResult['result']);
        $this->assertTrue($enforcerResult['ok'], $enforcerResult['error'] ?? 'Timeout enforcement failed.');
        $this->assertContains(
            $enforcerResult['result'],
            [
                [
                    'enforced' => true,
                    'reason' => null,
                ],
                [
                    'enforced' => false,
                    'reason' => 'no_deadline_expired',
                ],
            ],
        );

        $heartbeatEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded->value)
            ->count();
        $timeoutEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityTimedOut->value)
            ->count();

        $this->assertSame(1, $heartbeatEvents + $timeoutEvents);
        $this->assertSame(
            $heartbeatEvents === 1 ? ActivityStatus::Running : ActivityStatus::Failed,
            $execution->fresh()
                ->status,
        );
    }

    public function testTimeoutEnforcementSkipsClosedCurrentAttempt(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask, $attempt] = $this->createRunningActivity(
            instanceId: 'act-timeout-closed-attempt-1',
            closeDeadlineAt: $startedAt->copy()
                ->addSeconds(300),
        );

        $execution->forceFill([
            'heartbeat_deadline_at' => $startedAt->copy()
                ->subSecond(),
        ])->save();
        $attempt->forceFill([
            'status' => ActivityAttemptStatus::Completed,
            'lease_expires_at' => null,
            'closed_at' => $startedAt,
        ])->save();

        $result = ActivityTimeoutEnforcer::enforce($execution->id);

        $this->assertFalse($result['enforced']);
        $this->assertSame('current_attempt_not_running', $result['reason']);
        $this->assertSame(ActivityStatus::Running, $execution->fresh()->status);
        $this->assertSame(TaskStatus::Leased, $activityTask->fresh()->status);
        $this->assertSame(
            0,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ActivityTimedOut->value)
                ->count(),
        );

        Carbon::setTestNow();
    }

    public function testHeartbeatTimeoutRetriesWhenAttemptsRemain(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask, $attempt] = $this->createRunningActivity(
            instanceId: 'act-timeout-hb-retry-1',
            closeDeadlineAt: $startedAt->copy()
                ->addSeconds(300),
            maxAttempts: 3,
        );

        $execution->forceFill([
            'heartbeat_deadline_at' => $startedAt->copy()
                ->addSeconds(30),
        ])->save();

        Carbon::setTestNow($startedAt->copy()->addSeconds(60));

        $result = ActivityTimeoutEnforcer::enforce($execution->id);
        $this->assertTrue($result['enforced']);
        $this->assertNotNull($result['next_task']);

        $execution->refresh();
        $this->assertSame(ActivityStatus::Pending, $execution->status);
        // heartbeat_deadline_at should be cleared on retry.
        $this->assertNull($execution->heartbeat_deadline_at);

        $retryTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Ready->value)
            ->whereKeyNot($activityTask->id)
            ->first();
        $this->assertNotNull($retryTask);
        $this->assertSame('heartbeat', $retryTask->payload['timeout_kind']);

        Carbon::setTestNow();
    }

    public function testHeartbeatDeadlineSetOnClaim(): void
    {
        \Workflow\V2\WorkflowStub::fake();

        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask] = $this->createPendingActivity(
            instanceId: 'act-timeout-hb-claim-1',
            retryPolicy: [
                'snapshot_version' => 1,
                'max_attempts' => 3,
                'backoff_seconds' => [1],
                'start_to_close_timeout' => 120,
                'schedule_to_start_timeout' => null,
                'schedule_to_close_timeout' => null,
                'heartbeat_timeout' => 15,
            ],
        );

        $claimResult = \Workflow\V2\Support\ActivityTaskClaimer::claimDetailed($activityTask->id);
        $this->assertNotNull($claimResult['claim']);

        $execution->refresh();
        $this->assertSame(ActivityStatus::Running, $execution->status);
        $this->assertNotNull($execution->heartbeat_deadline_at);
        $this->assertEquals(
            $startedAt->copy()
                ->addSeconds(15)
                ->toIso8601String(),
            $execution->heartbeat_deadline_at->toIso8601String(),
        );

        Carbon::setTestNow();
    }

    public function testExpiredExecutionIdsFindsHeartbeatAndScheduleToClose(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        // Running activity with expired heartbeat deadline.
        [$run1, $execution1] = $this->createRunningActivity(instanceId: 'act-timeout-find-hb-1');
        $execution1->forceFill([
            'heartbeat_deadline_at' => $startedAt->copy()
                ->subSeconds(10),
        ])->save();

        // Pending activity with expired schedule-to-close deadline.
        [$run2, $execution2] = $this->createPendingActivity(instanceId: 'act-timeout-find-s2c-1');
        $execution2->forceFill([
            'schedule_to_close_deadline_at' => $startedAt->copy()
                ->subSeconds(10),
        ])->save();

        // Running activity with future heartbeat deadline (should NOT be found).
        [$run3, $execution3] = $this->createRunningActivity(instanceId: 'act-timeout-find-hb-future-1');
        $execution3->forceFill([
            'heartbeat_deadline_at' => $startedAt->copy()
                ->addSeconds(300),
        ])->save();

        $expiredIds = ActivityTimeoutEnforcer::expiredExecutionIds();

        $this->assertContains($execution1->id, $expiredIds);
        $this->assertContains($execution2->id, $expiredIds);
        $this->assertNotContains($execution3->id, $expiredIds);

        Carbon::setTestNow();
    }

    public function testScheduleToStartRetryResetsScheduleDeadline(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        $scheduleToStartTimeout = 30;

        [$run, $execution, $activityTask] = $this->createPendingActivity(
            instanceId: 'act-timeout-sts-retry-reset-1',
            scheduleDeadlineAt: $startedAt->copy()
                ->addSeconds($scheduleToStartTimeout),
            retryPolicy: [
                'snapshot_version' => 1,
                'max_attempts' => 3,
                'backoff_seconds' => [5],
                'start_to_close_timeout' => 60,
                'schedule_to_start_timeout' => $scheduleToStartTimeout,
            ],
        );

        // Advance past the schedule-to-start deadline.
        Carbon::setTestNow($startedAt->copy()->addSeconds(60));

        $result = ActivityTimeoutEnforcer::enforce($execution->id);
        $this->assertTrue($result['enforced']);
        $this->assertNotNull($result['next_task']);

        $execution->refresh();
        $this->assertSame(ActivityStatus::Pending, $execution->status);

        // The schedule_deadline_at should be reset relative to the retry available_at,
        // not left at the old expired value.
        $this->assertNotNull($execution->schedule_deadline_at);

        // The retry task has a 5-second backoff, so available_at = now + 5s.
        $retryAvailableAt = now()
            ->copy()
            ->addSeconds(5);
        $expectedDeadline = $retryAvailableAt->copy()
            ->addSeconds($scheduleToStartTimeout);

        $this->assertEquals(
            $expectedDeadline->toIso8601String(),
            $execution->schedule_deadline_at->toIso8601String(),
        );

        Carbon::setTestNow();
    }

    public function testScheduleToStartRetryClearsDeadlineWhenNoTimeoutConfigured(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        // Activity has a schedule_deadline_at set manually but no schedule_to_start_timeout in policy.
        [$run, $execution, $activityTask, $attempt] = $this->createRunningActivity(
            instanceId: 'act-timeout-stc-retry-clear-1',
            closeDeadlineAt: $startedAt->copy()
                ->addSeconds(30),
            maxAttempts: 3,
        );

        // Set an old schedule_deadline_at that would have been set at scheduling time.
        $execution->forceFill([
            'schedule_deadline_at' => $startedAt->copy()
                ->addSeconds(10),
        ])->save();

        // Advance past the start-to-close deadline.
        Carbon::setTestNow($startedAt->copy()->addSeconds(60));

        $result = ActivityTimeoutEnforcer::enforce($execution->id);
        $this->assertTrue($result['enforced']);
        $this->assertNotNull($result['next_task']);

        $execution->refresh();
        $this->assertSame(ActivityStatus::Pending, $execution->status);

        // No schedule_to_start_timeout in policy, so deadline should be cleared.
        $this->assertNull($execution->schedule_deadline_at);

        Carbon::setTestNow();
    }

    /**
     * @return array{0: WorkflowRun, 1: ActivityExecution, 2: WorkflowTask}
     */
    private function createPendingActivity(
        string $instanceId,
        ?\Carbon\CarbonInterface $scheduleDeadlineAt = null,
        ?array $retryPolicy = null,
    ): array {
        $now = now();

        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => $now,
            'started_at' => $now,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => null,
            'queue' => null,
            'started_at' => $now,
            'last_progress_at' => $now,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => 'test-greeting-activity',
            'status' => ActivityStatus::Pending->value,
            'attempt_count' => 0,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => null,
            'queue' => null,
            'schedule_deadline_at' => $scheduleDeadlineAt,
            'retry_policy' => $retryPolicy ?? [
                'snapshot_version' => 1,
                'max_attempts' => 1,
                'backoff_seconds' => [],
                'start_to_close_timeout' => null,
                'schedule_to_start_timeout' => null,
            ],
        ]);

        $activityTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $now,
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => null,
            'queue' => null,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => 1,
        ]);

        return [$run, $execution, $activityTask];
    }

    /**
     * @return array{0: WorkflowRun, 1: ActivityExecution, 2: WorkflowTask, 3: ActivityAttempt}
     */
    private function createRunningActivity(
        string $instanceId,
        ?\Carbon\CarbonInterface $closeDeadlineAt = null,
        int $maxAttempts = 1,
    ): array {
        $now = now();

        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => $now,
            'started_at' => $now,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => null,
            'queue' => null,
            'started_at' => $now,
            'last_progress_at' => $now,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $attemptId = (string) \Illuminate\Support\Str::ulid();

        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => 'test-greeting-activity',
            'status' => ActivityStatus::Running->value,
            'attempt_count' => 1,
            'current_attempt_id' => $attemptId,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => null,
            'queue' => null,
            'started_at' => $now,
            'close_deadline_at' => $closeDeadlineAt,
            'retry_policy' => [
                'snapshot_version' => 1,
                'max_attempts' => $maxAttempts,
                'backoff_seconds' => [1],
                'start_to_close_timeout' => 60,
                'schedule_to_start_timeout' => null,
            ],
        ]);

        $activityTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => $now,
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => null,
            'queue' => null,
            'leased_at' => $now,
            'lease_expires_at' => $now->copy()
                ->addMinutes(5),
        ]);

        $attempt = ActivityAttempt::query()->create([
            'id' => $attemptId,
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $execution->id,
            'workflow_task_id' => $activityTask->id,
            'attempt_number' => 1,
            'status' => ActivityAttemptStatus::Running->value,
            'lease_owner' => $activityTask->id,
            'started_at' => $now,
            'lease_expires_at' => $now->copy()
                ->addMinutes(5),
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => 1,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, [
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attemptId,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => 1,
            'attempt_number' => 1,
        ], $activityTask);

        return [$run, $execution, $activityTask, $attempt];
    }

    /**
     * @param callable(): array<string, mixed> $operation
     * @return array{pid: int, socket: resource}
     */
    private function forkDatabaseOperation(callable $operation): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'Unable to fork a database operation.');

        if ($pid === 0) {
            fclose($sockets[0]);
            $payload = null;

            try {
                DB::reconnect();
                fwrite($sockets[1], json_encode([
                    'ready' => true,
                ], JSON_THROW_ON_ERROR) . PHP_EOL);

                if (fgets($sockets[1]) !== 'go' . PHP_EOL) {
                    throw new \RuntimeException('Concurrent database operation received an invalid release signal.');
                }

                $payload = [
                    'ok' => true,
                    'result' => $operation(),
                ];
            } catch (Throwable $throwable) {
                $payload = [
                    'ok' => false,
                    'error' => $throwable::class . ': ' . $throwable->getMessage(),
                ];
            }

            fwrite($sockets[1], json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL);
            fclose($sockets[1]);
            exit($payload['ok'] ? 0 : 1);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 5);

        return [
            'pid' => $pid,
            'socket' => $sockets[0],
        ];
    }

    /**
     * @param array{pid: int, socket: resource} $child
     */
    private function awaitDatabaseOperationReady(array $child): void
    {
        $line = fgets($child['socket']);
        $this->assertIsString($line, 'A concurrent database operation did not become ready.');
        $this->assertSame([
            'ready' => true,
        ], json_decode($line, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{pid: int, socket: resource} $child
     */
    private function releaseDatabaseOperation(array $child): void
    {
        $this->assertSame(3, fwrite($child['socket'], 'go' . PHP_EOL));
    }

    /**
     * @param array{pid: int, socket: resource} $child
     * @return array{ok: bool, result?: array<string, mixed>, error?: string}
     */
    private function awaitDatabaseOperation(array $child): array
    {
        $deadline = microtime(true) + 10;
        $status = 0;

        do {
            $waitedPid = pcntl_waitpid($child['pid'], $status, WNOHANG);

            if ($waitedPid === $child['pid']) {
                break;
            }

            if ($waitedPid === -1) {
                $this->fail('Unable to wait for a concurrent database operation.');
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        if ($waitedPid !== $child['pid']) {
            $this->terminateDatabaseOperation($child);
            $this->fail('A concurrent database operation did not finish within 10 seconds.');
        }

        $line = fgets($child['socket']);
        fclose($child['socket']);

        $this->assertIsString($line, 'A concurrent database operation returned no result.');
        $payload = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array{pid: int, socket: resource} $child
     */
    private function terminateDatabaseOperation(array $child): void
    {
        @posix_kill($child['pid'], SIGKILL);
        @pcntl_waitpid($child['pid'], $status);

        if (is_resource($child['socket'])) {
            fclose($child['socket']);
        }
    }
}
