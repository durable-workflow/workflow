<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
use Workflow\V2\Support\PortableCancellationScopeDelivery;
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

    public function testRemoteActivityTaskLockCannotExtendHostingClaim(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling);
        $activityTask = $this->activityTask($run, $target);
        $claim = app(ActivityTaskBridge::class)->claimStatus($activityTask->id, 'activity-owner');
        $this->assertTrue($claim['claimed'], $claim['reason'] ?? '');
        $task->forceFill([
            'lease_expires_at' => now()
                ->addSeconds(5),
        ])->save();
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            '1.20'
        );
        $crossed = false;
        DB::listen(static function (QueryExecuted $query) use ($activityTask, &$crossed): void {
            if (! $crossed && str_contains($query->sql, 'workflow_tasks')
                && in_array($activityTask->id, $query->bindings, true)) {
                Carbon::setTestNow('2026-10-03T00:00:05Z');
                $crossed = true;
            }
        });
        $before = $run->historyEvents()
            ->count();
        try {
            ScopedActivityCancellation::fence(
                $run->fresh(),
                $task,
                $target->id,
                $scope,
                $request->payload['request_id'],
                '1.20'
            );
            $this->fail('Waiting for the Activity task lock must not renew the hosting claim.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_workflow_claim_mismatch', $error->getMessage());
        }
        $this->assertTrue($crossed);
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame(ActivityStatus::Running, $target->fresh()->status);
        $this->assertSame(TaskStatus::Leased, $activityTask->fresh()->status);
    }

    public static function policies(): iterable
    {
        yield 'try' => [CancellationPolicy::TryCancel->value, false];
        yield 'wait' => [CancellationPolicy::WaitCancellationCompleted->value, true];
    }

    public static function authorityLockDeadlines(): iterable
    {
        yield 'current ancestor deadline' => [false];
        yield 'original captured deadline' => [true];
    }

    #[DataProvider('authorityLockDeadlines')]
    public function testRemoteTaskLockCannotExtendCancellationAuthority(bool $captured): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling);
        $activityTask = $this->activityTask($run, $target);
        $claim = app(ActivityTaskBridge::class)->claimStatus($activityTask->id, 'activity-owner');
        $this->assertTrue($claim['claimed'], $claim['reason'] ?? '');
        if ($captured) {
            $run->forceFill([
                'run_deadline_at' => now()
                    ->addSeconds(5),
            ])->save();
        }
        $request = CancellationScopeRequests::request($run->fresh(), $scope, '1.20', 30);
        CancellationScopeDelivery::prepare(
            $run->fresh(),
            $task,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            '1.20'
        );
        $run->forceFill([
            'run_deadline_at' => now()
                ->addSeconds($captured ? 100 : 5),
        ])->save();
        $crossed = false;
        DB::listen(static function (QueryExecuted $query) use ($activityTask, &$crossed): void {
            if (! $crossed && str_contains($query->sql, 'workflow_tasks')
                && in_array($activityTask->id, $query->bindings, true)) {
                Carbon::setTestNow('2026-10-03T00:00:05Z');
                $crossed = true;
            }
        });
        $before = $run->historyEvents()
            ->count();
        try {
            ScopedActivityCancellation::fence(
                $run->fresh(),
                $task,
                $target->id,
                $scope,
                $request->payload['request_id'],
                '1.20'
            );
            $this->fail('Waiting for the Activity task lock must not extend cancellation authority.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_authority_expired', $error->getMessage());
        }
        $this->assertTrue($crossed);
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame(ActivityStatus::Running, $target->fresh()->status);
        $this->assertSame(TaskStatus::Leased, $activityTask->fresh()->status);
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
        CancellationScopeDelivery::prepare(
            $run,
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
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $scopePreparation = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'parallel',
            2,
            protocolVersion: '1.20'
        );
        $this->assertTrue($scopePreparation['prepared'], $scopePreparation['reason'] ?? '');
        $before = $task->fresh()
            ->getAttributes();
        $deliver = static fn () => $bridge->deliverCancellationScope(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'parallel',
            2,
            protocolVersion: '1.20'
        );
        $waiting = $deliver();
        $this->assertFalse($waiting['delivered']);
        $this->assertSame('cancellation_scope_activity_stop_not_acknowledged', $waiting['reason']);
        $this->assertCount(2, $waiting['activity_cancellations']);
        $this->assertTrue(ActivityCancellationAcknowledgement::recordLocalStopped(
            $prepared['activity_attempt_id'],
            'scope-owner',
            $request->payload['request_id'],
            1
        )['acknowledged']);
        $event = $deliver();
        $this->assertTrue($event['delivered'], $event['reason'] ?? '');
        $this->assertSame($scopePreparation['preparation_history_event_id'], $event['preparation_history_event_id']);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame($event['history_event_id'], CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
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
     * @return array<string, mixed>
     */
    public function testPreparationRetainsWholeScopeWithoutDeliveringOrMutatingClaims(): void
    {
        [, $run, $task, , $scope] = $this->tree();
        [$first, $second] = $this->remotePair($run, $task, $scope, $scope);
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $claim = $task->fresh()
            ->getAttributes();
        $next = \Workflow\V2\Support\WorkflowStepHistory::nextDurableCommandSequence($run->fresh());
        $prepared = CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            '1.20'
        );
        $this->assertSame(
            [$first->id, $second->id],
            array_column($prepared->payload['activity_members'], 'activity_execution_id')
        );
        $this->assertSame($claim, $task->fresh()->getAttributes());
        $this->assertSame($next, \Workflow\V2\Support\WorkflowStepHistory::nextDurableCommandSequence($run->fresh()));
        $this->assertNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
        $this->assertSame(ActivityStatus::Pending, $first->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $second->fresh()->status);
        $this->assertNull($run->fresh()->cancellation_request_command_id);
        $timeline = collect(\Workflow\V2\Support\HistoryTimeline::fromHistory($run->fresh()))->firstWhere(
            'id',
            $prepared->id
        );
        $this->assertSame('cancellation_scope', $timeline['kind']);
        $this->assertCount(2, $timeline['cancellation_scope']['activity_members']);
    }

    public function testPreparationCannotRetainCallerLocksAcrossActivityEffects(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        $this->remotePair($run, $task, $scope, $sibling);
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        $history = $run->historyEvents()
            ->count();
        try {
            $run->getConnection()
                ->transaction(static fn () => CancellationScopeDelivery::prepare(
                    $run,
                    $task,
                    $scope,
                    $request->payload['request_id'],
                    4,
                    'activity',
                    '1.20'
                ));
            $this->fail('Preparation must commit before any attempt/execution locks.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_preparation_requires_own_transaction', $error->getMessage());
        }
        $this->assertSame($history, $run->historyEvents()->count());
    }

    public function testReplacementFinishesPartialFencesUsingFirstPreparationAndDeadline(): void
    {
        [, $run, $task, , $scope] = $this->tree();
        [$first, $second] = $this->remotePair($run, $task, $scope, $scope);
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $prepared = CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            '1.20'
        );
        $this->fence($run, $task, $first, $scope);
        Carbon::setTestNow('2026-10-03T00:00:11Z');
        $replacement = $task->fresh();
        $replacement->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
            'lease_expires_at' => now()
                ->addSeconds(10),
        ])->save();
        $duplicate = CancellationScopeDelivery::prepare(
            $run->fresh(),
            $replacement,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            '1.20'
        );
        $this->assertSame($prepared->id, $duplicate->id);
        $this->assertSame('2026-10-03T00:00:00.000000Z', $duplicate->recorded_at->toISOString());
        $this->assertSame('2026-10-03T00:00:30.000000Z', $duplicate->payload['authority_deadline_at']);
        $this->fence($run->fresh(), $replacement, $second, $scope);
        $delivered = CancellationScopeDelivery::record(
            $run->fresh(),
            $replacement,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            '1.20'
        );
        $this->assertSame($prepared->id, $delivered->payload['preparation_history_event_id']);
        $this->assertSame($prepared->payload['authority_deadline_at'], $delivered->payload['authority_deadline_at']);
        $this->assertSame($delivered->id, CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeDeliveryPrepared)->count()
        );
    }

    public function testSelectedHandleStillRequiresStopProofForOtherOriginalScopeMembers(): void
    {
        [, $run, $task, , $scope] = $this->tree();
        [$first, $second] = $this->remotePair($run, $task, $scope, $scope);
        $bridge = app(ActivityTaskBridge::class);
        $claims = [];
        foreach ([$first, $second] as $execution) {
            $claims[] = $bridge->claimStatus($this->activityTask($run, $execution)->id, 'activity-owner');
        }
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            6,
            'selection_handle',
            '1.20',
            1,
            4
        );
        $this->fence($run, $task, $first, $scope);
        $this->assertTrue(
            ActivityCancellationAcknowledgement::recordStopped(
                $claims[0]['activity_attempt_id'],
                'activity-owner',
                $request->payload['request_id']
            )['acknowledged']
        );
        $deliver = static fn () => CancellationScopeDelivery::record(
            $run->fresh(),
            $task,
            $scope,
            $request->payload['request_id'],
            6,
            'selection_handle',
            '1.20',
            1,
            4
        );
        foreach ([
            'cancellation_scope_activity_fence_not_established',
            'cancellation_scope_activity_stop_not_acknowledged',
        ] as $reason) {
            try {
                $deliver();
                $this->fail('All original scope members need the policy-specific proof.');
            } catch (LogicException $error) {
                $this->assertSame($reason, $error->getMessage());
            }
            $this->fence($run, $task, $second, $scope);
        }
        $this->assertTrue(
            ActivityCancellationAcknowledgement::recordStopped(
                $claims[1]['activity_attempt_id'],
                'activity-owner',
                $request->payload['request_id']
            )['acknowledged']
        );
        $delivered = $deliver();
        $this->assertSame($delivered->id, CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
    }

    public function testAnUnpreparedScopeCannotFenceAnUnfinishedActivity(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling);
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        $history = $run->historyEvents()
            ->count();
        try {
            ScopedActivityCancellation::fence(
                $run,
                $task,
                $target->id,
                $scope,
                $request->payload['request_id'],
                '1.20'
            );
            $this->fail('Cancellation effects require prior retained preparation.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_delivery_not_prepared', $error->getMessage());
        }
        $this->assertSame($history, $run->historyEvents()->count());
        $this->assertSame(ActivityStatus::Pending, $target->fresh()->status);
    }

    #[DataProvider('weakenedPolicies')]
    public function testAChangedOriginalPolicyCannotWeakenThePreparedWaitContract(string $policy): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling);
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        CancellationScopeDelivery::prepare($run, $task, $scope, $request->payload['request_id'], 4, 'activity', '1.20');
        $scheduled = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityScheduled)->where(
                'payload->activity_execution_id',
                $target->id
            )->sole();
        $payload = $scheduled->payload;
        $payload['activity']['cancellation_policy'] = $policy;
        $scheduled->forceFill([
            'payload' => $payload,
        ])->save();
        $this->expectExceptionMessage('cancellation_scope_delivery_history_invalid');
        ScopedActivityCancellation::fence(
            $run->fresh(),
            $task,
            $target->id,
            $scope,
            $request->payload['request_id'],
            '1.20'
        );
    }

    public static function weakenedPolicies(): iterable
    {
        yield 'try cancel' => ['try_cancel'];
        yield 'abandon' => ['abandon'];
    }

    #[DataProvider('changedPreparations')]
    public function testPartialFencesCannotMoveTheOriginalPreparedCall(
        int $sequence,
        string $kind,
        int $span,
        ?int $operation,
        int $operationSpan
    ): void {
        [, $run, $task, , $scope] = $this->tree();
        [$first, $second] = $this->remotePair($run, $task, $scope, $scope);
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $prepared = CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            '1.20'
        );
        $this->fence($run, $task, $first, $scope);
        $history = $run->historyEvents()
            ->count();
        try {
            CancellationScopeDelivery::prepare(
                $run->fresh(),
                $task,
                $scope,
                $request->payload['request_id'],
                $sequence,
                $kind,
                '1.20',
                $span,
                $operation,
                $operationSpan
            );
            $this->fail('Partial effects must preserve the first preparation.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_delivery_mismatch', $error->getMessage());
        }
        $this->assertSame($history, $run->historyEvents()->count());
        $this->assertSame(ActivityStatus::Pending, $second->fresh()->status);
        $this->assertSame($prepared->id, CancellationScopeDelivery::prepared($run->fresh(), $scope)->id);
    }

    public static function changedPreparations(): iterable
    {
        yield 'move call' => [5, 'activity', 1, null, 1];
        yield 'change kind' => [4, 'timer', 1, null, 1];
        yield 'move selected operation' => [6, 'selection_handle', 1, 5, 1];
        yield 'widen call' => [4, 'parallel', 2, null, 1];
    }

    public function testExpiredPreparationRemainsReadableButCannotRenewEffectAuthority(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target] = $this->remotePair($run, $task, $scope, $sibling);
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $prepared = CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            '1.20'
        );
        Carbon::setTestNow('2026-10-03T00:00:31Z');
        $this->assertSame($prepared->id, CancellationScopeDelivery::prepared($run->fresh(), $scope)->id);
        $this->expectExceptionMessage('cancellation_scope_authority_expired');
        ScopedActivityCancellation::fence(
            $run->fresh(),
            $task,
            $target->id,
            $scope,
            $request->payload['request_id'],
            '1.20'
        );
    }

    public function testPreparationRejectsNewAdmissionsButAllowsRecordedPrefixReplay(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        $this->remotePair($run, $task, $scope, $sibling);
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        CancellationScopeDelivery::prepare($run, $task, $scope, $request->payload['request_id'], 4, 'activity', '1.20');
        $command = [
            'type' => 'schedule_activity',
            'activity_type' => TestGreetingActivity::class,
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
            'payload_codec' => 'avro',
            'cancellation_scope_id' => $scope,
        ];
        $history = $run->historyEvents()
            ->count();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertNull($bridge->validateCancellationScopeMembership($run->fresh(), [$command], 4));
        $this->remotePair($run->fresh(), $task, $scope, $sibling);
        $this->assertSame($history, $run->historyEvents()->count());
        $result = $bridge->checkpointCancellationScopePrefix(
            $task->id,
            'scope-owner',
            1,
            'late-scope-work',
            6,
            [$command],
            '1.20'
        );
        $this->assertFalse($result['checkpointed']);
        $this->assertSame('operation_scope_cancellation_prepared', $result['reason']);
        $this->assertSame($history, $run->historyEvents()->count());
        $this->assertCount(2, $run->activityExecutions()->get());
        $command['cancellation_scope_id'] = $sibling;
        $this->assertNull($bridge->validateCancellationScopeMembership($run->fresh(), [$command], 6));
    }

    #[DataProvider('ancestorShielding')]
    public function testPreparedAncestorRejectsNewDescendantWorkUnlessShielded(bool $shield): void
    {
        [, $run, $task, $parent, $scope, $sibling] = $this->tree($shield);
        $this->remotePair($run, $task, $parent, $parent);
        $request = CancellationScopeRequests::request($run, $parent, '1.20');
        CancellationScopeDelivery::prepare(
            $run,
            $task,
            $parent,
            $request->payload['request_id'],
            4,
            'activity',
            '1.20'
        );
        $this->assertSame(
            $shield ? null : 'operation_scope_cancellation_prepared',
            CancellationScopeDelivery::admissionRefusal($run->fresh(), $scope, 6)
        );
        $this->assertNull(CancellationScopeDelivery::admissionRefusal($run->fresh(), $sibling, 6));
        // Shielding does not let a new command consume the pending delivery position.
        $this->assertSame(
            $shield ? 'operation_cancellation_delivery_reserved' : 'operation_scope_cancellation_prepared',
            CancellationScopeDelivery::admissionRefusal($run->fresh(), $scope, 4)
        );
    }

    public static function ancestorShielding(): iterable
    {
        yield 'inherited' => [false];
        yield 'shielded' => [true];
    }

    public function testActivityOutsideTheOriginalPreparationCannotBeFenced(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        $this->remotePair($run, $task, $scope, $sibling);
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        CancellationScopeDelivery::prepare($run, $task, $scope, $request->payload['request_id'], 4, 'activity', '1.20');
        $reply = app(DefaultWorkflowTaskBridge::class)->checkpointCancellationScopePrefix(
            $task->id,
            'scope-owner',
            1,
            'later-sibling',
            6,
            [[
                'type' => 'schedule_activity',
                'activity_type' => TestGreetingActivity::class,
                'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
                'payload_codec' => 'avro',
                'cancellation_scope_id' => $sibling,
            ]],
            '1.20'
        );
        $this->assertTrue($reply['checkpointed'], $reply['reason'] ?? '');
        $execution = $run->activityExecutions()
            ->where('sequence', 6)
            ->sole();
        $scheduled = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityScheduled)
            ->where('payload->activity_execution_id', $execution->id)
            ->sole();
        // Corrupt a later admission to bypass the bridge's prepared-scope guard.
        $payload = $scheduled->payload;
        $payload['cancellation_scope_id'] = $scope;
        $payload['activity']['cancellation_scope_id'] = $scope;
        $scheduled->forceFill([
            'payload' => $payload,
        ])->save();
        $execution->forceFill([
            'activity_options' => [
                ...$execution->activity_options,
                'cancellation_scope_id' => $scope,
            ],
        ])->save();
        $history = $run->historyEvents()
            ->count();
        try {
            ScopedActivityCancellation::fence(
                $run->fresh(),
                $task,
                $execution->id,
                $scope,
                $request->payload['request_id'],
                '1.20'
            );
            $this->fail('A later Activity cannot become an original preparation member.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_activity_not_prepared', $error->getMessage());
        }
        $this->assertSame($history, $run->historyEvents()->count());
        $this->assertSame(ActivityStatus::Pending, $execution->fresh()->status);
    }

    public function testAuthenticatedBridgeRetainsPreparationWhileWaitingForOriginalStopProof(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target, $other] = $this->remotePair($run, $task, $scope, $sibling);
        $activityBridge = app(ActivityTaskBridge::class);
        $claim = $activityBridge->claimStatus($this->activityTask($run, $target)->id, 'activity-owner');
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertInstanceOf(\Workflow\V2\Contracts\PreparedCancellationScopeTaskBridge::class, $bridge);
        $taskBefore = $task->fresh()
            ->getAttributes();
        $prepared = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $this->assertFalse($prepared['delivered']);
        $this->assertFalse($prepared['claim_released']);
        $this->assertCount(1, $prepared['activity_members']);
        $this->assertSame($target->id, $prepared['activity_members'][0]['activity_execution_id']);
        $this->assertSame($prepared['history_event_id'], $prepared['preparation_history_event_id']);
        $this->assertSame(ActivityStatus::Running, $target->fresh()->status);
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCancelled)->count());
        $deliver = static fn () => $bridge->deliverCancellationScope(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $waiting = $deliver();
        $this->assertTrue($waiting['prepared']);
        $this->assertFalse($waiting['delivered']);
        $this->assertSame('cancellation_scope_activity_stop_not_acknowledged', $waiting['reason']);
        $this->assertSame($prepared['preparation_history_event_id'], $waiting['preparation_history_event_id']);
        $this->assertSame($prepared['authority_deadline_at'], $waiting['authority_deadline_at']);
        $this->assertSame($prepared['cancellation'], $waiting['cancellation']);
        $this->assertTrue($waiting['activity_cancellations'][0]['fenced']);
        $this->assertTrue($waiting['activity_cancellations'][0]['waiting_for_stop']);
        $this->assertSame($target->id, $waiting['activity_cancellations'][0]['activity_execution_id']);
        $this->assertFalse($activityBridge->status($claim['activity_attempt_id'])['can_continue']);
        $this->assertFalse($activityBridge->complete(
            $claim['activity_attempt_id'],
            Serializer::serializeWithCodec('avro', 'stale'),
            'avro'
        )['recorded']);
        $this->assertSame($waiting, $deliver());
        $this->assertTrue(ActivityCancellationAcknowledgement::recordStopped(
            $claim['activity_attempt_id'],
            'activity-owner',
            $request->payload['request_id']
        )['acknowledged']);
        $delivered = $deliver();
        $this->assertTrue($delivered['delivered'], $delivered['reason'] ?? '');
        $this->assertSame($prepared['preparation_history_event_id'], $delivered['preparation_history_event_id']);
        $this->assertSame($prepared['activity_members'], $delivered['activity_members']);
        $this->assertSame($delivered['history_event_id'], $deliver()['history_event_id']);
        $this->assertSame($taskBefore, $task->fresh()->getAttributes());
        $this->assertSame(ActivityStatus::Pending, $other->fresh()->status);
    }

    public function testAuthenticatedDispatchCancelsOriginalMembersOutsideTheSelectedCall(): void
    {
        [, $run, $task, , $scope] = $this->tree();
        [$first, $second] = $this->remotePair($run, $task, $scope, $scope);
        $activityBridge = app(ActivityTaskBridge::class);
        $claims = [];
        foreach ([$first, $second] as $execution) {
            $claims[] = $activityBridge->claimStatus($this->activityTask($run, $execution)->id, 'activity-owner');
        }
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $prepared = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertCount(2, $prepared['activity_members']);
        $deliver = static fn () => $bridge->deliverCancellationScope(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $waiting = $deliver();
        $this->assertFalse($waiting['delivered']);
        $this->assertSame('cancellation_scope_activity_stop_not_acknowledged', $waiting['reason']);
        $this->assertSame(
            [$first->id, $second->id],
            array_column($waiting['activity_cancellations'], 'activity_execution_id')
        );
        foreach ([$first, $second] as $execution) {
            $this->assertSame(ActivityStatus::Cancelled, $execution->fresh()->status);
        }
        foreach ($claims as $index => $claim) {
            $this->assertTrue(ActivityCancellationAcknowledgement::recordStopped(
                $claim['activity_attempt_id'],
                'activity-owner',
                $request->payload['request_id']
            )['acknowledged']);
            $result = $deliver();
            $this->assertSame($index === 1, $result['delivered']);
            $this->assertSame($prepared['authority_deadline_at'], $result['authority_deadline_at']);
        }
    }

    public function testAuthenticatedDispatchRetriesPartialCommittedProgressAfterClaimReplacement(): void
    {
        [, $run, $task, , $scope] = $this->tree();
        [$first, $second] = $this->remotePair($run, $task, $scope, $scope);
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $prepared = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $replace = true;
        WorkflowHistoryEvent::created(static function (WorkflowHistoryEvent $event) use (
            $task,
            $first,
            &$replace
        ): void {
            if (! $replace || $event->event_type !== HistoryEventType::ActivityCancelled
                || ($event->payload['activity_execution_id'] ?? null) !== $first->id) {
                return;
            }
            $replace = false;
            $event->getConnection()
                ->afterCommit(static function () use ($task): void {
                    Carbon::setTestNow('2026-10-03T00:00:11Z');
                    $task->fresh()
                        ->forceFill([
                            'lease_owner' => 'replacement',
                            'attempt_count' => 2,
                            'lease_expires_at' => now()
                                ->addSeconds(10),
                        ])->save();
                });
        });
        $partial = $bridge->deliverCancellationScope(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertFalse($partial['delivered']);
        $this->assertSame('cancellation_scope_workflow_claim_mismatch', $partial['reason']);
        $this->assertCount(1, $partial['activity_cancellations']);
        $firstReceipt = $partial['activity_cancellations'][0];
        $this->assertTrue($firstReceipt['fenced']);
        $this->assertSame(ActivityStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $second->fresh()->status);
        $this->assertNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
        $resumed = $bridge->deliverCancellationScope(
            $task->id,
            'replacement',
            2,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertTrue($resumed['delivered'], $resumed['reason'] ?? '');
        $this->assertSame($firstReceipt, $resumed['activity_cancellations'][0]);
        $this->assertSame($prepared['preparation_history_event_id'], $resumed['preparation_history_event_id']);
        $this->assertSame($prepared['authority_deadline_at'], $resumed['authority_deadline_at']);
        $this->assertSame('2026-10-03T00:00:30.000000Z', $resumed['authority_deadline_at']);
        $this->assertSame(2, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCancelled)->count());
    }

    #[DataProvider('dispatchRefusals')]
    public function testAuthenticatedDispatchValidatesTheOriginalBoundaryBeforeEffects(
        string $change,
        string $reason
    ): void {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target, $other] = $this->remotePair($run, $task, $scope, $sibling);
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $prepared = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        if ($change === 'deadline') {
            Carbon::setTestNow('2026-10-03T00:00:30Z');
        }
        $before = $run->historyEvents()
            ->count();
        $result = $bridge->deliverCancellationScope(
            $task->id,
            'scope-owner',
            1,
            $change === 'scope' ? $sibling : $scope,
            $change === 'request' ? 'wrong-request' : $request->payload['request_id'],
            $change === 'sequence' ? 5 : 4,
            $change === 'kind' ? 'timer' : 'activity',
            $change === 'span' ? 2 : 1,
            $change === 'operation' ? 4 : null,
            $change === 'operation-span' ? 2 : 1,
            '1.20'
        );
        $this->assertFalse($result['delivered']);
        $this->assertSame($reason, $result['reason']);
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame(ActivityStatus::Pending, $target->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $other->fresh()->status);
    }

    public static function dispatchRefusals(): iterable
    {
        foreach (['sequence', 'kind'] as $change) {
            yield $change => [$change, 'cancellation_scope_delivery_mismatch'];
        }
        yield 'span' => ['span', 'invalid_cancellation_delivery'];
        yield 'operation' => ['operation', 'invalid_cancellation_delivery'];
        yield 'operation-span' => ['operation-span', 'invalid_cancellation_delivery'];
        yield 'request' => ['request', 'cancellation_scope_request_mismatch'];
        yield 'scope' => ['scope', 'cancellation_scope_delivery_not_prepared'];
        yield 'deadline' => ['deadline', 'cancellation_scope_authority_expired'];
    }

    #[DataProvider('dispatchPolicies')]
    public function testAuthenticatedDispatchPreservesNaturalCompletionTryAndBoundedAbandon(
        string $policy,
        bool $completed
    ): void {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target, $other] = $this->remotePair($run, $task, $scope, $sibling, $policy);
        $activityBridge = app(ActivityTaskBridge::class);
        $claim = $activityBridge->claimStatus($this->activityTask($run, $target)->id, 'activity-owner');
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $prepared = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        if ($completed) {
            $this->assertTrue($activityBridge->complete(
                $claim['activity_attempt_id'],
                Serializer::serializeWithCodec('avro', 'completed'),
                'avro'
            )['recorded']);
        }
        $before = $target->fresh()
            ->getAttributes();
        $delivered = $bridge->deliverCancellationScope(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertTrue($delivered['delivered'], $delivered['reason'] ?? '');
        $this->assertSame($prepared['preparation_history_event_id'], $delivered['preparation_history_event_id']);
        $receipt = $delivered['activity_cancellations'][0];
        $this->assertFalse($receipt['waiting_for_stop']);
        $this->assertSame($policy === 'abandon', $receipt['abandoned']);
        $this->assertSame(! $completed && $policy === 'try_cancel', $receipt['fenced']);
        if ($completed || $policy === 'abandon') {
            $this->assertSame($before, $target->fresh()->getAttributes());
        } else {
            $this->assertSame(ActivityStatus::Cancelled, $target->fresh()->status);
        }
        $this->assertSame(ActivityStatus::Pending, $other->fresh()->status);
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
        );
        if ($policy === 'abandon') {
            $this->assertTrue($activityBridge->status($claim['activity_attempt_id'])['can_continue']);
            $this->assertTrue($activityBridge->complete(
                $claim['activity_attempt_id'],
                Serializer::serializeWithCodec('avro', 'continued'),
                'avro'
            )['recorded']);
        }
    }

    public static function dispatchPolicies(): iterable
    {
        yield 'natural completion' => ['wait_cancellation_completed', true];
        yield 'try cancel' => ['try_cancel', false];
        yield 'bounded abandon' => ['abandon', false];
    }

    #[DataProvider('bridgeRefusals')]
    public function testAuthenticatedBridgeRefusesInvalidClaimsWithoutEffects(
        string $owner,
        int $attempt,
        string $protocol,
        string $requestMode,
        int $sequence,
        string $kind,
        string $reason,
    ): void {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target, $other] = $this->remotePair($run, $task, $scope, $sibling);
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        if ($requestMode === 'expired') {
            Carbon::setTestNow('2026-10-03T00:05:01Z');
        } elseif ($requestMode === 'namespace') {
            $task->forceFill([
                'namespace' => 'other-namespace',
            ])->save();
        } elseif ($requestMode === 'activity-task') {
            $task->forceFill([
                'task_type' => TaskType::Activity,
            ])->save();
        }
        $history = $run->historyEvents()
            ->count();
        $before = $task->fresh()
            ->getAttributes();
        $result = app(DefaultWorkflowTaskBridge::class)->prepareCancellationScopeDelivery(
            $requestMode === 'missing' ? 'missing-task' : $task->id,
            $owner,
            $attempt,
            $requestMode === 'sibling' ? $sibling : $scope,
            $requestMode === 'request' ? 'wrong-request' : $request->payload['request_id'],
            $sequence,
            $kind,
            protocolVersion: $protocol
        );
        $this->assertFalse($result['prepared']);
        $this->assertFalse($result['delivered']);
        $this->assertFalse($result['claim_released']);
        $this->assertSame($reason, $result['reason']);
        $this->assertSame($history, $run->historyEvents()->count());
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame(ActivityStatus::Pending, $target->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $other->fresh()->status);
    }

    public static function bridgeRefusals(): iterable
    {
        yield 'wrong owner' => ['other', 1, '1.20', '', 4, 'activity', 'cancellation_scope_workflow_claim_mismatch'];
        yield 'wrong attempt' => [
            'scope-owner',
            2,
            '1.20',
            '',
            4,
            'activity',
            'cancellation_scope_workflow_claim_mismatch',
        ];
        yield 'invalid attempt' => ['scope-owner', 0, '1.20', '', 4, 'activity', 'invalid_cancellation_scope_delivery'];
        yield 'expired claim' => [
            'scope-owner',
            1,
            '1.20',
            'expired',
            4,
            'activity',
            'cancellation_scope_workflow_claim_mismatch',
        ];
        yield 'namespace mismatch' => [
            'scope-owner',
            1,
            '1.20',
            'namespace',
            4,
            'activity',
            'cancellation_scope_workflow_claim_mismatch',
        ];
        yield 'wrong task type' => [
            'scope-owner',
            1,
            '1.20',
            'activity-task',
            4,
            'activity',
            'cancellation_scope_workflow_claim_mismatch',
        ];
        yield 'missing task' => ['scope-owner', 1, '1.20', 'missing', 4, 'activity', 'task_not_found'];
        yield 'older protocol' => [
            'scope-owner',
            1,
            '1.19',
            '',
            4,
            'activity',
            'cancellation_scope_requires_protocol_1_20',
        ];
        yield 'wrong protocol major' => [
            'scope-owner',
            1,
            '2.0',
            '',
            4,
            'activity',
            'cancellation_scope_requires_protocol_1_20',
        ];
        yield 'wrong request' => [
            'scope-owner',
            1,
            '1.20',
            'request',
            4,
            'activity',
            'cancellation_scope_request_mismatch',
        ];
        yield 'wrong scope' => [
            'scope-owner',
            1,
            '1.20',
            'sibling',
            4,
            'activity',
            'cancellation_scope_request_mismatch',
        ];
        yield 'later call' => ['scope-owner', 1, '1.20', '', 99, 'activity', 'cancellation_delivery_sequence_mismatch'];
        yield 'invalid call' => ['scope-owner', 1, '1.20', '', 4, 'missing', 'invalid_cancellation_delivery'];
    }

    public function testAuthenticatedBridgeCannotDeliverWithoutPreparation(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        $this->remotePair($run, $task, $scope, $sibling);
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        $history = $run->historyEvents()
            ->count();
        $result = app(DefaultWorkflowTaskBridge::class)->deliverCancellationScope(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertFalse($result['prepared']);
        $this->assertFalse($result['delivered']);
        $this->assertSame('cancellation_scope_delivery_not_prepared', $result['reason']);
        $this->assertSame($history, $run->historyEvents()->count());
    }

    public function testAuthenticatedBridgeReplacementReusesFirstPreparationAndBoundary(): void
    {
        [, $run, $task, , $scope] = $this->tree();
        [$first, $second] = $this->remotePair($run, $task, $scope, $scope);
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $prepared = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $this->fence($run, $task, $first, $scope);
        Carbon::setTestNow('2026-10-03T00:00:11Z');
        $replacement = $task->fresh();
        $replacement->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
            'lease_expires_at' => now()
                ->addSeconds(10),
        ])->save();
        $history = $run->historyEvents()
            ->count();
        $stale = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertFalse($stale['prepared']);
        $this->assertSame('cancellation_scope_workflow_claim_mismatch', $stale['reason']);
        $duplicate = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'replacement',
            2,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertSame($prepared, $duplicate);
        $moved = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'replacement',
            2,
            $scope,
            $request->payload['request_id'],
            5,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertFalse($moved['prepared']);
        $this->assertSame('cancellation_scope_delivery_mismatch', $moved['reason']);
        $this->assertSame($history, $run->historyEvents()->count());
        $delivered = $bridge->deliverCancellationScope(
            $task->id,
            'replacement',
            2,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertTrue($delivered['delivered'], $delivered['reason'] ?? '');
        $this->assertSame($prepared['preparation_history_event_id'], $delivered['preparation_history_event_id']);
        $this->assertSame($prepared['cancellation'], $delivered['cancellation']);
        $this->assertSame('2026-10-03T00:00:30.000000Z', $delivered['authority_deadline_at']);
        $this->assertCount(2, $delivered['activity_cancellations']);
        $this->assertSame(ActivityStatus::Cancelled, $second->fresh()->status);
    }

    #[DataProvider('bridgeTransactionPhases')]
    public function testAuthenticatedBridgeCannotRetainCallerTransactionLocks(bool $preparing): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        $this->remotePair($run, $task, $scope, $sibling);
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        $history = $run->historyEvents()
            ->count();
        $method = $preparing ? 'prepareCancellationScopeDelivery' : 'deliverCancellationScope';
        $result = $run->getConnection()
            ->transaction(static fn () => app(DefaultWorkflowTaskBridge::class)->{$method}(
                $task->id,
                'scope-owner',
                1,
                $scope,
                $request->payload['request_id'],
                4,
                'activity',
                protocolVersion: '1.20'
            ));
        $this->assertFalse($result['prepared']);
        $this->assertFalse($result['delivered']);
        $this->assertSame('cancellation_scope_preparation_requires_own_transaction', $result['reason']);
        $this->assertSame($history, $run->historyEvents()->count());
    }

    public static function bridgeTransactionPhases(): iterable
    {
        yield 'prepare' => [true];
        yield 'record delivery' => [false];
    }

    #[DataProvider('ancestorShielding')]
    public function testAuthenticatedBridgeFencesUnshieldedDescendantsAndPreservesShieldedOnes(bool $shield): void
    {
        [, $run, $task, $parent, $scope] = $this->tree($shield);
        [$first, $descendant] = $this->remotePair($run, $task, $parent, $scope);
        $request = CancellationScopeRequests::request($run, $parent, '1.20');
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $prepared = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'scope-owner',
            1,
            $parent,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $result = $bridge->deliverCancellationScope(
            $task->id,
            'scope-owner',
            1,
            $parent,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertTrue($result['delivered'], $result['reason'] ?? '');
        $this->assertSame(ActivityStatus::Cancelled, $first->fresh()->status);
        $this->assertSame($shield ? ActivityStatus::Pending : ActivityStatus::Cancelled, $descendant->fresh()->status);
        $this->assertNull(CancellationScopeDelivery::prepared($run->fresh(), $scope));
        $this->assertSame($prepared['preparation_history_event_id'], $result['preparation_history_event_id']);
    }

    public function testAuthenticatedBridgeDispatchesMixedChildrenAndActivitiesWithoutCancellingSiblings(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$first, $other] = $this->remotePair($run, $task, $scope, $sibling);
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $scheduled = $bridge->checkpointCancellationScopePrefix(
            $task->id,
            'scope-owner',
            1,
            'child-prefix',
            6,
            [[
                'type' => 'start_child_workflow',
                'workflow_type' => 'child-scope-fixture',
                'arguments' => Serializer::serializeWithCodec('avro', ['child']),
                'payload_codec' => 'avro',
                'cancellation_policy' => 'try_cancel',
                'cancellation_scope_id' => $scope,
            ]],
            '1.20'
        );
        $this->assertTrue($scheduled['checkpointed'], $scheduled['reason'] ?? '');
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        $prepared = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $claimBefore = $task->fresh()
            ->getAttributes();
        $result = $bridge->deliverCancellationScope(
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            4,
            'activity',
            protocolVersion: '1.20'
        );
        $this->assertTrue($result['prepared']);
        $this->assertTrue($result['delivered'], $result['reason'] ?? '');
        $this->assertFalse($result['claim_released']);
        $this->assertSame($claimBefore, $task->fresh()->getAttributes());
        $this->assertSame(ActivityStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $other->fresh()->status);
        $this->assertCount(1, $result['activity_cancellations']);
        $this->assertCount(1, $result['child_cancellations']);
        $receipt = $result['child_cancellations'][0];
        $target = WorkflowRun::query()->findOrFail($receipt['child_workflow_run_id']);
        $this->assertFalse($target->status->isTerminal());
        $this->assertSame($receipt['request_id'], $target->cancellation_request_command_id);
        $this->assertNotNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
    }

    public function testConflictingNewScopeCannotFenceWorkBeforeTheOriginalDeliveryBoundaryIsRejected(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target, $other] = $this->remotePair($run, $task, $scope, $sibling, 'try_cancel');
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        CancellationScopeDelivery::prepare($run, $task, $scope, $request->payload['request_id'], 6, 'timer', '1.20');
        // Simulate an already corrupted command history to qualify the actor preflight,
        // independently of the admission guard exercised by the following test.
        $opened = $run->historyEvents()
            ->where('event_type', HistoryEventType::CancellationScopeOpened)
            ->where('payload->scope_id', $sibling)
            ->sole();
        $openingPayload = $opened->payload;
        unset($openingPayload['task']);
        WorkflowHistoryEvent::record($run->fresh(), HistoryEventType::CancellationScopeOpened, [
            ...$openingPayload,
            'sequence' => 6,
            'scope_id' => 'conflicting-unrelated-scope',
            'parent_scope_id' => $sibling,
        ], $task);
        $result = PortableCancellationScopeDelivery::mutate(
            false,
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            6,
            'timer',
            1,
            null,
            1,
            '1.20'
        );
        $this->assertFalse($result['delivered']);
        $this->assertSame('cancellation_delivery_shape_mismatch', $result['reason']);
        $this->assertSame(ActivityStatus::Pending, $target->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $other->fresh()->status);
        $this->assertSame(TaskStatus::Ready, $this->activityTask($run, $target)->status);
        $this->assertNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
    }

    public function testPendingDeliveryReservesItsFutureCommandWhileExistingSiblingWorkAndReplayContinue(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        [$target, $other] = $this->remotePair($run, $task, $scope, $sibling, 'try_cancel');
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $preparation = CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            6,
            'timer',
            '1.20'
        );
        $count = $run->historyEvents()
            ->count();
        try {
            CancellationScopeHistory::open($run->fresh(), $task, 6, '1.20', $sibling);
            $this->fail('A new unrelated scope consumed the prepared future boundary.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_command_sequence_reserved', $error->getMessage());
        }
        $this->assertSame($count, $run->historyEvents()->count());
        $this->assertSame(ActivityStatus::Pending, $target->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $other->fresh()->status);
        $conflictingPrefix = app(DefaultWorkflowTaskBridge::class)->checkpointCancellationScopePrefix(
            $task->id,
            'scope-owner',
            1,
            'conflicting-outside-prefix',
            6,
            [[
                'type' => 'schedule_activity',
                'activity_type' => TestGreetingActivity::class,
                'arguments' => Serializer::serializeWithCodec('avro', ['another']),
                'payload_codec' => 'avro',
                'cancellation_scope_id' => $sibling,
                'cancellation_policy' => 'try_cancel',
                'schedule_to_close_timeout' => 120,
            ]],
            '1.20'
        );
        $this->assertFalse($conflictingPrefix['checkpointed']);
        $this->assertSame('operation_cancellation_delivery_reserved', $conflictingPrefix['reason']);
        $this->assertSame($count, $run->historyEvents()->count());
        $this->assertSame($preparation->id, CancellationScopeDelivery::prepare(
            $run->fresh(),
            $task,
            $scope,
            $request->payload['request_id'],
            6,
            'timer',
            '1.20'
        )->id);
        $this->assertSame(
            $sibling,
            CancellationScopeHistory::open($run->fresh(), $task, 3, '1.20')->payload['scope_id']
        );
        $result = PortableCancellationScopeDelivery::mutate(
            false,
            $task->id,
            'scope-owner',
            1,
            $scope,
            $request->payload['request_id'],
            6,
            'timer',
            1,
            null,
            1,
            '1.20'
        );
        $this->assertTrue($result['delivered'], $result['reason'] ?? '');
        $this->assertSame(ActivityStatus::Cancelled, $target->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $other->fresh()->status);
        $later = CancellationScopeHistory::open($run->fresh(), $task, 7, '1.20', $sibling);
        $this->assertSame($sibling, $later->payload['parent_scope_id']);
        $this->assertSame($preparation->id, CancellationScopeDelivery::prepare(
            $run->fresh(),
            $task,
            $scope,
            $request->payload['request_id'],
            6,
            'timer',
            '1.20'
        )->id);
    }

    public function testAnotherAcceptedScopeCannotPrepareTheSameFutureAuthoredPosition(): void
    {
        [, $run, $task, , $scope, $sibling] = $this->tree();
        $this->remotePair($run, $task, $scope, $sibling, 'try_cancel');
        $first = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $second = CancellationScopeRequests::request($run, $sibling, '1.20', 30);
        CancellationScopeDelivery::prepare($run, $task, $scope, $first->payload['request_id'], 6, 'timer', '1.20');
        $count = $run->historyEvents()
            ->count();
        try {
            CancellationScopeDelivery::prepare(
                $run->fresh(),
                $task,
                $sibling,
                $second->payload['request_id'],
                6,
                'timer',
                '1.20'
            );
            $this->fail('Two scopes prepared the same future authored position.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_command_sequence_reserved', $error->getMessage());
        }
        $this->assertSame($count, $run->historyEvents()->count());
        $this->assertNull(CancellationScopeDelivery::prepared($run->fresh(), $sibling));
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

    private function fence(WorkflowRun $run, WorkflowTask $task, ActivityExecution $execution, string $scope): array
    {
        $execution = $execution->fresh();
        if (! in_array($execution->status, [ActivityStatus::Completed, ActivityStatus::Failed], true)
            && ($execution->activity_options['cancellation_scope_id'] ?? 'root') === $scope
            && CancellationScopeDelivery::prepared($run->fresh(), $scope) === null) {
            $scheduled = $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityScheduled)
                ->where('payload->activity_execution_id', $execution->id)
                ->sole();
            $size = $scheduled->payload['parallel_group_size'] ?? 1;
            $kind = $size > 1 ? 'parallel'
                : (\Workflow\V2\Support\CooperativeCancellationDelivery::callKindAt(
                    $run->fresh(),
                    $execution->sequence
                ) ?? 'activity');
            $base = $scheduled->payload['parallel_group_base_sequence'] ?? $execution->sequence;
            $mixed = $size > 1 && $run->activityExecutions()
                ->whereBetween('sequence', [$base, $base + $size - 1])
                ->get()
                ->contains(static fn (ActivityExecution $member): bool =>
                                    ($member->activity_options['cancellation_scope_id'] ?? 'root') !== $scope);
            CancellationScopeDelivery::prepare(
                $run,
                $task,
                $scope,
                CancellationScopeRequests::context($run, $scope)->requestId,
                $mixed ? $base + $size : $base,
                $mixed ? 'selection_handle' : $kind,
                '1.20',
                $mixed ? 1 : $size,
                $mixed ? $execution->sequence : null,
                1
            );
        }
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
            if (CancellationScopeDelivery::prepared($run->fresh(), $scope) === null) {
                CancellationScopeDelivery::prepare(
                    $run,
                    $task,
                    $scope,
                    CancellationScopeRequests::context($run, $scope)->requestId,
                    4,
                    'activity',
                    '1.20'
                );
                $history = $run->historyEvents()
                    ->count();
            }
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
