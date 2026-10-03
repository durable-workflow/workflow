<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\PreparedLocalActivityGroupTaskBridge;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityCancellationAcknowledgement;
use Workflow\V2\Support\ActivityCancellationCompletion;
use Workflow\V2\Support\ActivityCancellationContext;
use Workflow\V2\Support\CancellationScopeDelivery;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\CancellationScopeRequests;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\Support\PortableLocalActivityPreparation;
use Workflow\V2\Support\ScopedActivityCancellation;
use Workflow\V2\WorkflowStub;

final class V2ScopedActivityCancellationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        Carbon::setTestNow('2026-10-03T00:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('policies')]
    public function testRemoteFencePreservesSiblingAndClaimButDoesNotProveCallbackExit(string $policy, bool $wait): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target, $other] = $this->remotePair($run, $task, $scope, $sibling, $policy);
        $bridge = app(ActivityTaskBridge::class);
        $claim = $bridge->claimStatus($this->activityTask($run, $target)->id, 'activity-owner');
        $this->assertTrue($claim['claimed'], $claim['reason'] ?? '');
        $claimBefore = $task->fresh()
            ->getAttributes();
        $siblingBefore = $other->fresh()
            ->getAttributes();
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $fence = $this->fence($run, $task, $target, $scope);
        $this->assertTrue($fence['fenced']);
        $this->assertSame($wait, $fence['waiting_for_stop']);
        $this->assertSame(ActivityStatus::Cancelled, $target->fresh()->status);
        $this->assertSame(TaskStatus::Cancelled, $this->activityTask($run, $target)->status);
        $this->assertSame($claimBefore, $task->fresh()->getAttributes());
        $this->assertSame($siblingBefore, $other->fresh()->getAttributes());
        $this->assertNull($run->fresh()->cancellation_request_command_id);
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->assertFalse($bridge->status($claim['activity_attempt_id'])['can_continue']);
        $published = $bridge->complete($claim['activity_attempt_id'], Serializer::serializeWithCodec('avro', 'stale'));
        $this->assertFalse($published['recorded']);
        $this->assertFalse(ActivityCancellationCompletion::resolved(
            $run->fresh(),
            $target->id,
            CancellationScopeRequests::context($run, $scope)
        ));
        $wrong = ActivityCancellationAcknowledgement::recordStopped(
            $claim['activity_attempt_id'],
            'different-owner',
            $request->payload['request_id']
        );
        $this->assertFalse($wrong['acknowledged']);
        $ack = ActivityCancellationAcknowledgement::recordStopped(
            $claim['activity_attempt_id'],
            'activity-owner',
            $request->payload['request_id']
        );
        $this->assertTrue($ack['acknowledged'], $ack['reason'] ?? '');
        $this->assertFalse($this->fence($run->fresh(), $task, $target, $scope)['waiting_for_stop']);
        $this->assertSame(
            $fence['history_event_id'],
            $this->fence($run->fresh(), $task, $target, $scope)['history_event_id']
        );
        $this->assertTrue(
            ActivityCancellationAcknowledgement::recordStopped(
                $claim['activity_attempt_id'],
                'activity-owner',
                $request->payload['request_id']
            )['duplicate']
        );
        $this->assertTrue($bridge->claimStatus($this->activityTask($run, $other)->id, 'sibling-owner')['claimed']);
    }

    public static function policies(): iterable
    {
        yield 'try' => [CancellationPolicy::TryCancel->value, false];
        yield 'wait' => [CancellationPolicy::WaitCancellationCompleted->value, true];
    }

    #[DataProvider('policies')]
    public function testDeliveryRequiresCanonicalFenceAndWaitPolicyRequiresOriginalStopProof(
        string $policy,
        bool $wait
    ): void {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target, $other] = $this->remotePair($run, $task, $scope, $sibling, $policy);
        $claim = app(ActivityTaskBridge::class)->claimStatus($this->activityTask($run, $target)->id, 'activity-owner');
        $this->assertTrue($claim['claimed']);
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $this->assertDeliveryRefused($run, $task, $scope, 'cancellation_scope_activity_fence_not_established');
        $this->fence($run, $task, $target, $scope);
        if ($wait) {
            $this->assertDeliveryRefused($run, $task, $scope, 'cancellation_scope_activity_stop_not_acknowledged');
            $this->assertTrue(ActivityCancellationAcknowledgement::recordStopped(
                $claim['activity_attempt_id'],
                'activity-owner',
                $request->payload['request_id']
            )['acknowledged']);
        }
        $before = $task->fresh()
            ->getAttributes();
        $event = CancellationScopeDelivery::record(
            $run->fresh(),
            $task,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            '1.20'
        );
        $this->assertSame($event->id, CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame(ActivityStatus::Pending, $other->fresh()->status);
        $this->assertSame(
            $request->payload['cancellation']['root_context']['cleanup_deadline_at'],
            $event->payload['cancellation']['root_context']['cleanup_deadline_at']
        );
    }

    public function testPendingAdmissionFencePermitsDeliveryWithoutInventingAnOwnerStopReport(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling);
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        $this->fence($run, $task, $target, $scope);
        $event = CancellationScopeDelivery::record(
            $run->fresh(),
            $task,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            '1.20'
        );
        $this->assertSame($event->id, CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
        );
    }

    #[DataProvider('deliveryBoundaries')]
    public function testAllMembersMustHaveStopProofBeforeParallelScopedDelivery(bool $selection): void
    {
        [, $run, $task, , $scope] = $this->tree();
        [$first, $second] = $this->remotePair($run, $task, $scope, $scope, parallel: true);
        $bridge = app(ActivityTaskBridge::class);
        $claims = [];
        foreach ([$first, $second] as $execution) {
            $claims[] = $bridge->claimStatus($this->activityTask($run, $execution)->id, 'activity-owner');
        }
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        foreach ([$first, $second] as $execution) {
            $this->fence($run, $task, $execution, $scope);
        }
        $this->assertTrue(
            ActivityCancellationAcknowledgement::recordStopped(
                $claims[0]['activity_attempt_id'],
                'activity-owner',
                $request->payload['request_id']
            )['acknowledged']
        );
        $before = $run->historyEvents()
            ->count();
        $deliver = static fn () => CancellationScopeDelivery::record(
            $run->fresh(),
            $task,
            $scope,
            $request->payload['request_id'],
            $selection ? 6 : 4,
            $selection ? 'selection_handle' : 'parallel',
            '1.20',
            $selection ? 1 : 2,
            $selection ? 4 : null,
            $selection ? 2 : 1
        );
        try {
            $deliver();
            $this->fail('One remaining callback must prevent parallel delivery.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_activity_stop_not_acknowledged', $error->getMessage());
        }
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertTrue(
            ActivityCancellationAcknowledgement::recordStopped(
                $claims[1]['activity_attempt_id'],
                'activity-owner',
                $request->payload['request_id']
            )['acknowledged']
        );
        $event = $deliver();
        $this->assertSame($event->id, CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
    }

    public static function deliveryBoundaries(): iterable
    {
        yield 'parallel call' => [false];
        yield 'selection handle' => [true];
    }

    public function testALaterStopReportCannotRetroactivelyValidateAnEarlyHistoricalDelivery(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling);
        $claim = app(ActivityTaskBridge::class)->claimStatus($this->activityTask($run, $target)->id, 'activity-owner');
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        $fence = $this->fence($run, $task, $target, $scope);
        // Inject a malformed early marker without going through the public primitive.
        WorkflowHistoryEvent::record($run, HistoryEventType::CancellationScopeDelivered, [
            'schema' => CancellationScopeDelivery::SCHEMA,
            'workflow_run_id' => $run->id,
            'scope_id' => $scope,
            'request_id' => $request->payload['request_id'],
            'cancellation' => $request->payload['cancellation'],
            'sequence' => 4,
            'call_kind' => 'activity',
            'sequence_span' => 1,
            'operation_sequence' => null,
            'operation_sequence_span' => 1,
            'authority_deadline_at' => $fence['cleanup_deadline_at'],
        ], $task);
        $this->assertTrue(
            ActivityCancellationAcknowledgement::recordStopped(
                $claim['activity_attempt_id'],
                'activity-owner',
                $request->payload['request_id']
            )['acknowledged']
        );
        $this->expectExceptionMessage('cancellation_scope_delivery_history_invalid');
        CancellationScopeDelivery::recorded($run->fresh(), $scope);
    }

    public function testMutableProjectionCannotReanimateACanonicallyFencedActivityForDelivery(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling, CancellationPolicy::TryCancel->value);
        CancellationScopeRequests::request($run, $scope, '1.20');
        $this->fence($run, $task, $target, $scope);
        $target->refresh()
            ->forceFill([
                'status' => ActivityStatus::Pending,
            ])->save();
        $this->assertSame(ActivityStatus::Pending, $target->fresh()->status);
        $this->assertDeliveryRefused($run, $task, $scope, 'cancellation_scope_activity_fence_not_established');
    }

    public function testPendingFenceWinsBeforeCallbackAdmissionWithoutInventingStopReceipt(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling);
        CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $fence = $this->fence($run, $task, $target, $scope);
        $this->assertTrue($fence['fenced']);
        $this->assertFalse($fence['waiting_for_stop']);
        $this->assertFalse(
            app(ActivityTaskBridge::class)->claimStatus($this->activityTask($run, $target)->id, 'late-owner')['claimed']
        );
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
        );
    }

    public function testInheritedFenceAndLateReceiptKeepOriginalRootAndBudget(): void
    {
        [, $run, $task, $parent, $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling);
        $claim = app(ActivityTaskBridge::class)->claimStatus($this->activityTask($run, $target)->id, 'activity-owner');
        $root = CancellationScopeRequests::request($run, $parent, '1.20', 30, 'original reason');
        Carbon::setTestNow('2026-10-03T00:00:07Z');
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 300, parentScopeId: $parent);
        $fence = $this->fence($run, $task, $target, $scope);
        $this->assertSame($root->payload['request_id'], $fence['root_request_id']);
        $this->assertSame('2026-10-03T00:00:30.000000Z', $fence['cleanup_deadline_at']);
        Carbon::setTestNow('2026-10-03T00:00:31Z');
        $ack = ActivityCancellationAcknowledgement::recordStopped(
            $claim['activity_attempt_id'],
            'activity-owner',
            $request->payload['request_id']
        );
        $this->assertTrue($ack['acknowledged'], $ack['reason'] ?? '');
        $receipt = WorkflowHistoryEvent::query()->findOrFail($ack['history_event_id']);
        $this->assertTrue($receipt->payload['received_after_deadline']);
        $this->assertSame($root->payload['request_id'], $receipt->payload['root_request_id']);
        $this->assertSame(
            $request->id,
            CancellationScopeRequests::request($run->fresh(), $scope, '1.20', 300, parentScopeId: $parent)->id
        );
        $this->expectExceptionMessage('cancellation_scope_authority_expired');
        $this->fence($run->fresh(), $task, $target, $scope);
    }

    public function testLocalReceiptUsesOriginalOwnerAfterWorkflowClaimReplacementAndRunRequest(): void
    {
        [$workflow, $run, $task, , $scope, $sibling] = $this->tree();
        $commands = [];
        foreach ([$scope, $sibling] as $index => $address) {
            $commands[] = [
                ...$this->localDescriptor($address),
                'type' => 'prepare_local_activity',
                ...ParallelChildGroup::itemMetadata(4, 2, $index, 'mixed'),
            ];
        }
        $group = app(PreparedLocalActivityGroupTaskBridge::class)->checkpointLocalActivityGroup(
            $task->id,
            'scope-owner',
            1,
            'locals',
            4,
            $commands,
            '1.20'
        );
        $this->assertTrue($group['checkpointed'], $group['reason'] ?? '');
        $prepared = PortableLocalActivityPreparation::prepare(
            $task->id,
            'scope-owner',
            1,
            4,
            'local-attempt',
            [
                ...$commands[0],
                'type' => 'record_local_activity',
            ],
            '1.20'
        );
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $target = ActivityExecution::query()->findOrFail($prepared['activity_execution_id']);
        $claimBefore = $task->fresh()
            ->getAttributes();
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $fence = $this->fence($run, $task, $target, $scope);
        $this->assertTrue($fence['waiting_for_stop']);
        $this->assertSame($claimBefore, $task->fresh()->getAttributes());
        $cancelled = WorkflowHistoryEvent::query()->findOrFail($fence['history_event_id']);
        $this->assertSame($task->id, $cancelled->payload['workflow_task_id']);
        $this->assertSame('scope-owner', $cancelled->payload['task']['lease_owner']);
        $this->assertSame(TaskStatus::Leased->value, $cancelled->payload['task']['status']);
        $other = PortableLocalActivityPreparation::prepare(
            $task->id,
            'scope-owner',
            1,
            5,
            'sibling-attempt',
            [
                ...$commands[1],
                'type' => 'record_local_activity',
            ],
            '1.20'
        );
        $this->assertTrue($other['prepared'], $other['reason'] ?? '');
        $task->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
        ])->save();
        $workflow->requestCancellation('later whole run', 10);
        $wrong = ActivityCancellationAcknowledgement::recordLocalStopped(
            $prepared['activity_attempt_id'],
            'replacement',
            $request->payload['request_id'],
            2
        );
        $this->assertFalse($wrong['acknowledged']);
        $ack = ActivityCancellationAcknowledgement::recordLocalStopped(
            $prepared['activity_attempt_id'],
            'scope-owner',
            $request->payload['request_id'],
            1
        );
        $this->assertTrue($ack['acknowledged'], $ack['reason'] ?? '');
        $receipt = WorkflowHistoryEvent::query()->findOrFail($ack['history_event_id']);
        $this->assertSame('scope-owner', $receipt->payload['task']['lease_owner']);
        $this->assertSame(1, $receipt->payload['task']['attempt_count']);
        $this->assertSame($fence['cleanup_deadline_at'], $receipt->payload['cleanup_deadline_at']);
        $this->assertTrue(ActivityCancellationCompletion::resolved($run->fresh(), $target->id));
        $this->assertSame(
            $fence['history_event_id'],
            $this->fence($run->fresh(), $task->fresh(), $target, $scope)['history_event_id']
        );
    }

    public function testLocalCallbackStopProofPrecedesScopedDeliveryWithoutReleasingTheHostingClaim(): void
    {
        [, $run, $task, , $scope] = $this->tree();
        $commands = [];
        foreach ([0, 1] as $index) {
            $commands[] = [
                ...$this->localDescriptor($scope),
                'type' => 'prepare_local_activity',
                ...ParallelChildGroup::itemMetadata(4, 2, $index, 'mixed'),
            ];
        }
        $group = app(PreparedLocalActivityGroupTaskBridge::class)->checkpointLocalActivityGroup(
            $task->id,
            'scope-owner',
            1,
            'locals',
            4,
            $commands,
            '1.20'
        );
        $this->assertTrue($group['checkpointed'], $group['reason'] ?? '');
        $prepared = PortableLocalActivityPreparation::prepare(
            $task->id,
            'scope-owner',
            1,
            4,
            'local-attempt',
            [
                ...$commands[0],
                'type' => 'record_local_activity',
            ],
            '1.20'
        );
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        foreach ($run->activityExecutions()->get() as $execution) {
            $this->fence($run, $task, $execution, $scope);
        }
        $before = $task->fresh()
            ->getAttributes();
        $deliver = static fn () => CancellationScopeDelivery::record(
            $run->fresh(),
            $task,
            $scope,
            $request->payload['request_id'],
            4,
            'parallel',
            '1.20',
            2
        );
        try {
            $deliver();
            $this->fail('A fenced local callback is not proof of callback exit.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_activity_stop_not_acknowledged', $error->getMessage());
        }
        $this->assertTrue(ActivityCancellationAcknowledgement::recordLocalStopped(
            $prepared['activity_attempt_id'],
            'scope-owner',
            $request->payload['request_id'],
            1
        )['acknowledged']);
        $event = $deliver();
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame($event->id, CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
    }

    #[DataProvider('badMetadata')]
    public function testCorruptScopeSnapshotCannotAcknowledgeOrResolveAWait(string $change): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling);
        $claim = app(ActivityTaskBridge::class)->claimStatus($this->activityTask($run, $target)->id, 'activity-owner');
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $fence = $this->fence($run, $task, $target, $scope);
        $event = WorkflowHistoryEvent::query()->findOrFail($fence['history_event_id']);
        $payload = $event->payload;
        if ($change === 'scope') {
            $payload['cancellation_scope']['scope_id'] = $sibling;
        } elseif ($change === 'deadline') {
            $payload['cancellation_scope']['authority_deadline_at'] = '2026-10-03T00:01:00.000000Z';
        } elseif ($change === 'identity') {
            $payload['cancellation_scope']['cancellation']['request_id'] = 'different';
        } elseif ($change === 'request_event_array') {
            $payload['cancellation_scope']['request_history_event_id'] = [$request->id];
        } else {
            $payload['cancellation_scope'] = null;
        }
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $this->assertNull(ActivityCancellationContext::forEvent($run->fresh(), $event->fresh()));
        $this->assertFalse(
            ActivityCancellationAcknowledgement::recordStopped(
                $claim['activity_attempt_id'],
                'activity-owner',
                $request->payload['request_id']
            )['acknowledged']
        );
        $this->assertFalse(
            ActivityCancellationCompletion::resolved($run->fresh(), $target->id, CancellationScopeRequests::context(
                $run,
                $scope
            ))
        );
    }

    public static function badMetadata(): iterable
    {
        foreach (['scope', 'deadline', 'identity', 'null', 'request_event_array'] as $change) {
            yield $change => [$change];
        }
    }

    public function testWrongScopeRefusesWithoutMutatingActivityOrClaim(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling);
        CancellationScopeRequests::request($run, $sibling, '1.20', 30);
        $before = $target->fresh()
            ->getAttributes();
        try {
            $this->fence($run, $task, $target, $sibling);
            $this->fail('Wrong scope must refuse the fence.');
        } catch (LogicException $exception) {
            $this->assertSame('cancellation_scope_activity_membership_mismatch', $exception->getMessage());
        }
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCancelled)->count());
    }

    public function testPendingRetryFencePreservesTheFailedAttemptAndPreventsAnotherAdmission(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling);
        $bridge = app(ActivityTaskBridge::class);
        $claim = $bridge->claimStatus($this->activityTask($run, $target)->id, 'activity-owner');
        $failed = $bridge->fail($claim['activity_attempt_id'], 'retry me');
        $this->assertTrue($failed['recorded'], $failed['reason'] ?? '');
        $this->assertSame(ActivityStatus::Pending, $target->fresh()->status);
        $attempt = ActivityAttempt::query()->findOrFail($claim['activity_attempt_id']);
        $before = $attempt->getAttributes();
        CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $fence = $this->fence($run, $task, $target, $scope);
        $this->assertTrue($fence['fenced']);
        $this->assertFalse($fence['waiting_for_stop']);
        $this->assertSame($before, $attempt->fresh()->getAttributes());
        $this->assertFalse($bridge->claimStatus($failed['next_task_id'], 'retry-owner')['claimed']);
        $this->assertSame(1, $target->attempts()->count());
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
        );
    }

    public function testNaturallyCompletedActivityRetainsResultAndHasNoCancellationFence(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling);
        $bridge = app(ActivityTaskBridge::class);
        $claim = $bridge->claimStatus($this->activityTask($run, $target)->id, 'activity-owner');
        $result = $bridge->complete(
            $claim['activity_attempt_id'],
            Serializer::serializeWithCodec('avro', 'complete'),
            'avro'
        );
        $this->assertTrue($result['recorded'], $result['reason'] ?? '');
        $before = $target->fresh()
            ->getAttributes();
        CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $this->assertFalse($this->fence($run, $task, $target, $scope)['fenced']);
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCancelled)->count());
    }

    public function testBoundedAbandonLeavesTheOriginalActivityAuthorityAndCompletionIntact(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling, 'abandon');
        $bridge = app(ActivityTaskBridge::class);
        $claim = $bridge->claimStatus($this->activityTask($run, $target)->id, 'activity-owner');
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $before = $target->fresh()
            ->getAttributes();
        $fence = $this->fence($run, $task, $target, $scope);
        $this->assertTrue($fence['abandoned']);
        $this->assertFalse($fence['fenced']);
        $this->assertSame($before, $target->fresh()->getAttributes());
        $event = CancellationScopeDelivery::record(
            $run->fresh(),
            $task,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            '1.20'
        );
        $this->assertSame($event->id, CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
        $this->assertTrue($bridge->status($claim['activity_attempt_id'])['can_continue']);
        $this->assertTrue(
            $bridge->complete(
                $claim['activity_attempt_id'],
                Serializer::serializeWithCodec('avro', 'continued'),
                'avro'
            )['recorded']
        );
    }

    #[DataProvider('invalidDeliveryHistory')]
    public function testDeliveryRejectsUnboundedOrContradictoryActivityHistory(string $change, string $reason): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling, 'abandon');
        CancellationScopeRequests::request($run, $scope, '1.20');
        $scheduled = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityScheduled)
            ->where('payload->activity_execution_id', $target->id)
            ->sole();
        $payload = $scheduled->payload;
        if ($change === 'projection_deadline') {
            $target->forceFill([
                'schedule_to_close_deadline_at' => now()
                    ->addSeconds(300),
            ])->save();
        } elseif ($change === 'duplicate') {
            WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
                'sequence' => $target->sequence,
                'activity_execution_id' => $target->id,
                'activity' => $payload['activity'],
            ]);
        } else {
            $payload['activity'][$change === 'unknown_policy' ? 'cancellation_policy'
                : 'schedule_to_close_deadline_at'] = match ($change) {
                    'unknown_policy' => 'unknown',
                    'unbounded' => null,
                    'malformed_deadline' => 'not-a-deadline',
                };
            $scheduled->forceFill([
                'payload' => $payload,
            ])->save();
        }
        $this->assertDeliveryRefused($run, $task, $scope, $reason);
    }

    public static function invalidDeliveryHistory(): iterable
    {
        foreach (['projection_deadline', 'unbounded', 'malformed_deadline'] as $change) {
            yield $change => [$change, 'cancellation_scope_activity_abandon_not_supported'];
        }
        foreach (['unknown_policy', 'duplicate'] as $change) {
            yield $change => [$change, 'cancellation_scope_activity_history_invalid'];
        }
    }

    #[DataProvider('invalidFences')]
    public function testFenceRefusesInvalidAuthorityWithoutChangingRows(string $condition, string $reason): void
    {
        [, $run, $task, $parent, $scope, $sibling] = $this->tree($condition === 'shield');
        [$target, $other] = $this->remotePair($run, $task, $scope, $sibling);
        if ($condition === 'shield') {
            CancellationScopeRequests::request($run, $parent, '1.20', 30);
            $request = CancellationScopeRequests::request($run, $scope, '1.20', 30, parentScopeId: $parent);
        } else {
            $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        }
        $originalClaim = clone $task;
        if ($condition === 'claim') {
            $task->forceFill([
                'lease_owner' => 'replacement',
                'attempt_count' => 2,
            ])->save();
        } elseif ($condition === 'terminal') {
            $target->forceFill([
                'status' => ActivityStatus::Completed,
                'closed_at' => now(),
            ])->save();
        } elseif ($condition === 'namespace') {
            $task->forceFill([
                'namespace' => 'other-namespace',
            ])->save();
        } elseif ($condition === 'attempt') {
            $claim = app(ActivityTaskBridge::class)->claimStatus(
                $this->activityTask($run, $target)
->id,
                'activity-owner'
            );
            $this->assertTrue($claim['claimed']);
            ActivityAttempt::query()->findOrFail($claim['activity_attempt_id'])
                ->forceFill([
                    'activity_execution_id' => $other->id,
                ])->save();
        }
        $before = $target->fresh()
            ->getAttributes();
        $claimBefore = $task->fresh()
            ->getAttributes();
        $historyBefore = $run->historyEvents()
            ->count();
        try {
            ScopedActivityCancellation::fence(
                $run,
                $originalClaim,
                $target->id,
                $scope,
                $request->payload['request_id'],
                $condition === 'protocol' ? '1.19' : '1.20'
            );
            $this->fail('Invalid scope authority must be refused.');
        } catch (LogicException $exception) {
            $this->assertSame($reason, $exception->getMessage());
        }
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertSame($claimBefore, $task->fresh()->getAttributes());
        $this->assertSame($historyBefore, $run->historyEvents()->count());
    }

    public static function invalidFences(): iterable
    {
        yield 'stale claim' => ['claim', 'cancellation_scope_workflow_claim_mismatch'];
        yield 'old protocol' => ['protocol', 'cancellation_scope_requires_protocol_1_20'];
        yield 'inherited shield' => ['shield', 'cancellation_scope_parent_shielded'];
        yield 'wrong namespace' => ['namespace', 'cancellation_scope_workflow_claim_mismatch'];
        yield 'wrong attempt execution' => ['attempt', 'cancellation_scope_activity_attempt_changed'];
        yield 'terminal projection without fact' => [
            'terminal',
            'cancellation_scope_activity_terminal_history_not_recorded',
        ];
    }

    /**
     * @return array{WorkflowStub, WorkflowRun, WorkflowTask, string, string, string}
     */
    private function tree(bool $shield = false): array
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class);
        $workflow->start('scoped-activity');
        $run = $workflow->run();
        $task = $run->tasks()
            ->where('task_type', TaskType::Workflow)->sole();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'scope-owner',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinutes(5),
        ])->save();
        $parent = CancellationScopeHistory::open($run, $task, 1, '1.20')->payload['scope_id'];
        $scope = CancellationScopeHistory::open($run, $task, 2, '1.20', $parent, $shield)->payload['scope_id'];
        $sibling = CancellationScopeHistory::open($run, $task, 3, '1.20')->payload['scope_id'];
        return [$workflow, $run, $task->refresh(), $parent, $scope, $sibling];
    }

    /**
     * @return array{ActivityExecution, ActivityExecution}
     */
    private function remotePair(
        WorkflowRun $run,
        WorkflowTask $task,
        string $scope,
        string $sibling,
        string $policy = 'wait_cancellation_completed',
        bool $parallel = false,
    ): array {
        $commands = [];
        foreach ([$scope, $sibling] as $index => $address) {
            $commands[] = [
                'type' => 'schedule_activity',
                'activity_type' => TestGreetingActivity::class,
                'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
                'payload_codec' => 'avro',
                'cancellation_scope_id' => $address,
                'cancellation_policy' => $policy,
                ...($parallel ? ParallelChildGroup::itemMetadata(4, 2, $index, 'activity') : []),
                'schedule_to_close_timeout' => 120,
                'retry_policy' => [
                    'max_attempts' => 2,
                    'backoff_seconds' => [0],
                ],
            ];
        }
        $reply = app(DefaultWorkflowTaskBridge::class)->checkpointCancellationScopePrefix(
            $task->id,
            'scope-owner',
            1,
            'remote-pair',
            4,
            $commands,
            '1.20'
        );
        $this->assertTrue($reply['checkpointed'], $reply['reason'] ?? '');
        return [$run->activityExecutions()->where('sequence', 4)->sole(),
            $run->activityExecutions()
                ->where('sequence', 5)
                ->sole()];
    }

    private function activityTask(WorkflowRun $run, ActivityExecution $execution): WorkflowTask
    {
        return $run->tasks()
            ->where('task_type', TaskType::Activity)->where('payload->activity_execution_id', $execution->id)->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function localDescriptor(string $scope): array
    {
        return [
            'type' => 'record_local_activity',
            'activity_type' => 'python-local-greeting',
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
            'payload_codec' => 'avro',
            'cancellation_scope_id' => $scope,
            'cancellation_policy' => 'wait_cancellation_completed',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fence(WorkflowRun $run, WorkflowTask $task, ActivityExecution $execution, string $scope): array
    {
        return ScopedActivityCancellation::fence(
            $run,
            $task,
            $execution->id,
            $scope,
            CancellationScopeRequests::context($run, $scope)->requestId,
            '1.20'
        );
    }

    private function assertDeliveryRefused(WorkflowRun $run, WorkflowTask $task, string $scope, string $reason): void
    {
        $before = $task->fresh()
            ->getAttributes();
        $history = $run->historyEvents()
            ->count();
        try {
            CancellationScopeDelivery::record(
                $run->fresh(),
                $task,
                $scope,
                CancellationScopeRequests::context($run, $scope)->requestId,
                4,
                'activity',
                '1.20'
            );
            $this->fail('Unproved activity cancellation must prevent delivery.');
        } catch (LogicException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame($history, $run->historyEvents()->count());
    }
}
