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
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\CancellationScopeDelivery;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\CancellationScopeRequests;
use Workflow\V2\Support\DefaultActivityTaskBridge;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\Support\ScopedActivityCancellation;
use Workflow\V2\Support\ScopedTimerCancellation;
use Workflow\V2\Support\WorkflowStepHistory;
use Workflow\V2\WorkflowStub;

final class V2CancellationScopeDeliveryTest extends TestCase
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

    #[DataProvider('callKinds')]
    public function testUnscheduledCallRecordsScopeBoundaryWithoutRunCancellationOrClaimMutation(string $kind): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30, 'release one scope');
        $claimBefore = $task->fresh()
            ->getAttributes();
        $event = $this->deliver($run, $task, $scope, $kind);
        $this->assertSame(HistoryEventType::CancellationScopeDelivered, $event->event_type);
        $this->assertSame($request->payload['request_id'], $event->payload['request_id']);
        $this->assertSame($scope, $event->payload['scope_id']);
        $this->assertSame(3, $event->payload['sequence']);
        $this->assertSame(4, WorkflowStepHistory::nextDurableCommandSequence($run->fresh()));
        $this->assertSameJsonObject($request->payload['cancellation'], $event->payload['cancellation']);
        $this->assertSame('2026-10-03T00:00:30.000000Z', $event->payload['authority_deadline_at']);
        $this->assertSame($claimBefore, $task->fresh()->getAttributes());
        $this->assertSame(1, $run->tasks()->count());
        $this->assertNull($run->fresh()->cancellation_request_command_id);
        $this->assertNull($run->fresh()->cancellation_delivery_sequence);
        $this->assertNull($run->fresh()->cancellation_delivered_at);
        $this->assertFalse($run->fresh()->status->isTerminal());
        $timeline = collect(HistoryTimeline::fromHistory($run->fresh()))->firstWhere('id', $event->id);
        $this->assertSame('cancellation_scope', $timeline['kind']);
        $this->assertSameJsonObject($event->payload['cancellation'], $timeline['cancellation_scope']['cancellation']);
        $this->assertSame(3, $timeline['cancellation_scope']['sequence']);
    }

    public static function callKinds(): iterable
    {
        foreach (['activity', 'local_activity', 'timer', 'condition', 'signal', 'child'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function testResponseLossAndReplacementClaimReplayOriginalBoundaryAndClockWithoutNewBudget(): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $event = $this->deliver($run, $task, $scope);
        $original = $event->payload;
        Carbon::setTestNow('2026-10-03T00:00:11Z');
        $replacement = $task->fresh();
        $replacement->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
            'lease_expires_at' => now()
                ->addSeconds(10),
        ])->save();
        $replayed = $this->deliver($run->fresh(), $replacement, $scope);
        $this->assertSame($event->id, $replayed->id);
        $this->assertSameJsonObject($original, $replayed->payload);
        $this->assertSame('2026-10-03T00:00:00.000000Z', $replayed->recorded_at->toISOString());
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeDelivered)->count()
        );
        $this->expectExceptionMessage('cancellation_scope_workflow_claim_mismatch');
        $this->deliver($run->fresh(), $task, $scope);
    }

    #[DataProvider('changedBoundaries')]
    public function testDuplicateCannotMoveOrRelabelOriginalBoundary(int $sequence, string $kind, int $span): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        $this->deliver($run, $task, $scope);
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_delivery_mismatch', static fn () =>
            CancellationScopeDelivery::record(
                $run,
                $task,
                $scope,
                $request->payload['request_id'],
                $sequence,
                $kind,
                '1.20',
                $span
            ));
    }

    public static function changedBoundaries(): iterable
    {
        yield 'move earlier' => [2, 'activity', 1];
        yield 'move later' => [4, 'activity', 1];
        yield 'change call' => [3, 'timer', 1];
        yield 'change range' => [3, 'parallel', 2];
    }

    public function testScopeAddressesHaveIndependentDeliveryRecordsAndIdentities(): void
    {
        [, $run, $task, $parent, $child] = $this->scopeTree();
        CancellationScopeRequests::request($run, $parent, '1.20', 30);
        $first = $this->deliver($run, $task, $parent);
        CancellationScopeRequests::request($run, $child, '1.20', 30);
        $request = CancellationScopeRequests::context($run, $child);
        CancellationScopeDelivery::prepare($run, $task, $child, $request->requestId, 4, 'timer', '1.20');
        $second = CancellationScopeDelivery::record($run, $task, $child, $request->requestId, 4, 'timer', '1.20');
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->payload['request_id'], $second->payload['request_id']);
        $this->assertSame($first->id, CancellationScopeDelivery::recorded($run->fresh(), $parent)->id);
        $this->assertSame($second->id, CancellationScopeDelivery::recorded($run->fresh(), $child)->id);
        $this->assertSame(5, WorkflowStepHistory::nextDurableCommandSequence($run->fresh()));
    }

    #[DataProvider('inheritedShields')]
    public function testInheritedRequestRetainsOriginalRootAndObeysParentShield(bool $shield): void
    {
        [, $run, $task, $parent, $child] = $this->scopeTree($shield);
        $root = CancellationScopeRequests::request($run, $parent, '1.20', 30, 'original');
        Carbon::setTestNow('2026-10-03T00:00:07Z');
        CancellationScopeRequests::request($run, $child, '1.20', 300, parentScopeId: $parent);
        if ($shield) {
            $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_parent_shielded', fn () =>
                $this->deliver($run, $task, $child));
            return;
        }
        $event = $this->deliver($run, $task, $child);
        $this->assertSame(
            $root->payload['request_id'],
            $event->payload['cancellation']['root_context']['root_request_id']
        );
        $this->assertSame([$parent, $child], array_column($event->payload['cancellation']['lineage'], 'scope_id'));
        $this->assertSame('2026-10-03T00:00:30.000000Z', $event->payload['authority_deadline_at']);
    }

    public static function inheritedShields(): iterable
    {
        yield 'unshielded' => [false];
        yield 'shielded' => [true];
    }

    public function testDirectShieldRequestDeliversButCurrentAncestorCeilingStillFencesRetries(): void
    {
        [$workflow, $run, $task, , $scope] = $this->scopeTree(true);
        $accepted = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $workflow->requestCancellation('run ceiling', 12);
        $event = $this->deliver($run, $task, $scope);
        $this->assertSameJsonObject($accepted->payload['cancellation'], $event->payload['cancellation']);
        $this->assertSame('2026-10-03T00:00:12.000000Z', $event->payload['authority_deadline_at']);
        Carbon::setTestNow('2026-10-03T00:00:12Z');
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_authority_expired', fn () =>
            $this->deliver($run, $task, $scope));
        $this->assertSame($event->id, CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
    }

    public function testLaterAncestorDeadlineDoesNotRewriteAnEarlierDeliveryReceipt(): void
    {
        [$workflow, $run, $task, , $scope] = $this->scopeTree();
        CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $event = $this->deliver($run, $task, $scope);
        Carbon::setTestNow('2026-10-03T00:00:01Z');
        $workflow->requestCancellation('shorter run budget', 5);
        $duplicate = $this->deliver($run, $task, $scope);
        $this->assertSame($event->id, $duplicate->id);
        $this->assertSame('2026-10-03T00:00:30.000000Z', $duplicate->payload['authority_deadline_at']);
        $this->assertSame(
            '2026-10-03T00:00:06.000000Z',
            CancellationScopeRequests::authority($run, $scope)['deadline_at']
        );
        Carbon::setTestNow('2026-10-03T00:00:06Z');
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_authority_expired', fn () =>
            $this->deliver($run, $task, $scope));
    }

    #[DataProvider('memberships')]
    public function testRecordedOperationMustBelongToTheExactScope(string $layout, bool $valid): void
    {
        [, $run, $task, $parent, $scope] = $this->scopeTree();
        $address = $valid ? $scope : $parent;
        $payload = [
            'sequence' => 3,
        ];
        if ($layout !== 'nested') {
            $payload['cancellation_scope_id'] = $address;
        }
        if ($layout !== 'flat') {
            $payload['activity'] = [
                'cancellation_scope_id' => $layout === 'contradictory' ? $parent : $address,
            ];
        }
        if ($layout === 'contradictory') {
            // Deliberately inject contradictory stored metadata, bypassing the
            // normal admission schema, to exercise the cold-history fence.
            $event = WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
                'sequence' => 3,
                'activity' => [
                    'cancellation_scope_id' => $scope,
                ],
            ]);
            $event->forceFill([
                'payload' => $payload,
            ])->save();
            $run->refresh();
        } else {
            $this->schedule(
                $run,
                $layout === 'flat' ? HistoryEventType::TimerScheduled : HistoryEventType::ActivityScheduled,
                $payload
            );
        }
        CancellationScopeRequests::request($run, $scope, '1.20');
        if (! $valid || $layout === 'contradictory') {
            $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_delivery_membership_mismatch', fn () =>
                $this->deliver($run, $task, $scope, $layout === 'flat' ? 'timer' : 'activity'));
            return;
        }
        $this->assertSame(
            3,
            $this->deliver($run, $task, $scope, $layout === 'flat' ? 'timer' : 'activity')
->payload['sequence']
        );
    }

    public static function memberships(): iterable
    {
        foreach (['flat', 'nested'] as $layout) {
            yield $layout . ' matching' => [$layout, true];
            yield $layout . ' different' => [$layout, false];
        }
        yield 'contradictory' => ['contradictory', true];
    }

    public function testHistoricalRootOperationCannotBeReparentedByDelivery(): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        $this->schedule($run, HistoryEventType::ActivityScheduled, [
            'sequence' => 3,
        ]);
        CancellationScopeRequests::request($run, $scope, '1.20');
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_delivery_membership_mismatch', fn () =>
            $this->deliver($run, $task, $scope));
    }

    #[DataProvider('resolutionOrdering')]
    public function testCallResolutionUsesCanonicalHistoryOrderInsteadOfHostClock(bool $resolvedFirst): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        $this->schedule($run, HistoryEventType::ActivityScheduled, [
            'sequence' => 3,
            'activity' => [
                'cancellation_scope_id' => $scope,
            ],
        ]);
        if ($resolvedFirst) {
            $this->schedule($run, HistoryEventType::ActivityCompleted, [
                'sequence' => 3,
            ]);
        }
        CancellationScopeRequests::request($run, $scope, '1.20');
        if (! $resolvedFirst) {
            $this->schedule($run, HistoryEventType::ActivityCompleted, [
                'sequence' => 3,
            ]);
            $this->assertSame(3, $this->deliver($run, $task, $scope)->payload['sequence']);
            return;
        }
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_delivery_not_eligible', fn () =>
            $this->deliver($run, $task, $scope));
    }

    public static function resolutionOrdering(): iterable
    {
        yield 'completion before request' => [true];
        yield 'completion after request' => [false];
    }

    #[DataProvider('parallelBoundaries')]
    public function testParallelAndSelectionBoundariesAllowUnaffectedMembers(bool $selection, bool $mixed): void
    {
        [, $run, $task, $parent, $scope] = $this->scopeTree();
        for ($index = 0; $index < 2; ++$index) {
            $this->schedule($run, HistoryEventType::ActivityScheduled, [
                'sequence' => 3 + $index,
                'activity' => [
                    'cancellation_scope_id' => $mixed && $index === 1 ? $parent : $scope,
                ],
                ...ParallelChildGroup::itemMetadata(3, 2, $index, 'activity'),
            ]);
        }
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        $delivery = static fn () => CancellationScopeDelivery::record(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            $selection ? 5 : 3,
            $selection ? 'selection_handle' : 'parallel',
            '1.20',
            $selection ? 1 : 2,
            $selection ? 3 : null,
            $selection ? 2 : 1
        );
        CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            $selection ? 5 : 3,
            $selection ? 'selection_handle' : 'parallel',
            '1.20',
            $selection ? 1 : 2,
            $selection ? 3 : null,
            $selection ? 2 : 1
        );
        $this->fenceActivities($run, $task, $scope);
        $event = $delivery();
        $this->assertSame($selection ? 5 : 3, $event->payload['sequence']);
        $this->assertSame($selection ? 6 : 5, WorkflowStepHistory::nextDurableCommandSequence($run->fresh()));
        if ($mixed) {
            $this->assertSame(
                ActivityStatus::Pending,
                $run->activityExecutions()
                    ->where('sequence', 4)
                    ->sole()
->status
            );
            $this->assertNull(CancellationScopeRequests::context($run->fresh(), $parent));
        }
    }

    public static function parallelBoundaries(): iterable
    {
        yield 'parallel same scope' => [false, false];
        yield 'parallel mixed scopes' => [false, true];
        yield 'selection same scope' => [true, false];
        yield 'selection mixed scopes' => [true, true];
    }

    #[DataProvider('descendantAwaits')]
    public function testAncestorRequestDeliversAtAuthoredUnshieldedDescendantAwait(bool $deep): void
    {
        [, $run, $task, $parent, $child] = $this->scopeTree();
        $sequence = $deep ? 4 : 3;
        $member = $deep
            ? CancellationScopeHistory::open($run, $task, 3, '1.20', $child)->payload['scope_id'] : $child;
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $checkpoint = $bridge->checkpointCancellationScopePrefix(
            $task->id,
            'original',
            1,
            'descendant-await',
            $sequence,
            [[
                'type' => 'start_timer',
                'delay_seconds' => 60,
                'cancellation_scope_id' => $member,
            ]],
            '1.20'
        );
        $this->assertTrue($checkpoint['checkpointed'], $checkpoint['reason'] ?? '');
        $request = CancellationScopeRequests::request($run->fresh(), $parent, '1.20', 30);
        $beforeClaim = $task->fresh()
            ->getAttributes();
        $prepared = $bridge->prepareCancellationScopeDelivery(
            $task->id,
            'original',
            1,
            $parent,
            $request->payload['request_id'],
            $sequence,
            'timer',
            protocolVersion: '1.20'
        );
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $delivered = $bridge->deliverCancellationScope(
            $task->id,
            'original',
            1,
            $parent,
            $request->payload['request_id'],
            $sequence,
            'timer',
            protocolVersion: '1.20'
        );
        $this->assertTrue($delivered['delivered'], $delivered['reason'] ?? '');
        $this->assertSame(TimerStatus::Cancelled, $run->timers()->sole()->status);
        $this->assertSame($sequence, $delivered['sequence']);
        $this->assertSame('2026-10-03T00:00:30.000000Z', $delivered['authority_deadline_at']);
        $context = CancellationScopeRequests::context($run->fresh(), $member);
        $this->assertSame($request->payload['request_id'], $context->rootContext->rootRequestId);
        $this->assertSame($beforeClaim, $task->fresh()->getAttributes());
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->assertNull($run->fresh()->cancellation_request_command_id);
        $marker = CancellationScopeDelivery::recorded($run->fresh(), $parent);
        Carbon::setTestNow('2026-10-03T00:00:31Z');
        $this->assertSame(
            $marker->getAttributes(),
            CancellationScopeDelivery::recorded($run->fresh(), $parent)->getAttributes()
        );
        $this->assertSame(
            $prepared['preparation_history_event_id'],
            CancellationScopeDelivery::prepared($run->fresh(), $parent)->id
        );
    }

    public static function descendantAwaits(): iterable
    {
        yield 'child' => [false];
        yield 'grandchild' => [true];
    }

    #[DataProvider('mixedShieldGroups')]
    public function testMixedGroupCancelsOnlyRequestedSubtreeAndSurvivorsStillPublish(
        bool $selection,
        bool $directShield
    ): void {
        [, $run, $task, $parent, $child] = $this->scopeTree();
        $shield = CancellationScopeHistory::open($run, $task, 3, '1.20', $parent, true)->payload['scope_id'];
        $sibling = CancellationScopeHistory::open($run, $task, 4, '1.20')->payload['scope_id'];
        $scopes = [$child, $shield, $sibling];
        foreach ($scopes as $index => $scope) {
            $this->schedule($run, HistoryEventType::ActivityScheduled, [
                'sequence' => 5 + $index,
                'activity' => [
                    'cancellation_scope_id' => $scope,
                ],
                ...ParallelChildGroup::itemMetadata(5, 3, $index, 'activity'),
            ]);
        }
        $target = $directShield ? $shield : $parent;
        $request = CancellationScopeRequests::request($run, $target, '1.20', 30);
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $arguments = [
            $task->id, 'original', 1, $target, $request->payload['request_id'],
            $selection ? 8 : 5, $selection ? 'selection_handle' : 'parallel',
            $selection ? 1 : 3, $selection ? 5 : null, $selection ? 3 : 1, '1.20',
        ];
        $prepared = $bridge->prepareCancellationScopeDelivery(...$arguments);
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $beforeClaim = $task->fresh()
            ->getAttributes();
        $survivingExecutions = [];
        $survivingTasks = [];
        foreach ($run->activityExecutions()->orderBy('sequence')->get() as $execution) {
            if ($execution->sequence !== ($directShield ? 6 : 5)) {
                $survivingExecutions[$execution->id] = $execution->getAttributes();
                $survivorTask = $run->tasks()
                    ->where('payload->activity_execution_id', $execution->id)
                    ->sole();
                $survivingTasks[$survivorTask->id] = $survivorTask->getAttributes();
            }
        }
        $delivered = $bridge->deliverCancellationScope(...$arguments);
        $this->assertTrue($delivered['delivered'], $delivered['reason'] ?? '');
        $this->assertSame($beforeClaim, $task->fresh()->getAttributes());
        $this->assertSame(
            ActivityStatus::Cancelled,
            $run->activityExecutions()
                ->where('sequence', $directShield ? 6 : 5)
                ->sole()
->status
        );
        foreach ($survivingExecutions as $id => $attributes) {
            $this->assertSame($attributes, ActivityExecution::query()->findOrFail($id)->getAttributes());
        }
        foreach ($survivingTasks as $id => $attributes) {
            $this->assertSame($attributes, WorkflowTask::query()->findOrFail($id)->getAttributes());
        }
        $this->assertNull(CancellationScopeRequests::context($run->fresh(), $sibling));
        $this->assertNull(CancellationScopeRequests::context($run->fresh(), $directShield ? $child : $shield));
        $marker = CancellationScopeDelivery::recorded($run->fresh(), $target);
        $beforeMarker = $marker->getAttributes();
        $beforePreparation = CancellationScopeDelivery::prepared($run->fresh(), $target)->getAttributes();
        $task->forceFill([
            'lease_owner' => 'replacement',
            'attempt_count' => 2,
        ])->save();
        $arguments[1] = 'replacement';
        $arguments[2] = 2;
        $replayed = $bridge->deliverCancellationScope(...$arguments);
        $this->assertSame($delivered['history_event_id'], $replayed['history_event_id']);
        $this->assertSame($delivered['cancellation'], $replayed['cancellation']);
        foreach ($survivingExecutions as $attributes) {
            $this->schedule($run, HistoryEventType::ActivityCompleted, [
                'sequence' => $attributes['sequence'],
            ]);
        }
        $this->assertSame(2, $run->activityExecutions()->where('status', ActivityStatus::Completed)->count());
        Carbon::setTestNow('2026-10-03T00:00:31Z');
        $this->assertSame($beforeMarker, CancellationScopeDelivery::recorded($run->fresh(), $target)->getAttributes());
        $this->assertSame(
            $beforePreparation,
            CancellationScopeDelivery::prepared($run->fresh(), $target)->getAttributes()
        );
        $this->assertSame($selection ? 9 : 8, WorkflowStepHistory::nextDurableCommandSequence($run->fresh()));
        $this->assertFalse($run->fresh()->status->isTerminal());
    }

    public static function mixedShieldGroups(): iterable
    {
        yield 'parallel ancestor request' => [false, false];
        yield 'selection ancestor request' => [true, false];
        yield 'parallel direct shield request' => [false, true];
        yield 'selection direct shield request' => [true, true];
    }

    public function testAncestorCannotDeliverAtShieldedDescendantAwaitButDirectShieldRequestCan(): void
    {
        [, $run, $task, $parent] = $this->scopeTree();
        $shield = CancellationScopeHistory::open($run, $task, 3, '1.20', $parent, true)->payload['scope_id'];
        $child = CancellationScopeHistory::open($run, $task, 4, '1.20', $shield)->payload['scope_id'];
        $this->schedule($run, HistoryEventType::TimerScheduled, [
            'sequence' => 5,
            'cancellation_scope_id' => $child,
        ]);
        $ancestor = CancellationScopeRequests::request($run, $parent, '1.20', 30);
        $this->assertRefusedWithoutMutation(
            $run,
            $task,
            'cancellation_scope_delivery_membership_mismatch',
            static fn () =>
            CancellationScopeDelivery::prepare(
                $run,
                $task,
                $parent,
                $ancestor->payload['request_id'],
                5,
                'timer',
                '1.20'
            )
        );
        $this->assertSame(TimerStatus::Pending, $run->timers()->sole()->status);
        $this->assertNull(CancellationScopeRequests::context($run->fresh(), $child));
        $direct = CancellationScopeRequests::request($run, $shield, '1.20', 30);
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $arguments = [
            $task->id,
            'original',
            1,
            $shield,
            $direct->payload['request_id'],
            5,
            'timer',
            1,
            null,
            1,
            '1.20',
        ];
        $prepared = $bridge->prepareCancellationScopeDelivery(...$arguments);
        $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
        $delivered = $bridge->deliverCancellationScope(...$arguments);
        $this->assertTrue($delivered['delivered'], $delivered['reason'] ?? '');
        $this->assertSame(TimerStatus::Cancelled, $run->timers()->sole()->status);
        $this->assertSame(
            $direct->payload['request_id'],
            CancellationScopeRequests::context($run->fresh(), $child)->rootContext->rootRequestId
        );
        $this->assertNotSame($ancestor->payload['request_id'], $direct->payload['request_id']);
    }

    #[DataProvider('unaffectedGroupMembership')]
    public function testMixedGroupRejectsInvalidUnaffectedMembershipBeforeAnyEffects(string $kind): void
    {
        [, $run, $task, $parent, $scope] = $this->scopeTree();
        for ($index = 0; $index < 2; ++$index) {
            $this->schedule($run, HistoryEventType::ActivityScheduled, [
                'sequence' => 3 + $index,
                'activity' => [
                    'cancellation_scope_id' => $index === 0 && $kind !== 'entirely unrelated' ? $scope : $parent,
                ],
                ...ParallelChildGroup::itemMetadata(3, 2, $index, 'activity'),
            ]);
        }
        $event = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityScheduled)->where('payload->sequence', 4)->sole();
        $payload = $event->payload;
        switch ($kind) {
            case 'contradictory':
                $payload['cancellation_scope_id'] = $scope;
                break;
            case 'null flat':
                $payload['cancellation_scope_id'] = null;
                break;
            case 'null nested':
                $payload['activity']['cancellation_scope_id'] = null;
                break;
            case 'unknown':
                $payload['activity']['cancellation_scope_id'] = 'foreign-scope';
                break;
            case 'future opening':
                $payload['activity']['cancellation_scope_id'] = CancellationScopeHistory::open(
                    $run,
                    $task,
                    5,
                    '1.20'
                )->payload['scope_id'];
                break;
        }
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $run->refresh();
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $beforeExecutions = $run->activityExecutions()
            ->orderBy('sequence')
            ->get()
            ->map->getAttributes()
            ->all();
        $this->assertRefusedWithoutMutation(
            $run,
            $task,
            'cancellation_scope_delivery_membership_mismatch',
            static fn () =>
            CancellationScopeDelivery::prepare(
                $run,
                $task,
                $scope,
                $request->payload['request_id'],
                3,
                'parallel',
                '1.20',
                2
            )
        );
        $this->assertSame(
            $beforeExecutions,
            $run->activityExecutions()
                ->orderBy('sequence')
                ->get()
                ->map->getAttributes()
                ->all()
        );
    }

    public static function unaffectedGroupMembership(): iterable
    {
        foreach ([
            'entirely unrelated',
            'contradictory',
            'null flat',
            'null nested',
            'unknown',
            'future opening',
        ] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('claimFailures')]
    public function testClaimFencesApplyBeforeMutation(string $kind): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        CancellationScopeRequests::request($run, $scope, '1.20');
        $task->forceFill(match ($kind) {
            'expired' => [
                'lease_expires_at' => now(),
            ],
            'ready' => [
                'status' => TaskStatus::Ready,
            ],
            'activity' => [
                'task_type' => TaskType::Activity,
            ],
            'owner' => [
                'lease_owner' => '',
            ],
            'attempt' => [
                'attempt_count' => 0,
            ],
            'namespace' => [
                'namespace' => 'foreign',
            ],
        })->save();
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_workflow_claim_mismatch', fn () =>
            $this->deliver($run, $task, $scope));
    }

    public static function claimFailures(): iterable
    {
        foreach (['expired', 'ready', 'activity', 'owner', 'attempt', 'namespace'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function testRunClosureRefusesDeliveryButKeepsColdReceiptInspectable(): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        CancellationScopeRequests::request($run, $scope, '1.20');
        $event = $this->deliver($run, $task, $scope);
        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_at' => now(),
        ])->save();
        $this->assertSame($event->id, CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
        $this->assertRefusedWithoutMutation($run, $task, 'cancellation_scope_authority_expired', fn () =>
            $this->deliver($run, $task, $scope));
    }

    #[DataProvider('invalidRequests')]
    public function testWrongIdentityAndPublishedProtocolCannotCreateDelivery(string $kind, string $reason): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        $request = CancellationScopeRequests::request($run, $scope, '1.20');
        $this->assertRefusedWithoutMutation($run, $task, $reason, static fn () => CancellationScopeDelivery::record(
            $run,
            $task,
            $kind === 'unknown' ? 'foreign-scope' : $scope,
            $kind === 'identity' ? 'foreign-request' : $request->payload['request_id'],
            3,
            'activity',
            $kind === 'protocol' ? '1.19' : '1.20'
        ));
    }

    public static function invalidRequests(): iterable
    {
        yield 'foreign request' => ['identity', 'cancellation_scope_request_mismatch'];
        yield 'foreign scope' => ['unknown', 'cancellation_scope_not_recorded'];
        yield 'published protocol' => ['protocol', 'cancellation_scope_requires_protocol_1_20'];
    }

    #[DataProvider('corruptReceipts')]
    public function testColdReadRejectsContradictoryDelivery(string $field, mixed $value): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        CancellationScopeRequests::request($run, $scope, '1.20');
        $event = $this->deliver($run, $task, $scope);
        $payload = $event->payload;
        $payload[$field] = $value;
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $this->expectExceptionMessage('cancellation_scope_delivery_history_invalid');
        CancellationScopeDelivery::recorded($run->fresh(), $scope);
    }

    public static function corruptReceipts(): iterable
    {
        yield 'foreign run' => ['workflow_run_id', 'other-run'];
        yield 'foreign request' => ['request_id', 'other-request'];
        yield 'wrong schema' => ['schema', 'other-schema'];
        yield 'invalid sequence' => ['sequence', 0];
        yield 'invalid kind' => ['call_kind', 'unknown'];
        yield 'extended ceiling' => ['authority_deadline_at', '2026-10-03T00:05:00.000000Z'];
        yield 'noncanonical clock' => ['authority_deadline_at', 'tomorrow'];
    }

    #[DataProvider('reservedAdmissionPaths')]
    public function testPreparedScopeBlocksEveryNewAdmissionPathBeforeMutation(string $path, string $reason): void
    {
        [, $run, $task, $parent, $scope] = $this->scopeTree();
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            3,
            'local_activity',
            '1.20'
        );
        $history = $run->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->toArray();
        $claim = $task->fresh()
            ->getAttributes();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $local = [
            'type' => 'record_local_activity',
            'activity_type' => 'tests.scope-admission',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'payload_codec' => 'avro',
        ];
        $marker = [[
            'type' => 'record_side_effect',
            'marker_id' => 'reserved-position',
            'result' => Serializer::serializeWithCodec('avro', 'must not commit'),
            'payload_codec' => 'avro',
        ]];
        $reply = match ($path) {
            'scoped local' => $bridge->prepareLocalActivity(
                $task->id,
                'original',
                1,
                3,
                'new-scoped-attempt',
                [
                    ...$local,
                    'cancellation_scope_id' => $scope,
                ],
                '1.20'
            ),
            'unscoped local' => $bridge->prepareLocalActivity(
                $task->id,
                'original',
                1,
                3,
                'new-root-attempt',
                $local,
                '1.20'
            ),
            'parent local' => $bridge->prepareLocalActivity(
                $task->id,
                'original',
                1,
                3,
                'new-parent-attempt',
                [
                    ...$local,
                    'cancellation_scope_id' => $parent,
                ],
                '1.20'
            ),
            'completion' => $bridge->complete($task->id, [[
                ...$local,
                'type' => 'schedule_activity',
            ]]),
            'local prefix' => $bridge->checkpointLocalActivityPrefix(
                $task->id,
                'original',
                1,
                'reserved',
                3,
                $marker,
                '1.20'
            ),
            'scope prefix' => $bridge->checkpointCancellationScopePrefix(
                $task->id,
                'original',
                1,
                'reserved',
                3,
                $marker,
                '1.20'
            ),
            'local group' => $bridge->checkpointLocalActivityGroup($task->id, 'original', 1, 'reserved', 3, [[
                ...$local,
                'type' => 'prepare_local_activity',
                ...ParallelChildGroup::itemMetadata(3, 2, 0, 'mixed'),
            ], [
                ...$local,
                'type' => 'schedule_activity',
                'cancellation_scope_id' => $parent,
                ...ParallelChildGroup::itemMetadata(3, 2, 1, 'mixed'),
            ]], '1.20'),
        };
        $this->assertSame($reason, $reply['reason']);
        $this->assertSame($history, $run->historyEvents()->orderBy('sequence')->get()->toArray());
        $this->assertSame($claim, $task->fresh()->getAttributes());
        $this->assertSame(0, $run->activityExecutions()->count());
        $this->assertSame(1, $run->tasks()->count());
        $this->assertNull($run->fresh()->cancellation_request_command_id);
    }

    public static function reservedAdmissionPaths(): iterable
    {
        yield 'direct local scope admission' => ['scoped local', 'operation_scope_cancellation_prepared'];
        foreach ([
            'unscoped local',
            'parent local',
            'completion',
            'local prefix',
            'scope prefix',
            'local group',
        ] as $path) {
            yield $path => [$path, 'operation_cancellation_delivery_reserved'];
        }
    }

    public function testPreparedScopeRetainsOriginalLocalAdmissionAndAllowsParentAfterDelivery(): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $descriptor = [
            'type' => 'record_local_activity',
            'activity_type' => 'tests.scope-admission',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'payload_codec' => 'avro',
            'cancellation_scope_id' => $scope,
        ];
        $first = $bridge->prepareLocalActivity($task->id, 'original', 1, 3, 'original-attempt', $descriptor, '1.20');
        $this->assertTrue($first['prepared'], $first['reason'] ?? '');
        $request = CancellationScopeRequests::request($run->fresh(), $scope, '1.20', 30);
        $preparation = CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            3,
            'local_activity',
            '1.20'
        );
        $historyCount = $run->historyEvents()
            ->count();
        $duplicate = $bridge->prepareLocalActivity(
            $task->id,
            'original',
            1,
            3,
            'original-attempt',
            $descriptor,
            '1.20'
        );
        $this->assertTrue($duplicate['prepared'], $duplicate['reason'] ?? '');
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($first['activity_attempt_id'], $duplicate['activity_attempt_id']);
        $this->assertSame($historyCount, $run->historyEvents()->count());
        $this->assertSame('operation_scope_cancellation_prepared', $bridge->prepareLocalActivity(
            $task->id,
            'original',
            1,
            4,
            'forbidden-attempt',
            $descriptor,
            '1.20'
        )['reason']);
        $this->assertSame(1, $run->activityExecutions()->count());
        $fence = ScopedActivityCancellation::fence(
            $run,
            $task,
            $first['activity_execution_id'],
            $scope,
            $request->payload['request_id'],
            '1.20'
        );
        $this->assertTrue($fence['fenced']);
        $delivery = CancellationScopeDelivery::record(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            3,
            'local_activity',
            '1.20'
        );
        $this->assertSame($preparation->id, $delivery->payload['preparation_history_event_id']);
        $this->assertSame('operation_scope_cancellation_prepared', $bridge->prepareLocalActivity(
            $task->id,
            'original',
            1,
            4,
            'forbidden-after-delivery',
            $descriptor,
            '1.20'
        )['reason']);
        unset($descriptor['cancellation_scope_id']);
        $parent = $bridge->prepareLocalActivity($task->id, 'original', 1, 4, 'parent-attempt', $descriptor, '1.20');
        $this->assertTrue($parent['prepared'], $parent['reason'] ?? '');
        $this->assertSame(2, $run->activityExecutions()->count());
        $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
        $this->assertNull($run->fresh()->cancellation_request_command_id);
    }

    #[DataProvider('unaffectedLocalStates')]
    public function testMixedPendingDeliveryPreservesPreviouslyAdmittedRootLocalWork(bool $startedBefore): void
    {
        [, $run, $task, , $scope] = $this->scopeTree();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $local = [
            'type' => 'prepare_local_activity',
            'activity_type' => 'tests.unaffected-root',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'payload_codec' => 'avro',
            ...ParallelChildGroup::itemMetadata(3, 2, 0, 'mixed'),
        ];
        $checkpoint = $bridge->checkpointLocalActivityGroup($task->id, 'original', 1, 'original-group', 3, [$local, [
            ...$local,
            'type' => 'schedule_activity',
            'activity_type' => 'tests.cancelled-member',
            'cancellation_scope_id' => $scope,
            ...ParallelChildGroup::itemMetadata(3, 2, 1, 'mixed'),
        ]], '1.20');
        $this->assertTrue($checkpoint['checkpointed'], $checkpoint['reason'] ?? '');
        $local['type'] = 'record_local_activity';
        $first = $startedBefore
            ? $bridge->prepareLocalActivity($task->id, 'original', 1, 3, 'unaffected-attempt', $local, '1.20') : null;
        if ($first !== null) {
            $this->assertTrue($first['prepared'], $first['reason'] ?? '');
        }
        $request = CancellationScopeRequests::request($run->fresh(), $scope, '1.20', 30);
        $preparation = CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            $request->payload['request_id'],
            3,
            'parallel',
            '1.20',
            2
        );
        $before = $task->fresh()
            ->getAttributes();
        $reply = $bridge->prepareLocalActivity($task->id, 'original', 1, 3, 'unaffected-attempt', $local, '1.20');
        $this->assertTrue($reply['prepared'], $reply['reason'] ?? '');
        $this->assertSame($startedBefore, $reply['duplicate']);
        if ($first !== null) {
            $this->assertSame($first['activity_attempt_id'], $reply['activity_attempt_id']);
        }
        $this->assertSame(2, $run->activityExecutions()->count());
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame($preparation->id, CancellationScopeDelivery::prepared($run->fresh(), $scope)->id);
        $this->assertNull($run->fresh()->cancellation_request_command_id);
    }

    public static function unaffectedLocalStates(): iterable
    {
        yield 'previously started callback' => [true];
        yield 'admitted before preparation but not started' => [false];
    }

    /**
     * @return array{WorkflowStub, WorkflowRun, WorkflowTask, string, string}
     */
    private function scopeTree(bool $shield = false): array
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'scope-delivery');
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
        $parent = CancellationScopeHistory::open($run, $task, 1, '1.20')->payload['scope_id'];
        $child = CancellationScopeHistory::open($run, $task, 2, '1.20', $parent, $shield)->payload['scope_id'];
        return [$workflow, $run, $task, $parent, $child];
    }

    private function deliver(
        WorkflowRun $run,
        WorkflowTask $task,
        string $scope,
        string $kind = 'activity'
    ): WorkflowHistoryEvent {
        CancellationScopeDelivery::prepare(
            $run,
            $task,
            $scope,
            CancellationScopeRequests::context($run, $scope)->requestId,
            3,
            $kind,
            '1.20'
        );
        $this->fenceActivities($run, $task, $scope);
        return CancellationScopeDelivery::record(
            $run,
            $task,
            $scope,
            CancellationScopeRequests::context($run, $scope)->requestId,
            3,
            $kind,
            '1.20'
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function schedule(WorkflowRun $run, HistoryEventType $type, array $payload): void
    {
        if ($type === HistoryEventType::ActivityScheduled) {
            $scope = $payload['activity']['cancellation_scope_id'] ?? $payload['cancellation_scope_id'] ?? 'root';
            $execution = ActivityExecution::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => $payload['sequence'],
                'activity_type' => TestGreetingActivity::class,
                'activity_class' => TestGreetingActivity::class,
                'queue' => 'scope-delivery-activities',
                'status' => ActivityStatus::Pending,
                'payload_codec' => 'avro',
                'arguments' => Serializer::serializeWithCodec('avro', []),
                'activity_options' => [
                    'cancellation_scope_id' => $scope,
                ],
            ]);
            $payload['activity_execution_id'] = $execution->id;
            $payload['activity'] = [
                'id' => $execution->id,
                ...($payload['activity'] ?? []),
            ];
            WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'namespace' => $run->namespace,
                'task_type' => TaskType::Activity,
                'status' => TaskStatus::Ready,
                'queue' => 'scope-delivery-activities',
                'available_at' => now(),
                'payload' => [
                    'activity_execution_id' => $execution->id,
                ],
            ]);
        } elseif ($type === HistoryEventType::TimerScheduled) {
            $timer = WorkflowTimer::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => $payload['sequence'],
                'status' => TimerStatus::Pending,
                'delay_seconds' => 60,
                'fire_at' => now()
                    ->addMinute(),
            ]);
            $payload = [
                ...$payload,
                'timer_id' => $timer->id,
                'delay_seconds' => 60,
                'fire_at' => $timer->fire_at->toISOString(),
            ];
            WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'namespace' => $run->namespace,
                'task_type' => TaskType::Timer,
                'status' => TaskStatus::Ready,
                'available_at' => $timer->fire_at,
                'payload' => [
                    'timer_id' => $timer->id,
                ],
            ]);
        } elseif ($type === HistoryEventType::ActivityCompleted) {
            $execution = $run->activityExecutions()
                ->where('sequence', $payload['sequence'])->sole();
            $task = $run->tasks()
                ->where('task_type', TaskType::Activity)
                ->where('payload->activity_execution_id', $execution->id)
                ->sole();
            $bridge = app(DefaultActivityTaskBridge::class);
            $claim = $bridge->claimStatus($task->id, 'activity-owner');
            $this->assertTrue($claim['claimed'], $claim['reason'] ?? '');
            $result = $bridge->complete($claim['activity_attempt_id'], Serializer::serializeWithCodec('avro', 'done'));
            $this->assertTrue($result['recorded'], $result['reason'] ?? '');
            $run->refresh();
            return;
        }
        WorkflowHistoryEvent::record($run, $type, $payload);
        $run->refresh();
    }

    private function fenceActivities(WorkflowRun $run, WorkflowTask $task, string $scope): void
    {
        foreach (CancellationScopeDelivery::prepared($run->fresh(), $scope)->payload['timer_members'] as $member) {
            ScopedTimerCancellation::fence(
                $run,
                $task,
                $member['timer_id'],
                $scope,
                CancellationScopeRequests::context($run, $scope)->requestId,
                '1.20'
            );
        }
        foreach ($run->activityExecutions()->get() as $execution) {
            if ($execution->activity_options['cancellation_scope_id'] === $scope
                && $execution->status === ActivityStatus::Pending) {
                $this->assertTrue(ScopedActivityCancellation::fence(
                    $run,
                    $task,
                    $execution->id,
                    $scope,
                    CancellationScopeRequests::context($run, $scope)->requestId,
                    '1.20'
                )['fenced']);
            }
        }
    }

    private function assertRefusedWithoutMutation(
        WorkflowRun $run,
        WorkflowTask $task,
        string $reason,
        callable $action
    ): void {
        $history = $run->historyEvents()
            ->count();
        $claim = $task->fresh()
            ->getAttributes();
        try {
            $action();
            $this->fail('Scoped delivery should have been refused.');
        } catch (LogicException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
        $this->assertSame($history, $run->historyEvents()->count());
        $this->assertSame($claim, $task->fresh()->getAttributes());
    }
}
