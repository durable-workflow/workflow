<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\CooperativeWorkflowTaskBridge;
use Workflow\V2\Contracts\PreparedLocalActivityTaskBridge;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\PortableLocalActivityControl;
use Workflow\V2\WorkflowStub;

final class V2PortableLocalActivityControlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-02T04:38:00.000000Z');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        config()
            ->set('workflows.v2.workflow_task_lease_seconds', 10);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testPollingAnActivePreparedCallbackDoesNotWriteOrRenew(): void
    {
        [$run, $task, $attempt, $execution] = $this->prepared();
        $before = $this->snapshot($run, $task, $attempt, $execution);
        Carbon::setTestNow(now()->addSecond());
        $status = $this->control($attempt, renew: false);
        $this->assertTrue($status['active']);
        $this->assertFalse($status['stop_required']);
        $this->assertFalse($status['renewed']);
        $this->assertSame(7, $status['workflow_task_attempt']);
        $this->assertSame($task->id, $status['workflow_task_id']);
        $this->assertSame($before, $this->snapshot($run, $task, $attempt, $execution));
    }

    public function testSupervisorRenewalKeepsBothLeasesAliveWithoutAnApplicationHeartbeat(): void
    {
        [$run, $task, $attempt, $execution] = $this->prepared();
        $executionBefore = $execution->getAttributes();
        $heartbeat = $attempt->last_heartbeat_at->toISOString();
        $original = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)->sole()->payload;
        $this->assertSame($task->id, WorkflowRunSummary::query()->findOrFail($run->id)->next_task_id);
        foreach ([4, 8, 8, 8] as $seconds) {
            Carbon::setTestNow(now()->addSeconds($seconds));
            $status = $this->control($attempt);
            $this->assertTrue($status['active']);
            $this->assertTrue($status['renewed']);
            $this->assertSame(now()->addSeconds(10)->toISOString(), $status['lease_expires_at']);
            $this->assertSame($status['lease_expires_at'], $status['workflow_lease_expires_at']);
            $this->assertSame(
                $status['lease_expires_at'],
                WorkflowRunSummary::query()->findOrFail($run->id)->next_task_lease_expires_at->toISOString()
            );
            $this->assertSame($heartbeat, $attempt->refresh()->last_heartbeat_at->toISOString());
            $this->assertSame($executionBefore, $execution->refresh()->getAttributes());
        }
        $this->assertSame(2, $run->historyEvents()->count());
        $this->assertSame(
            $original,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityStarted)->sole()->payload
        );
        $this->assertSame(7, $task->refresh()->attempt_count);
        $this->assertSame(1, $attempt->refresh()->attempt_number);
    }

    public function testSupervisorPollingCannotResetAnApplicationHeartbeatTimeout(): void
    {
        [$run, $task, $attempt, $execution] = $this->prepared([
            'heartbeat_timeout' => 3,
        ]);
        Carbon::setTestNow(now()->addSeconds(2));
        $this->assertTrue($this->control($attempt)['renewed']);
        $before = $this->snapshot($run, $task, $attempt, $execution);
        Carbon::setTestNow(now()->addSecond());
        $status = $this->control($attempt);
        $this->assertSame('local_activity_deadline_expired', $status['reason']);
        $this->assertTrue($status['stop_required']);
        $this->assertFalse($status['renewed']);
        $this->assertSame($before, $this->snapshot($run, $task, $attempt, $execution));
    }

    public function testSupervisorRenewalDoesNotMoveTheOriginalTotalDeadline(): void
    {
        [$run, $task, $attempt, $execution] = $this->prepared([
            'start_to_close_timeout' => null,
            'schedule_to_close_timeout' => 3,
        ]);
        Carbon::setTestNow(now()->addSeconds(2));
        $this->assertTrue($this->control($attempt)['renewed']);
        $before = $this->snapshot($run, $task, $attempt, $execution);
        Carbon::setTestNow(now()->addSecond());
        $status = $this->control($attempt);
        $this->assertSame('local_activity_deadline_expired', $status['reason']);
        $this->assertTrue($status['stop_required']);
        $this->assertFalse($status['renewed']);
        $this->assertSame($before, $this->snapshot($run, $task, $attempt, $execution));
    }

    public function testFailedAttemptWriteRollsBackTheHostingClaimRenewal(): void
    {
        [$run, $task, $attempt, $execution] = $this->prepared();
        $before = $this->snapshot($run, $task, $attempt, $execution);
        Carbon::setTestNow(now()->addSecond());
        $summaryBefore = WorkflowRunSummary::query()->findOrFail($run->id)->getAttributes();
        $event = 'eloquent.saving: ' . ActivityAttempt::class;
        Event::listen($event, static function (ActivityAttempt $saved) use ($attempt): void {
            if ($saved->id === $attempt->id && $saved->isDirty('lease_expires_at')) {
                throw new RuntimeException('synthetic attempt write failure');
            }
        });
        try {
            $this->control($attempt);
            $this->fail('The synthetic write failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic attempt write failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame($before, $this->snapshot($run, $task, $attempt, $execution));
        $this->assertSame($summaryBefore, WorkflowRunSummary::query()->findOrFail($run->id)->getAttributes());
    }

    public function testCancellationPollingFencesWithoutRenewingOrInventingAStopReceipt(): void
    {
        [$run, $task, $attempt] = $this->prepared();
        $result = WorkflowStub::loadRun($run->id)->requestCancellation('stop both', 30);
        $this->assertTrue($result->accepted());
        $context = $result->cancellationContext()
            ->toArray();
        $taskBefore = $task->refresh()
            ->getAttributes();
        $status = $this->control($attempt);
        $this->assertSame('cancellation_requested', $status['reason']);
        $this->assertSame($context, $status['cancellation_request']);
        $this->assertFalse($status['renewed']);
        $this->assertTrue($status['stop_required']);
        $this->assertTrue($status['fenced']);
        $this->assertSame(ActivityAttemptStatus::Cancelled, $attempt->refresh()->status);
        $this->assertNull($attempt->lease_expires_at);
        $this->assertSame($taskBefore, $task->refresh()->getAttributes());
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
        );
        $again = $this->control($attempt);
        $this->assertSame($status['cancellation_history_event_id'], $again['cancellation_history_event_id']);
        $this->assertSame(1, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCancelled)->count());
        $this->assertSame(
            $context,
            WorkflowStub::loadRun($run->id)->requestCancellation('another reason', 600)->cancellationContext()
                ->toArray()
        );
    }

    public function testOriginalSupervisorCanObserveAndAcknowledgeCancellationAfterTakeover(): void
    {
        [$run, $task, $attempt] = $this->prepared();
        $request = WorkflowStub::loadRun($run->id)->requestCancellation('stop', 30)->cancellationContext();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'replacement-worker',
            'attempt_count' => 8,
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $before = $task->refresh()
            ->getAttributes();
        $status = $this->control($attempt);
        $this->assertSame('cancellation_requested', $status['reason']);
        $this->assertSame(7, $status['workflow_task_attempt']);
        $this->assertSame($request->toArray(), $status['cancellation_request']);
        $this->assertFalse($status['renewed']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $ack = $this->bridge()
            ->acknowledgeLocalActivityCancellation($attempt->id, 'original-worker', $request->requestId, 7, '1.20');
        $this->assertTrue($ack['acknowledged']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $receipt = WorkflowHistoryEvent::query()->findOrFail($ack['history_event_id']);
        $this->assertSame($request->rootRequestId, $receipt->payload['root_request_id']);
        $this->assertSame($request->deadline()->toISOString(), $receipt->payload['cleanup_deadline_at']);
        $this->assertFalse($receipt->payload['received_after_deadline']);
    }

    #[DataProvider('localCancellationPolicies')]
    public function testLocalPolicyDistinguishesImmediateDeliveryFromJoinedCallbackWaiting(?string $policy): void
    {
        [$run, $task, $attempt] = $this->prepared($policy === null ? [] : [
            'cancellation_policy' => $policy,
        ]);
        $context = WorkflowStub::loadRun($run->id)->requestCancellation('stop local work', 30)->cancellationContext();
        $workflowBridge = app(CooperativeWorkflowTaskBridge::class);
        $reply = $workflowBridge->deliverCancellation($task->id, $context->requestId, 1, 'local_activity');
        $this->assertSame(ActivityAttemptStatus::Cancelled, $attempt->refresh()->status);
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
        );
        if ($policy === 'wait_cancellation_completed') {
            $this->assertFalse($reply['delivered']);
            $this->assertTrue($reply['claim_released']);
            $this->assertSame('cancellation_waiting_for_activity', $reply['reason']);
            $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
            $this->assertSame(
                0,
                $run->historyEvents()
                    ->where('event_type', HistoryEventType::CooperativeCancellationDelivered)->count()
            );
            $wrong = $this->bridge()
                ->acknowledgeLocalActivityCancellation($attempt->id, 'another-worker', $context->requestId, 7, '1.20');
            $this->assertFalse($wrong['acknowledged']);
            $this->assertSame(0, $run->tasks()->where('status', TaskStatus::Ready)->count());
            Carbon::setTestNow(now()->addSecond());
            $stopped = $this->bridge()
                ->acknowledgeLocalActivityCancellation($attempt->id, 'original-worker', $context->requestId, 7, '1.20');
            $this->assertTrue($stopped['acknowledged']);
            $receipt = WorkflowHistoryEvent::query()->findOrFail($stopped['history_event_id']);
            $this->assertSame($context->rootRequestId, $receipt->payload['root_request_id']);
            $this->assertSame($context->deadline()->toISOString(), $receipt->payload['cleanup_deadline_at']);
            $successor = $run->tasks()
                ->where('status', TaskStatus::Ready)->sole();
            $successor->forceFill([
                'status' => TaskStatus::Leased,
                'lease_owner' => 'replacement-worker',
                'attempt_count' => 1,
                'lease_expires_at' => now()
                    ->addSeconds(5),
            ])->save();
            $wrongBoundary = $workflowBridge->deliverCancellation(
                $successor->id,
                $context->requestId,
                2,
                'local_activity'
            );
            $this->assertFalse($wrongBoundary['delivered']);
            $reply = $workflowBridge->deliverCancellation($successor->id, $context->requestId, 1, 'local_activity');
            $this->assertSame(
                1,
                $run->historyEvents()
                    ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
            );
        }
        $this->assertTrue($reply['delivered']);
        $this->assertSame(1, $reply['sequence']);
        $this->assertSame('local_activity', $reply['call_kind']);
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CooperativeCancellationDelivered)->count()
        );
        $this->assertSame(
            $context->toArray(),
            WorkflowStub::loadRun($run->id)->requestCancellation('duplicate', 300)->cancellationContext()->toArray()
        );
        $this->assertSame(
            $context->deadline()
                ->toISOString(),
            $run->refresh()
                ->cancellation_deadline_at->toISOString()
        );
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded)->count()
        );
    }

    public static function localCancellationPolicies(): iterable
    {
        yield 'historical default' => [null];
        yield 'try cancellation' => ['try_cancel'];
        yield 'wait for stop receipt' => ['wait_cancellation_completed'];
    }

    public function testLocalPolicyLateStopReceiptCannotResumeCleanupAfterTheOriginalDeadline(): void
    {
        [$run, $task, $attempt] = $this->prepared([
            'cancellation_policy' => 'wait_cancellation_completed',
        ]);
        $context = WorkflowStub::loadRun($run->id)->requestCancellation(
            'bounded local wait',
            30
        )->cancellationContext();
        $delivery = app(CooperativeWorkflowTaskBridge::class)
            ->deliverCancellation($task->id, $context->requestId, 1, 'local_activity');
        $this->assertFalse($delivery['delivered']);
        $this->assertTrue($delivery['claim_released']);
        Carbon::setTestNow($context->deadline());
        $stopped = $this->bridge()
            ->acknowledgeLocalActivityCancellation($attempt->id, 'original-worker', $context->requestId, 7, '1.20');
        $this->assertTrue($stopped['acknowledged']);
        $receipt = WorkflowHistoryEvent::query()->findOrFail($stopped['history_event_id']);
        $this->assertTrue($receipt->payload['received_after_deadline']);
        $this->assertSame($context->deadline()->toISOString(), $receipt->payload['cleanup_deadline_at']);
        $this->assertSame(0, $run->tasks()->where('status', TaskStatus::Ready)->count());
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CooperativeCancellationDelivered)->count()
        );
    }

    public function testADescendantCallbackStartedAfterTheRootRequestStillStopsOnLocalPropagation(): void
    {
        $parent = WorkflowStub::make(TestGreetingWorkflow::class, 'root');
        $parent->start('Taylor');
        $root = $parent->requestCancellation('stop tree', 30)
            ->cancellationContext();
        Carbon::setTestNow(now()->addSecond());
        [$run, $task, $attempt] = $this->prepared();
        WorkflowLink::query()->create([
            'parent_workflow_instance_id' => $parent->run()
->workflow_instance_id,
            'parent_workflow_run_id' => $parent->runId(),
            'child_workflow_instance_id' => $run->workflow_instance_id,
            'child_workflow_run_id' => $run->id,
            'sequence' => 1,
            'link_type' => 'child_workflow',
            'is_primary_parent' => true,
        ]);
        Carbon::setTestNow(now()->addSecond());
        $request = WorkflowStub::loadRun($run->id)->attemptRequestCancellationFromParent($parent->runId());
        $this->assertTrue($request->accepted());
        $context = $request->cancellationContext();
        $before = $task->refresh()
            ->getAttributes();
        $status = $this->control($attempt);
        $this->assertTrue($status['fenced']);
        $this->assertTrue($status['stop_required']);
        $this->assertSame('cancellation_requested', $status['reason']);
        $this->assertSame($root->rootRequestId, $status['cancellation_request']['root_request_id']);
        $this->assertNotSame($root->requestId, $context->requestId);
        $this->assertSame($root->deadline()->toISOString(), $status['cancellation_request']['cleanup_deadline_at']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertTrue(
            $this->bridge()
                ->acknowledgeLocalActivityCancellation(
                    $attempt->id,
                    'original-worker',
                    $context->requestId,
                    7,
                    '1.20'
                )['acknowledged']
        );
    }

    public function testAnExpiredCancellationBudgetStillReturnsItsOriginalIdentityWithoutRenewal(): void
    {
        [$run, $task, $attempt] = $this->prepared();
        $context = WorkflowStub::loadRun($run->id)->requestCancellation('stop', 30)->cancellationContext()->toArray();
        $before = $task->refresh()
            ->getAttributes();
        Carbon::setTestNow(now()->addSeconds(30));
        $status = $this->control($attempt);
        $this->assertSame('cancellation_deadline_expired', $status['reason']);
        $this->assertTrue($status['stop_required']);
        $this->assertFalse($status['renewed']);
        $this->assertSame($context, $status['cancellation_request']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame($context['cleanup_deadline_at'], $run->refresh()->cancellation_deadline_at->toISOString());
    }

    #[DataProvider('invalidClaims')]
    public function testInvalidOriginalClaimsCannotRenewOrReadCanonicalMetadata(string $owner, int $epoch): void
    {
        [$run, $task, $attempt, $execution] = $this->prepared();
        $before = $this->snapshot($run, $task, $attempt, $execution);
        $status = $this->bridge()
            ->controlLocalActivity($attempt->id, $owner, $epoch, true, '1.20');
        $this->assertTrue($status['stop_required']);
        $this->assertFalse($status['renewed']);
        $this->assertNull($status['activity_execution_id']);
        $this->assertSame($before, $this->snapshot($run, $task, $attempt, $execution));
    }

    public static function invalidClaims(): iterable
    {
        yield 'another owner' => ['someone-else', 7];
        yield 'local attempt number instead of workflow epoch' => ['original-worker', 1];
        yield 'new workflow epoch' => ['original-worker', 8];
        yield 'empty owner' => ['', 7];
        yield 'invalid epoch' => ['original-worker', 0];
    }

    public function testPublishedDefaultRefusesControlBeforeAnyMutation(): void
    {
        [$run, $task, $attempt, $execution] = $this->prepared();
        $before = $this->snapshot($run, $task, $attempt, $execution);
        $status = $this->bridge()
            ->controlLocalActivity($attempt->id, 'original-worker', 7, true);
        $this->assertSame('local_activity_control_requires_protocol_1_20', $status['reason']);
        $this->assertFalse($status['renewed']);
        $this->assertSame($before, $this->snapshot($run, $task, $attempt, $execution));
        $this->assertSame(
            'activity_attempt_not_found',
            PortableLocalActivityControl::poll('unknown', 'original-worker', 7, true, '1.20')['reason']
        );
    }

    #[DataProvider('lostAuthority')]
    public function testLostAuthorityCannotBeRenewed(string $kind, string $reason): void
    {
        [$run, $task, $attempt, $execution] = $this->prepared();
        match ($kind) {
            'takeover' => $task->forceFill([
                'lease_owner' => 'replacement-worker',
                'attempt_count' => 8,
            ])->save(),
            'same owner new epoch' => $task->forceFill([
                'attempt_count' => 8,
            ])->save(),
            'workflow expiry' => $task->forceFill([
                'lease_expires_at' => now(),
            ])->save(),
            'local expiry' => $attempt->forceFill([
                'lease_expires_at' => now(),
            ])->save(),
            'closed run' => $run->forceFill([
                'status' => RunStatus::Completed,
            ])->save(),
            'run deadline' => $run->forceFill([
                'run_deadline_at' => now(),
            ])->save(),
            'execution deadline' => $run->forceFill([
                'execution_deadline_at' => now(),
            ])->save(),
            'per attempt deadline' => $execution->forceFill([
                'close_deadline_at' => now(),
            ])->save(),
            'counter changed' => $execution->forceFill([
                'attempt_count' => 2,
            ])->save(),
            'missing start' => $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityStarted)->delete(),
            'total budget changed' => $execution->forceFill([
                'schedule_to_close_deadline_at' => now()
                    ->addMinutes(3),
            ])->save(),
        };
        $before = $this->snapshot($run, $task, $attempt, $execution);
        $status = $this->control($attempt);
        $this->assertSame($reason, $status['reason']);
        $this->assertTrue($status['stop_required']);
        $this->assertFalse($status['renewed']);
        $this->assertSame($before, $this->snapshot($run, $task, $attempt, $execution));
    }

    public static function lostAuthority(): iterable
    {
        yield 'takeover' => ['takeover', 'workflow_claim_mismatch'];
        yield 'same owner new epoch' => ['same owner new epoch', 'workflow_claim_mismatch'];
        yield 'workflow expiry' => ['workflow expiry', 'workflow_claim_expired'];
        yield 'local expiry despite live hosting claim' => ['local expiry', 'local_activity_lease_expired'];
        yield 'closed run' => ['closed run', 'run_closed'];
        yield 'run deadline' => ['run deadline', 'run_deadline_expired'];
        yield 'execution deadline' => ['execution deadline', 'run_deadline_expired'];
        yield 'per attempt deadline' => ['per attempt deadline', 'local_activity_deadline_expired'];
        yield 'counter changed' => ['counter changed', 'stale_activity_attempt'];
        yield 'missing start' => ['missing start', 'local_activity_preparation_mismatch'];
        yield 'total budget changed' => ['total budget changed', 'local_activity_preparation_mismatch'];
    }

    public function testAStartAfterCancellationCannotAuthorizeAControlFence(): void
    {
        [$run, $task, $attempt, $execution] = $this->prepared();
        WorkflowStub::loadRun($run->id)->requestCancellation('stop', 30);
        $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)->update([
                'sequence' => 100,
            ]);
        $before = $this->snapshot($run, $task, $attempt, $execution);
        $this->assertSame('local_activity_preparation_mismatch', $this->control($attempt)['reason']);
        $this->assertSame($before, $this->snapshot($run, $task, $attempt, $execution));
    }

    public function testMalformedCancellationContextCannotAuthorizeARenewalOrFence(): void
    {
        [$run, $task, $attempt, $execution] = $this->prepared();
        WorkflowStub::loadRun($run->id)->requestCancellation('stop', 30);
        $request = $run->historyEvents()
            ->where('event_type', HistoryEventType::CooperativeCancellationRequested)->sole();
        $payload = $request->payload;
        $payload['cancellation']['schema'] = 'unsupported';
        $request->forceFill([
            'payload' => $payload,
        ])->save();
        $before = $this->snapshot($run, $task, $attempt, $execution);
        $this->assertSame('cancellation_context_not_recorded', $this->control($attempt)['reason']);
        $this->assertSame($before, $this->snapshot($run, $task, $attempt, $execution));
    }

    private function bridge(): PreparedLocalActivityTaskBridge
    {
        return app(PreparedLocalActivityTaskBridge::class);
    }

    private function control(ActivityAttempt $attempt, bool $renew = true): array
    {
        return $this->bridge()
            ->controlLocalActivity($attempt->id, 'original-worker', 7, $renew, '1.20');
    }

    private function snapshot(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityAttempt $attempt,
        ActivityExecution $execution
    ): array {
        return [$run->refresh()->getAttributes(), $task->refresh()->getAttributes(),
            $attempt->refresh()
                ->getAttributes(), $execution->refresh()
                ->getAttributes(), $run->historyEvents()
                ->count()];
    }

    private function prepared(array $options = []): array
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'portable-parent',
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
        $first = $this->bridge()
            ->prepareLocalActivity($task->id, 'original-worker', 7, 1, 'sdk-local-attempt', [
                'type' => 'record_local_activity',
                'activity_type' => 'php-local-opaque',
                'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
                'payload_codec' => 'avro',
                'start_to_close_timeout' => 60,
                'schedule_to_close_timeout' => 120,
                ...$options,
            ], '1.20');
        $this->assertTrue($first['prepared']);
        return [$run->refresh(), $task->refresh(), ActivityAttempt::query()->findOrFail($first['activity_attempt_id']),
            ActivityExecution::query()->findOrFail($first['activity_execution_id'])];
    }
}
