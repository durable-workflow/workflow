<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\ActivityCancellationAcknowledgement;
use Workflow\V2\Support\CancellationScopeDelivery;
use Workflow\V2\Support\CancellationScopeDescendants;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\CancellationScopeRequests;
use Workflow\V2\Support\CooperativeCancellationDelivery;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\ScopedActivityCancellation;
use Workflow\V2\Support\ScopedCancellationPreparation;
use Workflow\V2\Support\ScopedWaitCancellation;
use Workflow\V2\WorkflowStub;

final class V2CancellationScopeDescendantsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        Carbon::setTestNow('2026-10-04T00:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testPreparationFreezesUnshieldedDescendantsUnderTheOriginalRootAndBudget(): void
    {
        [$run, $task, $scopes] = $this->tree();
        $before = $task->fresh()
            ->getAttributes();
        $request = CancellationScopeRequests::request($run, $scopes['parent'], '1.20', 30, 'original');
        Carbon::setTestNow('2026-10-04T00:00:09Z');
        $prepared = $this->prepare($run, $task, $scopes['parent']);
        $members = $prepared->payload['descendant_members'];
        $this->assertSame([$scopes['child'], $scopes['grandchild']], array_column($members, 'scope_id'));
        foreach ($members as $index => $member) {
            $this->assertSame(
                $request->payload['request_id'],
                $member['cancellation']['root_context']['root_request_id']
            );
            $this->assertSame('2026-10-04T00:00:00.000000Z', $member['cancellation']['root_context']['requested_at']);
            $this->assertSame('2026-10-04T00:00:30.000000Z', $member['authority_deadline_at']);
            $this->assertSame(
                array_slice([$scopes['parent'], $scopes['child'], $scopes['grandchild']], 0, $index + 2),
                array_column($member['cancellation']['lineage'], 'scope_id')
            );
            $this->assertSame([], $member['activity_members']);
            $this->assertSame([], $member['timer_members']);
            $this->assertSame([], $member['wait_members']);
            $this->assertSame([], $member['child_members']);
        }
        foreach (['shield', 'shield_child', 'sibling'] as $name) {
            $this->assertNull(CancellationScopeRequests::context($run->fresh(), $scopes[$name]));
        }
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertNull($run->fresh()->cancellation_request_command_id);
        $this->assertSame(
            3,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeRequested)->count()
        );
    }

    public function testColdRetryAndReplacementKeepTheOriginalPreparationWithoutGrowingHistory(): void
    {
        [$run, $task, $scopes] = $this->tree();
        CancellationScopeRequests::request($run, $scopes['parent'], '1.20', 30);
        $first = $this->prepare($run, $task, $scopes['parent']);
        $count = $run->historyEvents()
            ->count();
        Carbon::setTestNow('2026-10-04T00:00:20Z');
        $replacement = $task->fresh();
        $replacement->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
        ])->save();
        $retry = $this->prepare($run->fresh(), $replacement, $scopes['parent']);
        $this->assertSame($first->id, $retry->id);
        $this->assertSameJsonObject($first->payload, $retry->payload);
        $this->assertSame($count, $run->historyEvents()->count());
        $run->forceFill([
            'status' => RunStatus::Terminated,
        ])->save();
        Carbon::setTestNow('2026-10-04T00:01:00Z');
        $this->assertSameJsonObject(
            $first->payload,
            CancellationScopeDelivery::prepared($run->fresh(), $scopes['parent'])->payload
        );
    }

    public function testCompetingChildRootRemainsAcceptedAndItsOriginalConflictIsFrozen(): void
    {
        [$run, $task, $scopes] = $this->tree();
        $child = CancellationScopeRequests::request($run, $scopes['child'], '1.20', 20, 'independent');
        Carbon::setTestNow('2026-10-04T00:00:05Z');
        $parent = CancellationScopeRequests::request($run, $scopes['parent'], '1.20', 30, 'ancestor');
        $prepared = $this->prepare($run, $task, $scopes['parent']);
        $members = $prepared->payload['descendant_members'];
        $conflict = $run->historyEvents()
            ->where('event_type', HistoryEventType::CancellationScopeRequestConflicted)->sole();
        $this->assertSame($child->id, $members[0]['request_history_event_id']);
        $this->assertSame($conflict->id, $members[0]['propagation_history_event_id']);
        $this->assertSame($child->payload['request_id'], $members[0]['request_id']);
        foreach ($members as $member) {
            $this->assertSame(
                $child->payload['request_id'],
                $member['cancellation']['root_context']['root_request_id']
            );
            $this->assertSame('2026-10-04T00:00:20.000000Z', $member['authority_deadline_at']);
        }
        $this->assertSame(
            $parent->payload['request_id'],
            $conflict->payload['incoming_cancellation']['root_context']['root_request_id']
        );
        $this->assertSame('2026-10-04T00:00:35.000000Z', $prepared->payload['authority_deadline_at']);
        $reference = ScopedCancellationPreparation::forScope($run->fresh(), $scopes['child'], $prepared->id);
        $this->assertSame($prepared->id, $reference->historyEventId);
        $this->assertSame($child->id, $reference->requestHistoryEventId);
        $this->assertSame($child->payload['request_id'], $reference->context->rootContext->rootRequestId);
        $this->assertSame('2026-10-04T00:00:20.000000Z', $reference->authorityDeadlineAt);
        $count = $run->historyEvents()
            ->count();
        $this->assertSame($prepared->id, $this->prepare($run->fresh(), $task, $scopes['parent'])->id);
        $this->assertSame($count, $run->historyEvents()->count());
    }

    public function testPreparationFreezesEveryDescendantOperationWithoutStartingCancellationEffects(): void
    {
        [$run, $task, $scopes] = $this->tree();
        $result = app(DefaultWorkflowTaskBridge::class)->checkpointCancellationScopePrefix(
            $task->id,
            'original',
            1,
            'descendant-operations',
            7,
            [
                [
                    'type' => 'schedule_activity',
                    'activity_type' => 'descendant-activity',
                    'arguments' => Serializer::serializeWithCodec('avro', []),
                    'payload_codec' => 'avro',
                    'cancellation_scope_id' => $scopes['child'],
                ],
                [
                    'type' => 'start_timer',
                    'delay_seconds' => 3600,
                    'cancellation_scope_id' => $scopes['grandchild'],
                ],
                [
                    'type' => 'start_child_workflow',
                    'workflow_type' => 'descendant-workflow',
                    'arguments' => Serializer::serializeWithCodec('avro', []),
                    'payload_codec' => 'avro',
                    'cancellation_policy' => 'try_cancel',
                    'cancellation_scope_id' => $scopes['child'],
                ],
            ],
            '1.20',
        );
        $this->assertTrue($result['checkpointed'], $result['reason'] ?? '');
        WorkflowHistoryEvent::record($run, HistoryEventType::SignalWaitOpened, [
            'sequence' => 10,
            'signal_wait_id' => 'descendant-wait',
            'signal_name' => 'ready',
            'cancellation_scope_id' => $scopes['child'],
        ], $task);
        $beforeActivities = ActivityExecution::query()->get()->map->getAttributes()->all();
        $beforeTimers = WorkflowTimer::query()->get()->map->getAttributes()->all();
        $beforeTask = $task->fresh()
            ->getAttributes();
        CancellationScopeRequests::request($run, $scopes['parent'], '1.20', 30);
        $prepared = $this->prepare($run, $task, $scopes['parent'], 11);
        $members = $prepared->payload['descendant_members'];
        $this->assertCount(1, $members[0]['activity_members']);
        $this->assertCount(1, $members[0]['child_members']);
        $this->assertCount(1, $members[0]['wait_members']);
        $this->assertCount(1, $members[1]['timer_members']);
        $this->assertSame($beforeActivities, ActivityExecution::query()->get()->map->getAttributes()->all());
        $this->assertSame($beforeTimers, WorkflowTimer::query()->get()->map->getAttributes()->all());
        $this->assertSame($beforeTask, $task->fresh()->getAttributes());
        $this->assertSameJsonObject(
            $prepared->payload,
            CancellationScopeDelivery::prepared($run->fresh(), $scopes['parent'])->payload
        );
        $this->expectExceptionMessage('cancellation_scope_activity_fence_not_established');
        CancellationScopeDelivery::record(
            $run,
            $task,
            $scopes['parent'],
            $prepared->payload['request_id'],
            11,
            'timer',
            '1.20'
        );
    }

    public function testNewShieldCannotEscapePreparedParentButOriginalOpenStillReplays(): void
    {
        [$run, $task, $scopes] = $this->tree();
        CancellationScopeRequests::request($run, $scopes['parent'], '1.20', 30);
        $prepared = $this->prepare($run, $task, $scopes['parent']);
        $original = CancellationScopeHistory::open($run->fresh(), $task, 2, '1.20', $scopes['parent']);
        $this->assertSame($scopes['child'], $original->payload['scope_id']);
        $count = $run->historyEvents()
            ->count();
        try {
            CancellationScopeHistory::open($run->fresh(), $task, 7, '1.20', $scopes['parent'], true);
            $this->fail('A late shield escaped a prepared subtree.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_parent_delivery_prepared', $error->getMessage());
        }
        $this->assertSame($count, $run->historyEvents()->count());
        try {
            CancellationScopeHistory::open($run->fresh(), $task, 7, '1.20', $scopes['sibling']);
            $this->fail('An unrelated opening consumed the reserved delivery position.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_command_sequence_reserved', $error->getMessage());
        }
        $this->assertSame($count, $run->historyEvents()->count());
        CancellationScopeDelivery::record(
            $run->fresh(),
            $task,
            $scopes['parent'],
            $prepared->payload['request_id'],
            7,
            'timer',
            '1.20'
        );
        CancellationScopeHistory::open($run->fresh(), $task, 8, '1.20', $scopes['sibling']);
        $this->assertSameJsonObject(
            $prepared->payload,
            CancellationScopeDelivery::prepared($run->fresh(), $scopes['parent'])->payload
        );
    }

    public static function inheritedActivityRoots(): iterable
    {
        yield 'inherited root' => [false];
        yield 'independently accepted child root' => [true];
    }

    public static function inheritedWaitKinds(): iterable
    {
        yield 'signal with timeout' => ['signal'];
        yield 'condition with timeout' => ['condition'];
    }

    public function testInheritedWaitPolicyRequiresOriginalActivityStopBeforeParentDelivery(): void
    {
        [$run, $task, $scopes] = $this->tree();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $prefix = $bridge->checkpointCancellationScopePrefix(
            $task->id,
            'original',
            1,
            'inherited-wait-activity',
            7,
            [[
                'type' => 'schedule_activity',
                'activity_type' => 'descendant-activity',
                'arguments' => Serializer::serializeWithCodec('avro', []),
                'payload_codec' => 'avro',
                'cancellation_policy' => 'wait_cancellation_completed',
                'cancellation_scope_id' => $scopes['child'],
            ]],
            '1.20'
        );
        $this->assertTrue($prefix['checkpointed'], $prefix['reason'] ?? '');
        $activity = $run->activityExecutions()
            ->sole();
        $activityTask = $run->tasks()
            ->where('task_type', TaskType::Activity)
            ->where('payload->activity_execution_id', $activity->id)
            ->sole();
        $activityBridge = app(ActivityTaskBridge::class);
        $attempt = $activityBridge->claimStatus($activityTask->id, 'activity-owner');
        $this->assertTrue($attempt['claimed'], $attempt['reason'] ?? '');
        $request = CancellationScopeRequests::request($run->fresh(), $scopes['parent'], '1.20', 30);
        $prepared = $this->prepare($run->fresh(), $task, $scopes['parent'], 8);
        $beforeClaim = $task->fresh()
            ->getAttributes();
        $first = $bridge->deliverCancellationScope(
            $task->id,
            'original',
            1,
            $scopes['parent'],
            $request->payload['request_id'],
            8,
            'timer',
            protocolVersion: '1.20'
        );
        $this->assertFalse($first['delivered']);
        $this->assertSame('cancellation_scope_activity_stop_not_acknowledged', $first['reason']);
        $this->assertTrue($first['activity_cancellations'][0]['waiting_for_stop']);
        $this->assertSame(ActivityStatus::Cancelled, $activity->fresh()->status);
        $this->assertNull(CancellationScopeDelivery::recorded($run->fresh(), $scopes['parent']));
        $this->assertFalse($activityBridge->complete(
            $attempt['activity_attempt_id'],
            Serializer::serializeWithCodec('avro', 'stale'),
            'avro'
        )['recorded']);
        $childRequest = CancellationScopeRequests::context($run->fresh(), $scopes['child'])->requestId;
        $this->assertFalse(ActivityCancellationAcknowledgement::recordStopped(
            $attempt['activity_attempt_id'],
            'activity-owner',
            $request->payload['request_id']
        )['acknowledged']);
        $this->assertTrue(ActivityCancellationAcknowledgement::recordStopped(
            $attempt['activity_attempt_id'],
            'activity-owner',
            $childRequest
        )['acknowledged']);
        $retry = $bridge->deliverCancellationScope(
            $task->id,
            'original',
            1,
            $scopes['parent'],
            $request->payload['request_id'],
            8,
            'timer',
            protocolVersion: '1.20'
        );
        $this->assertTrue($retry['delivered'], $retry['reason'] ?? '');
        $this->assertFalse($retry['activity_cancellations'][0]['waiting_for_stop']);
        $this->assertSame(
            $first['activity_cancellations'][0]['history_event_id'],
            $retry['activity_cancellations'][0]['history_event_id']
        );
        $this->assertSame($beforeClaim, $task->fresh()->getAttributes());
        $this->assertNull(CancellationScopeDelivery::prepared($run->fresh(), $scopes['child']));
        $this->assertSame($prepared->id, $retry['preparation_history_event_id']);
    }

    #[DataProvider('inheritedWaitKinds')]
    public function testMixedInheritedDeliveryResumesCommittedWaitAfterClaimReplacement(string $kind): void
    {
        [$run, $task, $scopes] = $this->tree();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $prefix = $bridge->checkpointCancellationScopePrefix(
            $task->id,
            'original',
            1,
            'mixed-descendant-prefix',
            7,
            [
                [
                    'type' => 'schedule_activity',
                    'activity_type' => 'descendant-activity',
                    'arguments' => Serializer::serializeWithCodec('avro', []),
                    'payload_codec' => 'avro',
                    'cancellation_scope_id' => $scopes['child'],
                ], [
                    'type' => 'start_timer',
                    'delay_seconds' => 3600,
                    'cancellation_scope_id' => $scopes['grandchild'],
                ], [
                    'type' => 'start_child_workflow',
                    'workflow_type' => 'descendant-workflow',
                    'arguments' => Serializer::serializeWithCodec('avro', []),
                    'payload_codec' => 'avro',
                    'cancellation_policy' => 'try_cancel',
                    'cancellation_scope_id' => $scopes['child'],
                ],
            ],
            '1.20'
        );
        $this->assertTrue($prefix['checkpointed'], $prefix['reason'] ?? '');
        $completed = $bridge->complete($task->id, [[
            'type' => 'open_' . $kind . '_wait',
            'cancellation_scope_id' => $scopes['child'],
            'timeout_seconds' => 3600,
            ...($kind === 'signal' ? [
                'signal_name' => 'ready',
            ] : [
                'condition_key' => 'ready',
            ]),
        ]]);
        $this->assertTrue($completed['completed'], $completed['reason'] ?? '');
        $claim = $run->tasks()
            ->create([
                'namespace' => $run->namespace,
                'task_type' => TaskType::Workflow,
                'status' => TaskStatus::Leased,
                'lease_owner' => 'first-delivery-owner',
                'attempt_count' => 1,
                'lease_expires_at' => now()
                    ->addMinutes(5),
                'available_at' => now(),
                'connection' => $run->connection,
                'queue' => $run->queue,
                'compatibility' => $run->compatibility,
            ]);
        $run->forceFill([
            'run_deadline_at' => now()->addSeconds(25),
        ])->save();
        $request = CancellationScopeRequests::request($run->fresh(), $scopes['parent'], '1.20', 30);
        Carbon::setTestNow('2026-10-04T00:00:09Z');
        $prepared = $this->prepare($run->fresh(), $claim, $scopes['parent'], 11);
        $reference = ScopedCancellationPreparation::forScope($run->fresh(), $scopes['child'], $prepared->id);
        $wait = $reference->waitMembers[0];
        $receipt = ScopedWaitCancellation::fence(
            $run->fresh(),
            $claim,
            $wait['wait_id'],
            $reference->scopeId,
            $reference->requestId,
            '1.20',
            $prepared->id
        );
        $this->assertTrue($receipt['cancelled']);
        $this->assertTrue($receipt['timer_cancellation']['fenced']);
        $beforeReplacement = $run->historyEvents()
            ->count();
        $originalClaim = $claim->fresh();
        $claim->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
        ])->save();
        $run->forceFill([
            'run_deadline_at' => now()->addSeconds(100),
        ])->save();
        Carbon::setTestNow('2026-10-04T00:00:10Z');
        try {
            ScopedWaitCancellation::fence(
                $run->fresh(),
                $originalClaim,
                $wait['wait_id'],
                $reference->scopeId,
                $reference->requestId,
                '1.20',
                $prepared->id
            );
            $this->fail('The prior hosting attempt retained authority after replacement.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_workflow_claim_mismatch', $error->getMessage());
        }
        $this->assertSame($beforeReplacement, $run->historyEvents()->count());
        $this->assertSame($receipt, ScopedWaitCancellation::fence(
            $run->fresh(),
            $claim,
            $wait['wait_id'],
            $reference->scopeId,
            $reference->requestId,
            '1.20',
            $prepared->id
        ));
        $result = $bridge->deliverCancellationScope(
            $claim->id,
            'replacement',
            2,
            $scopes['parent'],
            $request->payload['request_id'],
            11,
            'timer',
            protocolVersion: '1.20'
        );
        $this->assertTrue($result['delivered'], $result['reason'] ?? '');
        $this->assertCount(1, $result['wait_cancellations']);
        $this->assertCount(2, $result['timer_cancellations']);
        $this->assertCount(1, $result['activity_cancellations']);
        $this->assertCount(1, $result['child_cancellations']);
        $this->assertSame($receipt['history_event_id'], $result['wait_cancellations'][0]['history_event_id']);
        foreach ($run->historyEvents()->whereIn('event_type', [
            HistoryEventType::TimerCancelled, HistoryEventType::SignalWaitCancelled,
            HistoryEventType::ConditionWaitCancelled, HistoryEventType::ActivityCancelled,
            HistoryEventType::ChildCancellationRequested,
        ])->get() as $event) {
            $snapshot = $event->payload['cancellation_scope'];
            $this->assertSame('2026-10-04T00:00:25.000000Z', $snapshot['authority_deadline_at']);
            $this->assertSame(
                $request->payload['request_id'],
                $snapshot['cancellation']['root_context']['root_request_id']
            );
            if ($event->event_type !== HistoryEventType::ActivityCancelled) {
                $this->assertSame($prepared->id, $snapshot['preparation_history_event_id']);
            }
        }
        $child = WorkflowRun::query()->findOrFail($result['child_cancellations'][0]['child_workflow_run_id']);
        $childContext = CooperativeCancellationDelivery::context($child);
        $this->assertSame($request->payload['request_id'], $childContext->rootRequestId);
        $this->assertSame('2026-10-04T00:00:25.000000Z', $childContext->deadline()->toISOString());
        $this->assertSame(
            1,
            $run->historyEvents()->where('event_type', HistoryEventType::CancellationScopeDeliveryPrepared)->count()
        );
        $this->assertSame(
            1,
            $run->historyEvents()->where('event_type', HistoryEventType::CancellationScopeDelivered)->count()
        );
        $recorded = CancellationScopeDelivery::recorded($run->fresh(), $scopes['parent']);
        $this->assertNotNull($recorded);
        $count = $run->historyEvents()
            ->count();
        $this->assertSame($result, $bridge->deliverCancellationScope(
            $claim->id,
            'replacement',
            2,
            $scopes['parent'],
            $request->payload['request_id'],
            11,
            'timer',
            protocolVersion: '1.20'
        ));
        $this->assertSame($count, $run->historyEvents()->count());
        Carbon::setTestNow('2026-10-04T00:00:40Z');
        $cold = CancellationScopeDelivery::recorded($run->fresh(), $scopes['parent']);
        $this->assertSame($recorded->id, $cold->id);
        $this->assertSameJsonObject($recorded->payload, $cold->payload);
        $expired = $bridge->deliverCancellationScope(
            $claim->id,
            'replacement',
            2,
            $scopes['parent'],
            $request->payload['request_id'],
            11,
            'timer',
            protocolVersion: '1.20'
        );
        $this->assertFalse($expired['delivered']);
        $this->assertSame('cancellation_scope_authority_expired', $expired['reason']);
        $this->assertSame($count, $run->historyEvents()->count());
    }

    #[DataProvider('inheritedActivityRoots')]
    public function testInheritedActivityFenceUsesOriginalPreparationAndPreservesExcludedWork(bool $independent): void
    {
        [$run, $task, $scopes] = $this->tree();
        $operations = [];
        foreach (['child', 'grandchild', 'shield', 'sibling'] as $name) {
            $operations[] = [
                'type' => 'schedule_activity',
                'activity_type' => $name . '-activity',
                'arguments' => Serializer::serializeWithCodec('avro', []),
                'payload_codec' => 'avro',
                'cancellation_scope_id' => $scopes[$name],
            ];
        }
        $checkpoint = app(DefaultWorkflowTaskBridge::class)->checkpointCancellationScopePrefix(
            $task->id,
            'original',
            1,
            'inherited-activity-prefix',
            7,
            $operations,
            '1.20'
        );
        $this->assertTrue($checkpoint['checkpointed'], $checkpoint['reason'] ?? '');
        $childRequest = $independent
            ? CancellationScopeRequests::request($run->fresh(), $scopes['child'], '1.20', 20, 'independent') : null;
        $run->forceFill([
            'run_deadline_at' => now()
                ->addSeconds(5),
        ])->save();
        $parentRequest = CancellationScopeRequests::request($run->fresh(), $scopes['parent'], '1.20', 30);
        $prepared = $this->prepare($run->fresh(), $task, $scopes['parent'], 11);
        $run->forceFill([
            'run_deadline_at' => now()
                ->addSeconds(100),
        ])->save();
        Carbon::setTestNow('2026-10-04T00:00:04Z');
        $beforeClaim = $task->fresh()
            ->getAttributes();
        $receipts = [];
        foreach (['child', 'grandchild'] as $name) {
            $member = collect($prepared->payload['descendant_members'])->firstWhere('scope_id', $scopes[$name]);
            $activity = ActivityExecution::query()->findOrFail($member['activity_members'][0]['activity_execution_id']);
            $receipt = ScopedActivityCancellation::fence(
                $run->fresh(),
                $task,
                $activity->id,
                $scopes[$name],
                $member['request_id'],
                '1.20',
                $prepared->id
            );
            $this->assertTrue($receipt['fenced']);
            $this->assertSame(ActivityStatus::Cancelled, $activity->fresh()->status);
            $this->assertSame(
                ($childRequest ?? $parentRequest)
->payload['request_id'],
                $receipt['root_request_id']
            );
            $this->assertSame('2026-10-04T00:00:05.000000Z', $receipt['cancellation_scope']['authority_deadline_at']);
            $this->assertNull(CancellationScopeDelivery::prepared($run->fresh(), $scopes[$name]));
            $receipts[$name] = [$activity, $member, $receipt];
        }
        $count = $run->historyEvents()
            ->count();
        foreach ($receipts as $name => [$activity, $member, $receipt]) {
            $retry = ScopedActivityCancellation::fence(
                $run->fresh(),
                $task,
                $activity->id,
                $scopes[$name],
                $member['request_id'],
                '1.20',
                $prepared->id
            );
            $this->assertSame($receipt['history_event_id'], $retry['history_event_id']);
        }
        $this->assertSame($count, $run->historyEvents()->count());
        $this->assertSame($beforeClaim, $task->fresh()->getAttributes());
        foreach (['shield', 'sibling'] as $name) {
            $other = ActivityExecution::query()->where(
                'activity_options->cancellation_scope_id',
                $scopes[$name]
            )->sole();
            $this->assertSame(ActivityStatus::Pending, $other->status);
        }
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeDeliveryPrepared)->count()
        );
    }

    #[DataProvider('lostAuthority')]
    public function testNestedRequestsRollBackWithPreparationIfOriginalAuthorityIsLost(string $change): void
    {
        [$run, $task, $scopes] = $this->tree();
        CancellationScopeRequests::request($run, $scopes['parent'], '1.20', 30);
        $count = $run->historyEvents()
            ->count();
        $eventName = 'eloquent.created: ' . WorkflowHistoryEvent::class;
        Event::listen($eventName, static function (WorkflowHistoryEvent $event) use (
            $change,
            $task,
            $run,
            $scopes
        ): void {
            if ($event->event_type !== HistoryEventType::CancellationScopeRequested
                || ($event->payload['scope_id'] ?? null) !== $scopes['child']) {
                return;
            }
            match ($change) {
                'owner' => $task->fresh()
                    ->forceFill([
                        'lease_owner' => 'another',
                    ])->save(),
                'attempt' => $task->fresh()
                    ->forceFill([
                        'attempt_count' => 2,
                    ])->save(),
                'lease' => $task->fresh()
                    ->forceFill([
                        'lease_expires_at' => now(),
                    ])->save(),
                'terminated' => $run->fresh()
                    ->forceFill([
                        'status' => RunStatus::Terminated,
                    ])->save(),
                'deadline' => Carbon::setTestNow('2026-10-04T00:00:30Z'),
            };
        });
        try {
            $this->prepare($run, $task, $scopes['parent']);
            $this->fail('Preparation committed after authority loss.');
        } catch (LogicException $error) {
            $this->assertSame(in_array($change, ['terminated', 'deadline'], true)
                ? 'cancellation_scope_authority_expired' : 'cancellation_scope_workflow_claim_mismatch', $error->getMessage());
        } finally {
            Event::forget($eventName);
        }
        $this->assertSame($count, $run->historyEvents()->count());
        $this->assertNull(CancellationScopeRequests::context($run->fresh(), $scopes['child']));
        $this->assertNull(CancellationScopeRequests::context($run->fresh(), $scopes['grandchild']));
        $this->assertNull(CancellationScopeDelivery::prepared($run->fresh(), $scopes['parent']));
        $this->assertSame('original', $task->fresh()->lease_owner);
    }

    public static function lostAuthority(): iterable
    {
        foreach (['owner', 'attempt', 'lease', 'terminated', 'deadline'] as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('changedInventory')]
    public function testColdPreparationRejectsChangedDescendantInventory(string $field): void
    {
        [$run, $task, $scopes] = $this->tree();
        CancellationScopeRequests::request($run, $scopes['parent'], '1.20', 30);
        $event = $this->prepare($run, $task, $scopes['parent']);
        $payload = $event->payload;
        if ($field === 'missing') {
            unset($payload['descendant_members']);
        } elseif ($field === 'extra') {
            $payload['descendant_members'][0]['secret'] = 'not-for-history';
        } elseif ($field === 'cancellation') {
            $payload['descendant_members'][0]['cancellation']['root_context']['cleanup_deadline_at'] = 'invalid';
        } elseif ($field === 'order') {
            $payload['descendant_members'] = array_reverse($payload['descendant_members']);
        } else {
            $payload['descendant_members'][0][$field] = 'substituted';
        }
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $this->expectExceptionMessage('cancellation_scope_delivery_history_invalid');
        CancellationScopeDelivery::prepared($run->fresh(), $scopes['parent']);
    }

    public static function changedInventory(): iterable
    {
        foreach (['scope_id', 'parent_scope_id', 'scope_history_event_id', 'request_history_event_id',
            'request_id', 'propagation_history_event_id', 'authority_deadline_at', 'cancellation', 'order', 'missing', 'extra'] as $field) {
            yield $field => [$field];
        }
    }

    public function testDescendantPropagationCannotRunOutsidePreparationTransaction(): void
    {
        [$run, , $scopes] = $this->tree();
        CancellationScopeRequests::request($run, $scopes['parent'], '1.20', 30);
        $this->expectExceptionMessage('cancellation_scope_descendants_require_preparation_transaction');
        CancellationScopeDescendants::request($run, $scopes['parent']);
    }

    public function testOriginalRootOperationDoesNotInvalidateNestedDelivery(): void
    {
        [$run, $task, $scopes] = $this->tree();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $prefix = $bridge->checkpointCancellationScopePrefix(
            $task->id,
            'original',
            1,
            'ordinary-root-timer',
            7,
            [[
                'type' => 'start_timer',
                'delay_seconds' => 3600,
            ]],
            '1.20'
        );
        $this->assertTrue($prefix['checkpointed'], $prefix['reason'] ?? '');
        $timer = $run->timers()
            ->sole();
        $before = $timer->getAttributes();
        $request = CancellationScopeRequests::request($run, $scopes['parent'], '1.20', 30);
        $preparation = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'original',
            1,
            $scopes['parent'],
            $request->payload['request_id'],
            8,
            'timer',
            protocolVersion: '1.20'
        );
        $this->assertTrue($preparation['prepared'], $preparation['reason'] ?? '');
        $delivery = $bridge->deliverCancellationScope(
            $task->id,
            'original',
            1,
            $scopes['parent'],
            $request->payload['request_id'],
            8,
            'timer',
            protocolVersion: '1.20'
        );
        $this->assertTrue($delivery['delivered'], $delivery['reason'] ?? '');
        $this->assertSame($before, $timer->fresh()->getAttributes());
        $this->assertSame($preparation['preparation_history_event_id'], $delivery['preparation_history_event_id']);
    }

    public function testColdPreparationKeepsItsRunCeilingAfterMutableLimitsChange(): void
    {
        [$run, $task, $scopes] = $this->tree();
        $run->forceFill([
            'run_deadline_at' => now()
                ->addSeconds(25),
        ])->save();
        CancellationScopeRequests::request($run, $scopes['parent'], '1.20', 30);
        $prepared = $this->prepare($run, $task, $scopes['parent']);
        foreach ($prepared->payload['descendant_members'] as $member) {
            $this->assertSame('2026-10-04T00:00:25.000000Z', $member['authority_deadline_at']);
            $this->assertSame(
                '2026-10-04T00:00:30.000000Z',
                $member['cancellation']['root_context']['cleanup_deadline_at']
            );
        }
        $run->forceFill([
            'run_deadline_at' => now()
                ->addSeconds(10),
        ])->save();
        $this->assertSameJsonObject(
            $prepared->payload,
            CancellationScopeDelivery::prepared($run->fresh(), $scopes['parent'])->payload
        );
        Carbon::setTestNow('2026-10-04T00:00:10Z');
        $this->expectExceptionMessage('cancellation_scope_authority_expired');
        $this->prepare($run->fresh(), $task, $scopes['parent']);
    }

    public function testInheritedReferenceUsesOriginalHistoryWithoutCreatingAnotherBoundary(): void
    {
        [$run, $task, $scopes] = $this->tree();
        $request = CancellationScopeRequests::request($run, $scopes['parent'], '1.20', 30);
        $prepared = $this->prepare($run, $task, $scopes['parent']);
        $count = $run->historyEvents()
            ->count();
        foreach (['parent', 'child', 'grandchild'] as $name) {
            $reference = ScopedCancellationPreparation::forScope($run->fresh(), $scopes[$name], $prepared->id);
            $this->assertSame($prepared->id, $reference->historyEventId);
            $this->assertSame($prepared->sequence, $reference->historySequence);
            $this->assertSame($scopes['parent'], $reference->preparedScopeId);
            $this->assertSame($scopes[$name], $reference->scopeId);
            $this->assertSame($request->payload['request_id'], $reference->context->rootContext->rootRequestId);
        }
        $this->assertNull(ScopedCancellationPreparation::forScope($run->fresh(), $scopes['child']));
        $this->assertSame($count, $run->historyEvents()->count());
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeDeliveryPrepared)->count()
        );
    }

    #[DataProvider('excludedReferenceScopes')]
    public function testInheritedReferenceRejectsUnrelatedAndShieldedScopes(string $name): void
    {
        [$run, $task, $scopes] = $this->tree();
        CancellationScopeRequests::request($run, $scopes['parent'], '1.20', 30);
        $prepared = $this->prepare($run, $task, $scopes['parent']);
        $count = $run->historyEvents()
            ->count();
        try {
            ScopedCancellationPreparation::forScope($run->fresh(), $scopes[$name], $prepared->id);
            $this->fail('An excluded scope obtained the original preparation.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_descendant_not_prepared', $error->getMessage());
        }
        $this->assertSame($count, $run->historyEvents()->count());
    }

    public static function excludedReferenceScopes(): iterable
    {
        yield 'shield' => ['shield'];
        yield 'shielded descendant' => ['shield_child'];
        yield 'sibling' => ['sibling'];
    }

    public function testColdInheritedReferenceKeepsOriginalBudgetAfterReplacementAndExpiry(): void
    {
        [$run, $task, $scopes] = $this->tree();
        $run->forceFill([
            'run_deadline_at' => now()
                ->addSeconds(25),
        ])->save();
        CancellationScopeRequests::request($run, $scopes['parent'], '1.20', 30);
        $prepared = $this->prepare($run, $task, $scopes['parent']);
        $original = ScopedCancellationPreparation::forScope($run->fresh(), $scopes['child'], $prepared->id);
        $count = $run->historyEvents()
            ->count();
        $run->forceFill([
            'run_deadline_at' => now()
                ->addSeconds(100),
        ])->save();
        $task->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
        ])->save();
        Carbon::setTestNow('2026-10-04T00:00:40Z');
        $replayed = ScopedCancellationPreparation::forScope($run->fresh(), $scopes['child'], $prepared->id);
        $this->assertSame($original->historyEventId, $replayed->historyEventId);
        $this->assertSame($original->requestHistoryEventId, $replayed->requestHistoryEventId);
        $this->assertSame('2026-10-04T00:00:25.000000Z', $replayed->authorityDeadlineAt);
        $this->assertSame($original->context->toArray(), $replayed->context->toArray());
        $this->assertFalse(CancellationScopeRequests::authority($run->fresh(), $scopes['child'])['active']);
        $this->assertSame($count, $run->historyEvents()->count());
    }

    /**
     * @return array{WorkflowRun, WorkflowTask, array<string, string>}
     */
    private function tree(): array
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'descendant-preparation');
        $workflow->start();
        $run = $workflow->run()
            ->fresh();
        $task = $run->tasks()
            ->where('task_type', TaskType::Workflow)->sole();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'original',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addSeconds(60),
        ])->save();
        $scopes = [];
        $scopes['parent'] = CancellationScopeHistory::open($run, $task, 1, '1.20')->payload['scope_id'];
        $scopes['child'] = CancellationScopeHistory::open(
            $run,
            $task,
            2,
            '1.20',
            $scopes['parent']
        )->payload['scope_id'];
        $scopes['grandchild'] = CancellationScopeHistory::open(
            $run,
            $task,
            3,
            '1.20',
            $scopes['child']
        )->payload['scope_id'];
        $scopes['shield'] = CancellationScopeHistory::open(
            $run,
            $task,
            4,
            '1.20',
            $scopes['parent'],
            true
        )->payload['scope_id'];
        $scopes['shield_child'] = CancellationScopeHistory::open(
            $run,
            $task,
            5,
            '1.20',
            $scopes['shield']
        )->payload['scope_id'];
        $scopes['sibling'] = CancellationScopeHistory::open($run, $task, 6, '1.20')->payload['scope_id'];
        return [$run, $task, $scopes];
    }

    private function prepare(
        WorkflowRun $run,
        WorkflowTask $task,
        string $scope,
        int $sequence = 7
    ): WorkflowHistoryEvent {
        return CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            CancellationScopeRequests::context($run, $scope)->requestId,
            $sequence,
            'timer',
            '1.20'
        );
    }
}
