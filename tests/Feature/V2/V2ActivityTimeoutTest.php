<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
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
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\Support\FailureSnapshots;
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
            scheduleDeadlineAt: $startedAt->copy()->addSeconds(30),
        );

        $this->assertNotNull($execution->schedule_deadline_at);
        $this->assertEquals(
            $startedAt->copy()->addSeconds(30)->toIso8601String(),
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
            scheduleDeadlineAt: $startedAt->copy()->addSeconds(30),
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
            $startedAt->copy()->addSeconds(60)->toIso8601String(),
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
            closeDeadlineAt: $startedAt->copy()->addSeconds(60),
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
            closeDeadlineAt: $startedAt->copy()->addSeconds(30),
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
            scheduleDeadlineAt: $startedAt->copy()->subSeconds(10),
        );

        // Create a running activity with expired close deadline.
        [$run2, $execution2] = $this->createRunningActivity(
            instanceId: 'act-timeout-find-2',
            closeDeadlineAt: $startedAt->copy()->subSeconds(10),
        );

        // Create a pending activity with future deadline (should NOT be found).
        [$run3, $execution3] = $this->createPendingActivity(
            instanceId: 'act-timeout-find-3',
            scheduleDeadlineAt: $startedAt->copy()->addSeconds(300),
        );

        $expiredIds = ActivityTimeoutEnforcer::expiredExecutionIds();

        $this->assertContains($execution1->id, $expiredIds);
        $this->assertContains($execution2->id, $expiredIds);
        $this->assertNotContains($execution3->id, $expiredIds);

        Carbon::setTestNow();
    }

    public function testWatchdogPassDetectsAndEnforcesActivityTimeouts(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask] = $this->createPendingActivity(
            instanceId: 'act-timeout-watchdog-1',
            scheduleDeadlineAt: $startedAt->copy()->subSeconds(10),
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
            scheduleDeadlineAt: $startedAt->copy()->addSeconds(30),
        );

        Carbon::setTestNow($startedAt->copy()->addSeconds(60));

        ActivityTimeoutEnforcer::enforce($execution->id);

        $run = $run->fresh(['failures', 'historyEvents']);
        $snapshots = FailureSnapshots::forRun($run);

        $this->assertNotEmpty($snapshots);

        $timeoutSnapshot = collect($snapshots)->first(
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
            scheduleDeadlineAt: $startedAt->copy()->addSeconds(300),
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
            scheduleDeadlineAt: $startedAt->copy()->subSeconds(10),
        );

        $run->forceFill(['status' => RunStatus::Completed, 'closed_at' => now()])->save();

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
            closeDeadlineAt: $startedAt->copy()->addSeconds(120),
            maxAttempts: 3,
        );

        // Set the schedule-to-close deadline on the execution.
        $execution->forceFill([
            'schedule_to_close_deadline_at' => $startedAt->copy()->addSeconds(60),
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
            closeDeadlineAt: $startedAt->copy()->addSeconds(300),
            maxAttempts: 10,
        );

        $execution->forceFill([
            'schedule_to_close_deadline_at' => $startedAt->copy()->addSeconds(30),
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

        [$run, $execution, $activityTask] = $this->createPendingActivity(
            instanceId: 'act-timeout-s2c-pending-1',
        );

        $execution->forceFill([
            'schedule_to_close_deadline_at' => $startedAt->copy()->addSeconds(30),
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
            closeDeadlineAt: $startedAt->copy()->addSeconds(300),
        );

        // Set heartbeat deadline.
        $execution->forceFill([
            'heartbeat_deadline_at' => $startedAt->copy()->addSeconds(30),
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

        Carbon::setTestNow();
    }

    public function testHeartbeatTimeoutRetriesWhenAttemptsRemain(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        [$run, $execution, $activityTask, $attempt] = $this->createRunningActivity(
            instanceId: 'act-timeout-hb-retry-1',
            closeDeadlineAt: $startedAt->copy()->addSeconds(300),
            maxAttempts: 3,
        );

        $execution->forceFill([
            'heartbeat_deadline_at' => $startedAt->copy()->addSeconds(30),
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
            $startedAt->copy()->addSeconds(15)->toIso8601String(),
            $execution->heartbeat_deadline_at->toIso8601String(),
        );

        Carbon::setTestNow();
    }

    public function testExpiredExecutionIdsFindsHeartbeatAndScheduleToClose(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        // Running activity with expired heartbeat deadline.
        [$run1, $execution1] = $this->createRunningActivity(
            instanceId: 'act-timeout-find-hb-1',
        );
        $execution1->forceFill([
            'heartbeat_deadline_at' => $startedAt->copy()->subSeconds(10),
        ])->save();

        // Pending activity with expired schedule-to-close deadline.
        [$run2, $execution2] = $this->createPendingActivity(
            instanceId: 'act-timeout-find-s2c-1',
        );
        $execution2->forceFill([
            'schedule_to_close_deadline_at' => $startedAt->copy()->subSeconds(10),
        ])->save();

        // Running activity with future heartbeat deadline (should NOT be found).
        [$run3, $execution3] = $this->createRunningActivity(
            instanceId: 'act-timeout-find-hb-future-1',
        );
        $execution3->forceFill([
            'heartbeat_deadline_at' => $startedAt->copy()->addSeconds(300),
        ])->save();

        $expiredIds = ActivityTimeoutEnforcer::expiredExecutionIds();

        $this->assertContains($execution1->id, $expiredIds);
        $this->assertContains($execution2->id, $expiredIds);
        $this->assertNotContains($execution3->id, $expiredIds);

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

        $instance->forceFill(['current_run_id' => $run->id])->save();

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

        $instance->forceFill(['current_run_id' => $run->id])->save();

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
            'lease_expires_at' => $now->copy()->addMinutes(5),
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
            'lease_expires_at' => $now->copy()->addMinutes(5),
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
}
