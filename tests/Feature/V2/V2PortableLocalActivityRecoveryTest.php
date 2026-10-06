<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\CooperativeWorkflowTaskBridge;
use Workflow\V2\Contracts\PreparedLocalActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
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
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\LocalActivityCall;
use Workflow\V2\Support\LocalActivityExecutor;
use Workflow\V2\Support\PortableLocalActivityPreparation;
use Workflow\V2\WorkflowStub;

final class V2PortableLocalActivityRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-02T04:20:00.000000Z');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testColdRecoveryFencesTheOldAttemptAndPreparesARealRetryUnderTheOriginalBudget(): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedCall();
        $reply = $this->recover($task, $descriptor);
        $this->assertTrue($reply['recovered']);
        $this->assertFalse($reply['duplicate']);
        $this->assertTrue($reply['claim_released']);
        $this->assertSame('unknown', $reply['callback_stop_state']);
        $this->assertSame('ActivityRetryScheduled', $reply['event_type']);
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
        $this->assertNull($task->lease_expires_at);
        $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
        $this->assertSame('cold_replay', $event->payload['retry_reason']);
        $this->assertSame(7, $event->payload['local_recovery']['original_workflow_task_attempt']);
        $this->assertSame(8, $event->payload['local_recovery']['workflow_task_attempt']);
        $this->assertSame('original-worker', $event->payload['local_recovery']['original_lease_owner']);
        $this->assertSame('replacement-worker', $event->payload['local_recovery']['lease_owner']);
        $this->assertSame('expired', $event->payload['activity_attempt']['status']);
        $this->assertSame(8, $event->payload['task']['attempt_count']);
        $this->assertSame(1, $event->payload['activity_attempt']['attempt_number']);
        $timeline = collect(HistoryTimeline::forRun($run->fresh()))->firstWhere('id', $event->id);
        $this->assertSame('unknown', $timeline['local_recovery']['callback_stop_state']);
        $this->assertSame(7, $timeline['local_recovery']['original_workflow_task_attempt']);
        $this->assertSame(8, $timeline['local_recovery']['workflow_task_attempt']);
        $this->assertStringContainsString('Callback stop is unknown', $timeline['summary']);
        $execution = ActivityExecution::query()->findOrFail($first['activity_execution_id']);
        $this->assertSame(ActivityStatus::Pending, $execution->status);
        $this->assertSame(ActivityAttemptStatus::Expired, $execution->attempts()->sole()->status);
        $this->assertSame(1, $execution->attempt_count);
        $this->assertSame(
            $first['schedule_to_close_deadline_at'],
            $execution->schedule_to_close_deadline_at->toISOString()
        );
        $this->assertSame('stale_activity_attempt', $this->lateResult($first)['reason']);
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
        );
        $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Activity)->count());
        $retry = WorkflowTask::query()->findOrFail($reply['created_task_ids'][0]);
        $retry->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'next-worker',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $this->assertSame('local_activity_retry_not_due', $this->prepareRetry($retry, $descriptor)['reason']);
        Carbon::setTestNow(now()->addSeconds(2));
        $second = $this->prepareRetry($retry, $descriptor);
        $this->assertTrue($second['prepared']);
        $this->assertSame(2, $second['attempt_number']);
        $this->assertSame(1, $second['workflow_task_attempt']);
        $this->assertNotSame($first['activity_attempt_id'], $second['activity_attempt_id']);
        $this->assertSame($first['schedule_to_close_deadline_at'], $second['schedule_to_close_deadline_at']);
        $result = $this->bridge()
            ->recordLocalActivityOutcome($second['activity_attempt_id'], 'next-worker', 1, $this->success(), '1.20');
        $this->assertTrue($result['recorded']);
        $replayed = app(LocalActivityExecutor::class)->execute(
            $run->fresh(),
            $retry->fresh(),
            1,
            new LocalActivityCall('python-local-opaque', ['Taylor'])
        );
        $this->assertSame($result['event_id'], $replayed['event']->id);
        $before = $task->refresh()
            ->getAttributes();
        $historyCount = $run->historyEvents()
            ->count();
        $this->assertSame([
            ...$reply,
            'duplicate' => true,
        ], $this->recover($task, $descriptor));
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame($historyCount, $run->historyEvents()->count());
    }

    public function testAnIndependentRecoveryClaimRetiresTheExpiredOriginalTask(): void
    {
        [$run, $original, $first, $descriptor] = $this->reclaimedCall(reclaim: false);
        $task = $original->replicate();
        $task->save();
        $task->forceFill([
            'lease_owner' => 'replacement-worker',
            'attempt_count' => 8,
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $reply = $this->recover($task, $descriptor);
        $this->assertTrue($reply['recovered']);
        $this->assertSame(TaskStatus::Completed, $original->refresh()->status);
        $this->assertNull($original->lease_expires_at);
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
        $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
        $this->assertSame($task->id, $event->workflow_task_id);
        $this->assertSame($original->id, $event->payload['local_recovery']['original_workflow_task_id']);
        $this->assertSame($original->id, $event->payload['activity_attempt']['task_id']);
        $this->assertSame(3, $run->tasks()->count());
        $this->assertSame('stale_activity_attempt', $this->lateResult($first)['reason']);
    }

    public function testResponseLossReturnsItsReceiptAfterTakeoverAndLaterCancellationWithoutRenewal(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall();
        $first = $this->recover($task, $descriptor);
        $task->forceFill([
            'lease_owner' => 'another-worker',
            'attempt_count' => 9,
            'lease_expires_at' => now()
                ->subSecond(),
        ])->save();
        $this->assertTrue(WorkflowStub::loadRun($run->id)->requestCancellation('stop', 30)->accepted());
        $deadline = $run->refresh()
            ->cancellation_deadline_at->toISOString();
        $before = $task->refresh()
            ->getAttributes();
        $historyCount = $run->historyEvents()
            ->count();
        $this->assertSame([
            ...$first,
            'duplicate' => true,
        ], $this->recover($task, $descriptor));
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
        $this->assertSame($historyCount, $run->historyEvents()->count());
    }

    public function testChangedRecoveryContentsCannotRelabelAReceipt(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall();
        $this->assertTrue($this->recover($task, $descriptor)['recovered']);
        $descriptor['schedule_to_close_timeout'] = 60;
        $count = $run->historyEvents()
            ->count();
        $this->assertSame('local_activity_recovery_mismatch', $this->recover($task, $descriptor)['reason']);
        $this->assertSame($count, $run->historyEvents()->count());
    }

    public function testRetryExhaustionRecordsOneReplayableFailureAndRetainsTheReplacementClaim(): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedCall(maxAttempts: 1);
        $before = $task->getAttributes();
        $reply = $this->recover($task, $descriptor);
        $this->assertTrue($reply['recovered']);
        $this->assertSame('ActivityFailed', $reply['event_type']);
        $this->assertFalse($reply['claim_released']);
        $this->assertSame([], $reply['created_task_ids']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame(1, $run->failures()->count());
        $this->assertSame(
            ActivityAttemptStatus::Expired,
            ActivityAttempt::query()->findOrFail($first['activity_attempt_id'])->status
        );
        $this->assertSame([
            ...$reply,
            'duplicate' => true,
        ], $this->recover($task, $descriptor));
        $this->assertSame(1, $run->failures()->count());
        $this->assertSame(1, $run->tasks()->count());
        $replayed = app(LocalActivityExecutor::class)->execute(
            $run->fresh(),
            $task->fresh(),
            1,
            new LocalActivityCall('python-local-opaque', ['Taylor'])
        );
        $this->assertSame($reply['event_id'], $replayed['event']->id);
        $this->assertSame('failed', $replayed['status']);
    }

    public function testTheOriginalTotalDeadlineOverridesColdReplayWithoutAnotherAttempt(): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedCall(totalTimeout: 4);
        $before = $task->getAttributes();
        $reply = $this->recover($task, $descriptor);
        $this->assertSame('ActivityTimedOut', $reply['event_type']);
        $this->assertFalse($reply['claim_released']);
        $this->assertSame([], $reply['created_task_ids']);
        $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
        $this->assertSame('schedule_to_close', $event->payload['timeout_kind']);
        $this->assertSame('unknown', $event->payload['local_recovery']['callback_stop_state']);
        $this->assertSame('expired', $event->payload['activity_attempt']['status']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame(1, $run->tasks()->count());
        $this->assertSame(1, ActivityExecution::query()->findOrFail($first['activity_execution_id'])->attempt_count);
        $this->assertSame([
            ...$reply,
            'duplicate' => true,
        ], $this->recover($task, $descriptor));
    }

    public function testAnExpiredPerAttemptDeadlineUsesTheExistingTimeoutRetryPolicy(): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedCall(startTimeout: 4);
        $reply = $this->recover($task, $descriptor);
        $this->assertSame('ActivityRetryScheduled', $reply['event_type']);
        $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
        $this->assertSame('timeout', $event->payload['retry_reason']);
        $this->assertSame('start_to_close', $event->payload['timeout_kind']);
        $retry = WorkflowTask::query()->findOrFail($reply['created_task_ids'][0]);
        Carbon::setTestNow(now()->addSeconds(2));
        $retry->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'next-worker',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $prepared = $this->prepareRetry($retry, $descriptor);
        $this->assertTrue($prepared['prepared']);
        $this->assertSame($first['schedule_to_close_deadline_at'], $prepared['schedule_to_close_deadline_at']);
    }

    public function testALivePreviousAttemptCannotBeRecoveredEvenAfterTaskOwnershipChanges(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall(elapsed: 3);
        $before = $run->historyEvents()
            ->count();
        $this->assertSame('local_activity_previous_attempt_live', $this->recover($task, $descriptor)['reason']);
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame(TaskStatus::Leased, $task->refresh()->status);
    }

    public function testALiveOriginalHostingClaimCannotBeRecoveredThroughAnotherTask(): void
    {
        [$run, $original, , $descriptor] = $this->reclaimedCall(reclaim: false);
        $task = $original->replicate();
        $task->save();
        $task->forceFill([
            'lease_owner' => 'replacement-worker',
            'attempt_count' => 8,
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $original->forceFill([
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $before = $run->historyEvents()
            ->count();
        $this->assertSame('local_activity_original_claim_live', $this->recover($task, $descriptor)['reason']);
        $this->assertSame($before, $run->historyEvents()->count());
    }

    public function testTheSameClaimEpochCannotTurnAMissedLocalLeaseIntoColdRecovery(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall();
        $task->forceFill([
            'attempt_count' => 7,
        ])->save();
        $reply = PortableLocalActivityPreparation::recover($task->id, 'replacement-worker', 7, 1, $descriptor, '1.20');
        $this->assertSame('local_activity_original_claim_not_reclaimed', $reply['reason']);
        $this->assertSame(2, $run->historyEvents()->count());
    }

    public function testCancellationFencesBeforeLeaseExpiryAndStillRequiresTheOriginalSupervisorsStopReceipt(): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedCall(elapsed: 3);
        $request = WorkflowStub::loadRun($run->id)->requestCancellation('stop', 30);
        $this->assertTrue($request->accepted());
        $deadline = $run->refresh()
            ->cancellation_deadline_at->toISOString();
        $before = $task->getAttributes();
        $reply = $this->recover($task, $descriptor);
        $this->assertFalse($reply['recovered']);
        $this->assertSame('cancellation_requested', $reply['reason']);
        $this->assertTrue($reply['fenced']);
        $this->assertSame($reply, $this->recover($task, $descriptor));
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityRetryScheduled)->count()
        );
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
        );
        $acknowledgement = $this->bridge()
            ->acknowledgeLocalActivityCancellation(
                $first['activity_attempt_id'],
                'original-worker',
                $request->commandId(),
                7,
                '1.20'
            );
        $this->assertTrue($acknowledgement['acknowledged']);
        $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
        $this->assertSame($before, $task->refresh()->getAttributes());
    }

    public function testMissingOriginalStartCannotBeReplacedWithMutableRunningRows(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall();
        $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)->delete();
        $before = $run->historyEvents()
            ->count();
        $this->assertSame('local_activity_preparation_mismatch', $this->recover($task, $descriptor)['reason']);
        $this->assertSame($before, $run->historyEvents()->count());
    }

    public function testChangedDescriptorCannotRecoverAnotherOperation(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall();
        $descriptor['arguments'] = Serializer::serializeWithCodec('avro', ['different']);
        $before = $run->historyEvents()
            ->count();
        $this->assertSame('local_activity_preparation_mismatch', $this->recover($task, $descriptor)['reason']);
        $this->assertSame($before, $run->historyEvents()->count());
    }

    public function testProtocol119CannotEnterThePreparedRecoveryPath(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall();
        $reply = PortableLocalActivityPreparation::recover($task->id, 'replacement-worker', 8, 1, $descriptor, '1.19');
        $this->assertSame('local_activity_recovery_requires_protocol_1_20', $reply['reason']);
        $this->assertSame(2, $run->historyEvents()->count());
    }

    public function testPreparedLocalRoleUsesTheExistingWorkflowBindingWithoutExpandingCustomBridges(): void
    {
        $this->assertSame(app(WorkflowTaskBridge::class), $this->bridge());
        foreach ([WorkflowTaskBridge::class, CooperativeWorkflowTaskBridge::class] as $contract) {
            $custom = \Mockery::mock($contract);
            $this->app->instance(WorkflowTaskBridge::class, $custom);
            $resolved = $this->app->make(PreparedLocalActivityTaskBridge::class);
            $this->assertSame($custom, $resolved);
            $this->assertNotInstanceOf(PreparedLocalActivityTaskBridge::class, $resolved);
        }
    }

    public function testLocalStopAdmissionRejectsProtocol119ThroughTheOptionalRole(): void
    {
        [$run, , $first] = $this->reclaimedCall();
        $before = $run->historyEvents()
            ->count();
        $reply = $this->bridge()
            ->acknowledgeLocalActivityCancellation(
                $first['activity_attempt_id'],
                'original-worker',
                'not-a-recorded-request',
                7,
                '1.19'
            );
        $this->assertFalse($reply['acknowledged']);
        $this->assertSame('local_activity_stop_receipt_requires_protocol_1_20', $reply['reason']);
        $this->assertSame($before, $run->historyEvents()->count());
    }

    private function bridge(): PreparedLocalActivityTaskBridge
    {
        return $this->app->make(PreparedLocalActivityTaskBridge::class);
    }

    /** @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private function recover(WorkflowTask $task, array $descriptor): array
    {
        return $this->bridge()
            ->recoverLocalActivity($task->id, 'replacement-worker', 8, 1, $descriptor, '1.20');
    }

    /** @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private function prepareRetry(WorkflowTask $task, array $descriptor): array
    {
        return $this->bridge()
            ->prepareLocalActivity($task->id, 'next-worker', 1, 1, 'new-local-attempt', $descriptor, '1.20');
    }

    /** @param array<string, mixed> $first
     * @return array<string, mixed>
     */
    private function lateResult(array $first): array
    {
        return $this->bridge()
            ->recordLocalActivityOutcome($first['activity_attempt_id'], 'original-worker', 7, $this->success(), '1.20');
    }

    /**
     * @return array<string, mixed>
     */
    private function success(): array
    {
        return [
            'outcome' => 'completed',
            'payload_codec' => 'avro',
            'result' => Serializer::serializeWithCodec('avro', 'completed'),
        ];
    }

    /**
     * @return array{WorkflowRun, WorkflowTask, array<string, mixed>, array<string, mixed>}
     */
    private function reclaimedCall(
        int $elapsed = 6,
        int $maxAttempts = 3,
        int $totalTimeout = 30,
        int $startTimeout = 20,
        bool $reclaim = true,
    ): array {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'portable-parent',
            'run_count' => 1,
            'started_at' => now()
                ->subMinute(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'portable-parent',
            'status' => RunStatus::Waiting,
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
            'payload_codec' => 'avro',
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Leased,
            'attempt_count' => 7,
            'payload' => [],
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'original-worker',
            'lease_expires_at' => now()
                ->addSeconds(5),
        ])->refresh();
        $descriptor = [
            'type' => 'record_local_activity',
            'activity_type' => 'python-local-opaque',
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
            'payload_codec' => 'avro',
            'start_to_close_timeout' => min($startTimeout, $totalTimeout),
            'schedule_to_close_timeout' => $totalTimeout,
            'retry_policy' => [
                'max_attempts' => $maxAttempts,
                'backoff_seconds' => [2],
            ],
        ];
        $first = $this->bridge()
            ->prepareLocalActivity($task->id, 'original-worker', 7, 1, 'original-local-attempt', $descriptor, '1.20');
        $this->assertTrue($first['prepared']);
        Carbon::setTestNow(now()->addSeconds($elapsed));
        if ($reclaim) {
            $task->forceFill([
                'lease_owner' => 'replacement-worker',
                'attempt_count' => 8,
                'lease_expires_at' => now()
                    ->addMinute(),
            ])->save();
        }
        return [$run->refresh(), $task->refresh(), $first, $descriptor];
    }
}
