<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\CancellationScopeDelivery;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\CancellationScopeRequests;
use Workflow\V2\Support\CooperativeCancellationDelivery;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\ScopedChildCancellation;
use Workflow\V2\Support\ScopedChildCancellationDelivery;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class V2ScopedChildCancellationTest extends TestCase
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

    public function testPreparationFreezesAllPoliciesWithoutTouchingChildrenSiblingsOrShields(): void
    {
        [$run, $task, $scope, $sibling, $shield] = $this->tree();
        $target = [];
        foreach (['try_cancel', 'wait_cancellation_completed', 'abandon'] as $index => $policy) {
            $target[] = $this->child($run, $task, $scope, 4 + $index, $policy);
        }
        $other = $this->child($run, $task, $sibling, 7);
        $shielded = $this->child($run, $task, $shield, 8);
        $before = $task->fresh()
            ->getAttributes();
        $prepared = $this->prepare($run, $task, $scope);
        $this->assertSame(array_column($target, 'child_workflow_run_id'), array_column(
            $prepared['child_members'],
            'child_workflow_run_id'
        ));
        $this->assertSame(['try_cancel', 'wait_cancellation_completed', 'abandon'], array_column(
            $prepared['child_members'],
            'cancellation_policy'
        ));
        foreach ([...$target, $other, $shielded] as $child) {
            $this->assertNull(
                WorkflowRun::query()->findOrFail($child['child_workflow_run_id'])->cancellation_request_command_id
            );
        }
        $this->assertHostingShortenedWithoutClaimReplacement($before, $task);
        $this->assertNull($run->fresh()->cancellation_request_command_id);
        $this->assertFalse($run->fresh()->status->isTerminal());
        $timeline = collect(HistoryTimeline::fromHistory($run->fresh()))
            ->firstWhere('id', $prepared['preparation_history_event_id']);
        $this->assertSame($prepared['child_members'], ScopedChildCancellation::normalizeMembers(
            $timeline['cancellation_scope']['child_members']
        ));
    }

    public function testColdPreparationIgnoresMissingProjectionsAndChangedCurrentRun(): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        WorkflowChildCall::query()->where('parent_workflow_run_id', $run->id)->delete();
        WorkflowLink::query()->where('parent_workflow_run_id', $run->id)->delete();
        $target = WorkflowRun::query()->findOrFail($child['child_workflow_run_id']);
        $target->instance()
            ->firstOrFail()
            ->forceFill([
                'current_run_id' => null,
            ])->save();
        $cold = CancellationScopeDelivery::prepared($run->fresh(), $scope);
        $this->assertSame($prepared['preparation_history_event_id'], $cold->id);
        $this->assertSame($prepared['child_members'], ScopedChildCancellation::normalizeMembers(
            $cold->payload['child_members']
        ));
        $this->assertSame($prepared, $this->prepare($run->fresh(), $task->fresh(), $scope));
    }

    public function testOnlyChildStartsCommittedBeforePreparationSelectItsOriginalRun(): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        $this->started($run, $task, $child, 'continued-before-preparation');
        $prepared = $this->prepare($run, $task, $scope);
        $this->assertSame('continued-before-preparation', $prepared['child_members'][0]['child_workflow_run_id']);
        $this->started($run, $task, $child, 'continued-after-preparation');
        $this->assertSame('continued-after-preparation', ScopedChildCancellation::members(
            $run->fresh(),
            $scope
        )[0]['child_workflow_run_id']);
        $this->assertSame($prepared, $this->prepare($run->fresh(), $task->fresh(), $scope));
        $this->assertSame($prepared['child_members'], ScopedChildCancellation::normalizeMembers(
            CancellationScopeDelivery::prepared($run->fresh(), $scope)->payload['child_members']
        ));
    }

    public function testNaturalChildCompletionKeepsTheOriginalPreparedTargetAndPolicy(): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        WorkflowHistoryEvent::record($run->fresh(), HistoryEventType::ChildRunCompleted, [
            ...array_intersect_key($child, array_flip([
                'sequence', 'child_call_id', 'workflow_link_id', 'child_workflow_instance_id',
                'child_workflow_run_id', 'child_workflow_class', 'child_workflow_type',
            ])),
            'result' => Serializer::serializeWithCodec('avro', ['done']),
        ], $task);
        $this->assertSame($prepared, $this->prepare($run->fresh(), $task->fresh(), $scope));
        $this->assertSame(1, $run->historyEvents()->where('event_type', HistoryEventType::ChildRunCompleted)->count());
    }

    #[DataProvider('changedMemberFields')]
    public function testColdReplayRejectsAlteredOriginalChildMembership(string $field, mixed $value): void
    {
        [$run, $task, $scope] = $this->tree();
        $this->child($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        $event = WorkflowHistoryEvent::query()->findOrFail($prepared['preparation_history_event_id']);
        $payload = $event->payload;
        $payload['child_members'][0][$field] = $value;
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $this->expectExceptionMessage('cancellation_scope_delivery_history_invalid');
        CancellationScopeDelivery::prepared($run->fresh(), $scope);
    }

    public static function changedMemberFields(): iterable
    {
        yield 'sequence' => ['sequence', 99];
        yield 'call' => ['child_call_id', 'other-call'];
        yield 'instance' => ['child_workflow_instance_id', 'other-instance'];
        yield 'run' => ['child_workflow_run_id', 'other-run'];
        yield 'policy' => ['cancellation_policy', 'abandon'];
        yield 'hash' => ['descriptor_hash', str_repeat('a', 64)];
        yield 'unknown policy' => ['cancellation_policy', 'invalid'];
        yield 'extra field' => ['extra', 'value'];
        yield 'empty identity' => ['child_call_id', ''];
        yield 'wrong identity type' => ['child_workflow_run_id', 42];
    }

    public function testReplacementClaimReplaysOriginalDeadlineAndDatabaseKeyOrder(): void
    {
        [$run, $task, $scope] = $this->tree();
        $this->child($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        $event = WorkflowHistoryEvent::query()->findOrFail($prepared['preparation_history_event_id']);
        $payload = $event->payload;
        $payload['child_members'][0] = array_reverse($payload['child_members'][0], true);
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        Carbon::setTestNow('2026-10-03T00:00:20Z');
        $repair = TaskWatchdog::runPass(respectThrottle: false, runIds: [$run->id]);
        $this->assertSame([], $repair['existing_task_failures']);
        $this->assertSame(1, $repair['repaired_existing_tasks']);
        $this->assertTrue(
            app(DefaultWorkflowTaskBridge::class)->claimStatus($task->id, 'replacement-child-owner')['claimed']
        );
        $this->assertSame(2, $task->fresh()->attempt_count);
        $this->assertSame($prepared, $this->prepare($run->fresh(), $task->fresh(), $scope));
        $this->assertSame('2026-10-03T00:00:30.000000Z', $prepared['authority_deadline_at']);
    }

    public function testChildSnapshotCannotBeMistakenForCommittedCancellation(): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        $before = $run->historyEvents()
            ->count();
        try {
            CancellationScopeDelivery::record(
                $run->fresh(),
                $task->fresh(),
                $scope,
                $prepared['request_id'],
                4,
                'child',
                '1.20'
            );
            $this->fail('A child membership snapshot was treated as cancellation proof.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_child_delivery_not_established', $error->getMessage());
        }
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertNull(
            WorkflowRun::query()->findOrFail($child['child_workflow_run_id'])->cancellation_request_command_id
        );
        $this->assertNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
    }

    public function testCanonicalChildRequestPreservesThePreparedScopeAndColdDuplicateAfterExpiry(): void
    {
        [$run, $task, $scope, $sibling] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        $other = $this->child($run, $task, $sibling, 5);
        CancellationScopeRequests::request($run, $scope, '1.20', 30, 'maintenance');
        $prepared = $this->prepare($run, $task, $scope);
        WorkflowChildCall::query()->where('parent_workflow_run_id', $run->id)->delete();
        WorkflowLink::query()->where('parent_workflow_run_id', $run->id)->delete();
        Carbon::setTestNow('2026-10-03T00:00:12Z');
        $target = WorkflowStub::loadRun($child['child_workflow_run_id']);
        $request = $target->attemptRequestCancellationFromScope(
            $run->id,
            $scope,
            $prepared['preparation_history_event_id'],
            $child['child_call_id']
        );
        $this->assertTrue($request->accepted(), $request->rejectionReason() ?? '');
        $context = $request->cancellationContext();
        $this->assertSame($prepared['cancellation'], $context->scopeOrigin->toArray());
        $this->assertSame($prepared['request_id'], $context->parentRequestId);
        $this->assertSame('maintenance', $context->reason);
        $this->assertSame('2026-10-03T00:00:00.000000Z', $context->requestedAt()->toISOString());
        $this->assertSame('2026-10-03T00:00:30.000000Z', $context->deadline()->toISOString());
        $this->assertFalse($target->run()->fresh()->status->isTerminal());
        $this->assertNull($run->fresh()->cancellation_request_command_id);
        $this->assertNull(
            WorkflowRun::query()->findOrFail($other['child_workflow_run_id'])->cancellation_request_command_id
        );
        $this->assertSame(
            $context->toArray(),
            CooperativeCancellationDelivery::context($target->run()->fresh())->toArray()
        );
        Carbon::setTestNow('2026-10-03T00:01:00Z');
        $duplicate = WorkflowStub::loadRun($child['child_workflow_run_id'])->attemptRequestCancellationFromScope(
            $run->id,
            $scope,
            $prepared['preparation_history_event_id'],
            $child['child_call_id']
        );
        $this->assertSame($context->toArray(), $duplicate->cancellationContext()->toArray());
        $this->assertSame(
            1,
            $target->run()
                ->historyEvents()
                ->where('event_type', HistoryEventType::CooperativeCancellationRequested)->count()
        );
    }

    #[DataProvider('invalidChildRequests')]
    public function testUnpreparedOrExpiredChildRequestCannotCancelTheTarget(string $mutation): void
    {
        [$run, $task, $scope, $sibling] = $this->tree();
        $child = $this->child($run, $task, $scope, 4, $mutation === 'abandon' ? 'abandon' : 'try_cancel');
        $prepared = $this->prepare($run, $task, $scope);
        $preparationId = $prepared['preparation_history_event_id'];
        $callId = $child['child_call_id'];
        if ($mutation === 'preparation') {
            $preparationId = 'wrong-preparation';
        }
        if ($mutation === 'call') {
            $callId = 'wrong-call';
        }
        if ($mutation === 'scope') {
            $scope = $sibling;
        }
        if ($mutation === 'expired') {
            Carbon::setTestNow('2026-10-03T00:00:30Z');
        }
        if ($mutation === 'shorter run deadline') {
            $run->forceFill([
                'run_deadline_at' => now()
                    ->addSecond(),
            ])->save();
            Carbon::setTestNow('2026-10-03T00:00:01Z');
        }
        $request = WorkflowStub::loadRun($child['child_workflow_run_id'])->attemptRequestCancellationFromScope(
            $run->id,
            $scope,
            $preparationId,
            $callId
        );
        $this->assertTrue($request->rejected());
        $this->assertSame(in_array($mutation, ['expired', 'shorter run deadline'], true)
            ? 'cancellation_scope_authority_expired' : 'cancellation_scope_child_target_mismatch', $request->rejectionReason());
        $target = WorkflowRun::query()->findOrFail($child['child_workflow_run_id']);
        $this->assertNull($target->cancellation_request_command_id);
        $this->assertSame(
            0,
            $target->historyEvents()
                ->where('event_type', HistoryEventType::CooperativeCancellationRequested)->count()
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidChildRequests(): iterable
    {
        foreach (['preparation', 'call', 'scope', 'abandon', 'expired', 'shorter run deadline'] as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    #[DataProvider('childPolicies')]
    public function testChildActorCommitsPolicyReceiptsWithoutReleasingTheParentClaim(string $policy): void
    {
        [$run, $task, $scope, $sibling, $shield] = $this->tree();
        $child = $this->child($run, $task, $scope, 4, $policy);
        $other = $this->child($run, $task, $sibling, 5);
        $shielded = $this->child($run, $task, $shield, 6);
        $prepared = $this->prepare($run, $task, $scope);
        $claimBefore = $task->fresh()
            ->getAttributes();
        $receipt = ScopedChildCancellationDelivery::request(
            $run,
            $task,
            $child['child_call_id'],
            $scope,
            $prepared['request_id'],
            '1.20'
        );
        $target = WorkflowStub::loadRun($child['child_workflow_run_id']);
        $context = CooperativeCancellationDelivery::context($target->run()->fresh());
        $this->assertSame($policy === 'abandon', $context === null);
        $this->assertFalse($target->run()->fresh()->status->isTerminal());
        $this->assertSame($claimBefore, $task->fresh()->getAttributes());
        $this->assertNull($run->fresh()->cancellation_request_command_id);
        foreach ([$other, $shielded] as $untouched) {
            $this->assertNull(
                WorkflowRun::query()->findOrFail($untouched['child_workflow_run_id'])->cancellation_request_command_id
            );
        }
        if ($policy === 'wait_cancellation_completed') {
            $this->assertFalse($receipt['ready']);
            try {
                CancellationScopeDelivery::record(
                    $run->fresh(),
                    $task->fresh(),
                    $scope,
                    $prepared['request_id'],
                    4,
                    'child',
                    '1.20'
                );
                $this->fail('WAIT delivered before canonical child terminal history.');
            } catch (LogicException $error) {
                $this->assertSame('cancellation_scope_child_completion_not_established', $error->getMessage());
            }
            $target->attemptCancel('terminal fixture');
            $resolved = ScopedChildCancellationDelivery::request(
                $run->fresh(),
                $task->fresh(),
                $child['child_call_id'],
                $scope,
                $prepared['request_id'],
                '1.20'
            );
            $this->assertSame($receipt['history_event_id'], $resolved['history_event_id']);
            $this->assertTrue($resolved['ready']);
            $this->assertNotNull($resolved['resolution_history_event_id']);
        } else {
            $this->assertTrue($receipt['ready']);
        }
        $delivery = CancellationScopeDelivery::record(
            $run->fresh(),
            $task->fresh(),
            $scope,
            $prepared['request_id'],
            4,
            'child',
            '1.20'
        );
        Carbon::setTestNow('2026-10-03T00:00:20Z');
        $repair = TaskWatchdog::runPass(respectThrottle: false, runIds: [$run->id]);
        $this->assertSame([], $repair['existing_task_failures']);
        $this->assertSame(1, $repair['repaired_existing_tasks']);
        $this->assertTrue(app(DefaultWorkflowTaskBridge::class)->claimStatus($task->id, 'replacement')['claimed']);
        $this->assertSame(2, $task->fresh()->attempt_count);
        $cold = ScopedChildCancellationDelivery::request(
            $run->fresh(),
            $task->fresh(),
            $child['child_call_id'],
            $scope,
            $prepared['request_id'],
            '1.20'
        );
        $this->assertSame($receipt['history_event_id'], $cold['history_event_id']);
        $this->assertSame($delivery->id, CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
        $this->assertSame(
            $context?->toArray(),
            CooperativeCancellationDelivery::context($target->run()->fresh())?->toArray()
        );
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ChildCancellationRequested)->count()
        );
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeDelivered)->count()
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function childPolicies(): iterable
    {
        foreach (['try_cancel', 'wait_cancellation_completed', 'abandon'] as $policy) {
            yield $policy => [$policy];
        }
    }

    #[DataProvider('childPolicies')]
    public function testPortableDeliveryReconcilesOriginalChildPolicyAndReplacementClaim(string $policy): void
    {
        [$run, $task, $scope, $sibling, $shield] = $this->tree();
        $child = $this->child($run, $task, $scope, 4, $policy);
        $other = $this->child($run, $task, $sibling, 5);
        $shielded = $this->child($run, $task, $shield, 6);
        $prepared = $this->prepare($run, $task, $scope);
        $claimBefore = $task->fresh()
            ->getAttributes();
        $response = $this->deliver($task, $scope, $prepared['request_id']);
        $this->assertTrue($response['prepared']);
        $this->assertFalse($response['claim_released']);
        $this->assertCount(1, $response['child_cancellations']);
        $receipt = $response['child_cancellations'][0];
        $this->assertSame($child['child_call_id'], $receipt['child_call_id']);
        $this->assertSame($child['child_workflow_run_id'], $receipt['child_workflow_run_id']);
        $this->assertSame($policy, $receipt['policy']);
        $target = WorkflowStub::loadRun($child['child_workflow_run_id']);
        $context = CooperativeCancellationDelivery::context($target->run()->fresh());
        $this->assertSame($policy === 'abandon', $context === null);
        $this->assertFalse($target->run()->fresh()->status->isTerminal());
        if ($context !== null) {
            $this->assertSame($prepared['cancellation'], $context->scopeOrigin->toArray());
            $this->assertSame($prepared['request_id'], $context->rootRequestId);
            $this->assertSame($prepared['authority_deadline_at'], $context->deadline()->toISOString());
            $this->assertSame($context->requestId, $receipt['request_id']);
        }
        if ($response['delivered']) {
            $this->assertHostingShortenedWithoutClaimReplacement($claimBefore, $task);
        } else {
            $this->assertSame($claimBefore, $task->fresh()->getAttributes());
        }
        foreach ([$other, $shielded] as $untouched) {
            $this->assertNull(
                WorkflowRun::query()->findOrFail($untouched['child_workflow_run_id'])->cancellation_request_command_id
            );
        }
        $beforeRetry = $run->historyEvents()
            ->count();
        $this->assertSame($response, $this->deliver($task->fresh(), $scope, $prepared['request_id']));
        $this->assertSame($beforeRetry, $run->historyEvents()->count());
        if ($policy === 'wait_cancellation_completed') {
            $this->assertFalse($response['delivered']);
            $this->assertFalse($receipt['ready']);
            $this->assertNull($receipt['resolution_history_event_id']);
            $this->assertSame('cancellation_scope_child_completion_not_established', $response['reason']);
            $this->assertNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
            $target->attemptCancel('canonical terminal fixture');
        } else {
            $this->assertTrue($response['delivered']);
            $this->assertTrue($receipt['ready']);
        }
        Carbon::setTestNow('2026-10-03T00:00:20Z');
        $repair = TaskWatchdog::runPass(respectThrottle: false, runIds: [$run->id]);
        $this->assertSame([], $repair['existing_task_failures']);
        $this->assertSame(1, $repair['repaired_existing_tasks']);
        $this->assertTrue(app(DefaultWorkflowTaskBridge::class)->claimStatus($task->id, 'replacement')['claimed']);
        $this->assertSame(2, $task->fresh()->attempt_count);
        $cold = $this->deliver($task->fresh(), $scope, $prepared['request_id']);
        $this->assertTrue($cold['delivered'], $cold['reason'] ?? '');
        $this->assertSame($prepared['preparation_history_event_id'], $cold['preparation_history_event_id']);
        $this->assertSame($prepared['cancellation'], $cold['cancellation']);
        $this->assertSame($prepared['authority_deadline_at'], $cold['authority_deadline_at']);
        $this->assertSame($receipt['history_event_id'], $cold['child_cancellations'][0]['history_event_id']);
        $this->assertSame($receipt['request_id'], $cold['child_cancellations'][0]['request_id']);
        if ($policy === 'wait_cancellation_completed') {
            $this->assertTrue($cold['child_cancellations'][0]['ready']);
            $this->assertNotNull($cold['child_cancellations'][0]['resolution_history_event_id']);
        } else {
            $this->assertSame($response['history_event_id'], $cold['history_event_id']);
        }
        $this->assertSame(
            $context?->toArray(),
            CooperativeCancellationDelivery::context($target->run()->fresh())?->toArray()
        );
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ChildCancellationRequested)->count()
        );
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeDelivered)->count()
        );
        $this->assertSame($cold, $this->deliver($task->fresh(), $scope, $prepared['request_id']));
    }

    public function testPortableDeliveryRequestsDirectAndDescendantChildrenUnderOneBoundary(): void
    {
        [$run, $task, $scope] = $this->tree();
        $descendant = CancellationScopeHistory::open($run, $task, 4, '1.20', $scope)->payload['scope_id'];
        $child = $this->child($run, $task, $scope, 5);
        $nested = $this->child($run, $task, $descendant, 6);
        $prepared = $this->prepare($run, $task, $scope, 5);
        $response = $this->deliver($task, $scope, $prepared['request_id'], 5);
        $this->assertTrue($response['delivered'], $response['reason'] ?? '');
        foreach ([$child, $nested] as $untouched) {
            $this->assertNotNull(
                WorkflowRun::query()->findOrFail($untouched['child_workflow_run_id'])->cancellation_request_command_id
            );
        }
        $this->assertNull(CancellationScopeDelivery::prepared($run->fresh(), $descendant));
        $this->assertSame($response, $this->deliver($task, $scope, $prepared['request_id'], 5));
    }

    public function testPreviouslyClosedChildHasItsOwnCanonicalResolutionReceipt(): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4, 'wait_cancellation_completed');
        $prepared = $this->prepare($run, $task, $scope);
        WorkflowStub::loadRun($child['child_workflow_run_id'])->attemptCancel('natural terminal fixture');
        $receipt = ScopedChildCancellationDelivery::request(
            $run,
            $task,
            $child['child_call_id'],
            $scope,
            $prepared['request_id'],
            '1.20'
        );
        $this->assertTrue($receipt['ready']);
        $this->assertNull($receipt['request_id']);
        $this->assertSame(
            'already_terminal',
            WorkflowHistoryEvent::query()->findOrFail($receipt['history_event_id'])->payload['request_outcome']
        );
        ScopedChildCancellation::assertReady(CancellationScopeDelivery::prepared($run->fresh(), $scope), $run->fresh());
        $this->assertNull(
            WorkflowRun::query()->findOrFail($child['child_workflow_run_id'])->cancellation_request_command_id
        );
    }

    #[DataProvider('postLockDeadlines')]
    public function testChildLockCannotExtendTheRootBudgetOrExpiredHostingLease(int $seconds): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        config()
            ->set('workflows.v2.workflow_task_lease_seconds', 5);
        $prepared = $this->prepare($run, $task, $scope);
        WorkflowInstance::retrieved(static function (WorkflowInstance $instance) use ($child, $seconds): void {
            if ($instance->id === $child['child_workflow_instance_id'] && $instance->getConnection()->transactionLevel() >= 2) {
                Carbon::setTestNow('2026-10-03T00:00:' . sprintf('%02d', $seconds) . 'Z');
            }
        });
        try {
            ScopedChildCancellationDelivery::request(
                $run,
                $task,
                $child['child_call_id'],
                $scope,
                $prepared['request_id'],
                '1.20'
            );
            $this->fail('A child request committed after its hosting authority expired.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('cancellation_scope_authority_expired', $error->getMessage());
        }
        $target = WorkflowRun::query()->findOrFail($child['child_workflow_run_id']);
        $this->assertNull($target->cancellation_request_command_id);
        $this->assertSame(
            0,
            $target->historyEvents()
                ->where('event_type', HistoryEventType::CooperativeCancellationRequested)->count()
        );
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ChildCancellationRequested)->count()
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function postLockDeadlines(): iterable
    {
        yield 'expired hosting lease' => [5];
        yield 'expired root budget' => [30];
    }

    #[DataProvider('changedChildReceipts')]
    public function testAlteredReceiptCannotAuthorizeParentDelivery(string $mutation): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        $receipt = ScopedChildCancellationDelivery::request(
            $run,
            $task,
            $child['child_call_id'],
            $scope,
            $prepared['request_id'],
            '1.20'
        );
        $event = WorkflowHistoryEvent::query()->findOrFail($receipt['history_event_id']);
        $payload = $event->payload;
        switch ($mutation) {
            case 'parent': $payload['parent_request_id'] = 'wrong';
                break;
            case 'target': $payload['child_workflow_run_id'] = 'wrong';
                break;
            case 'call': $payload['cancellation_scope']['member']['child_call_id'] = 'wrong';
                break;
            case 'preparation': $payload['cancellation_scope']['preparation_history_event_id'] = 'wrong';
                break;
            case 'schema': $payload['cancellation_scope']['schema'] = 'wrong';
                break;
            case 'deadline': $payload['child_cleanup_deadline_at'] = '2026-10-03T00:00:40.000000Z';
                break;
            case 'context': $payload['child_cancellation']['scope_origin'] = [];
                break;
            case 'outcome': $payload['request_outcome'] = 'abandoned';
                break;
        }
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cancellation_scope_child_delivery_not_established');
        CancellationScopeDelivery::record(
            $run->fresh(),
            $task->fresh(),
            $scope,
            $prepared['request_id'],
            4,
            'child',
            '1.20'
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function changedChildReceipts(): iterable
    {
        foreach (['parent', 'target', 'call', 'preparation', 'schema', 'deadline', 'context', 'outcome'] as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    public function testColdReceiptObjectKeyReorderingPreservesTheOriginalProof(): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        $receipt = ScopedChildCancellationDelivery::request(
            $run,
            $task,
            $child['child_call_id'],
            $scope,
            $prepared['request_id'],
            '1.20'
        );
        $event = WorkflowHistoryEvent::query()->findOrFail($receipt['history_event_id']);
        $payload = $event->payload;
        ksort($payload['cancellation_scope']);
        ksort($payload['cancellation_scope']['member']);
        ksort($payload['cancellation_scope']['cancellation']['root_context']['requester']);
        ksort($payload['child_cancellation']['requester']);
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $delivery = CancellationScopeDelivery::record(
            $run->fresh(),
            $task->fresh(),
            $scope,
            $prepared['request_id'],
            4,
            'child',
            '1.20'
        );
        $this->assertSame($delivery->id, CancellationScopeDelivery::recorded($run->fresh(), $scope)->id);
    }

    public function testAnotherCancellationRootCannotBeReplacedByAScopeReceipt(): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        $prepared = $this->prepare($run, $task, $scope);
        $target = WorkflowStub::loadRun($child['child_workflow_run_id']);
        $original = $target->requestCancellation('independent', 20)
            ->cancellationContext();
        try {
            ScopedChildCancellationDelivery::request(
                $run,
                $task,
                $child['child_call_id'],
                $scope,
                $prepared['request_id'],
                '1.20'
            );
            $this->fail('An independent root was replaced by a parent scope.');
        } catch (LogicException $error) {
            $this->assertSame(
                'cancellation_scope_child_request_refused:cancellation_root_conflict',
                $error->getMessage()
            );
        }
        $this->assertSame(
            $original->toArray(),
            CooperativeCancellationDelivery::context($target->run()->fresh())->toArray()
        );
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ChildCancellationRequested)->count()
        );
    }

    public function testLateResolutionReadCannotCommitAfterTheOriginalBudget(): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4, 'wait_cancellation_completed');
        $prepared = $this->prepare($run, $task, $scope);
        $receipt = ScopedChildCancellationDelivery::request(
            $run,
            $task,
            $child['child_call_id'],
            $scope,
            $prepared['request_id'],
            '1.20'
        );
        WorkflowStub::loadRun($child['child_workflow_run_id'])->attemptCancel('terminal fixture');
        WorkflowCommand::retrieved(static function (WorkflowCommand $command) use ($receipt): void {
            if ($command->id === $receipt['request_id'] && $command->getConnection()->transactionLevel() >= 1) {
                Carbon::setTestNow('2026-10-03T00:00:30Z');
            }
        });
        try {
            ScopedChildCancellationDelivery::request(
                $run->fresh(),
                $task->fresh(),
                $child['child_call_id'],
                $scope,
                $prepared['request_id'],
                '1.20'
            );
            $this->fail('A late canonical read committed a child resolution receipt.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_authority_expired', $error->getMessage());
        }
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ChildCancellationResolved)->count()
        );
    }

    public function testChildInheritsTheEarlierPreparedParentRunDeadline(): void
    {
        [$run, $task, $scope] = $this->tree();
        $child = $this->child($run, $task, $scope, 4);
        $run->forceFill([
            'run_deadline_at' => now()
                ->addSeconds(10),
        ])->save();
        $prepared = $this->prepare($run, $task, $scope);
        $this->assertSame('2026-10-03T00:00:10.000000Z', $prepared['authority_deadline_at']);
        Carbon::setTestNow('2026-10-03T00:00:05Z');
        $receipt = ScopedChildCancellationDelivery::request(
            $run,
            $task,
            $child['child_call_id'],
            $scope,
            $prepared['request_id'],
            '1.20'
        );
        $target = WorkflowRun::query()->findOrFail($child['child_workflow_run_id']);
        $context = CooperativeCancellationDelivery::context($target);
        $this->assertSame('2026-10-03T00:00:10.000000Z', $context->deadline()->toISOString());
        $this->assertSame('2026-10-03T00:00:30.000000Z', $context->scopeOrigin->rootDeadline()->toISOString());
        $this->assertSame($prepared['cancellation'], $context->scopeOrigin->toArray());
        $this->assertSame($context->requestId, $receipt['request_id']);
        $this->assertTrue($receipt['ready']);
        ScopedChildCancellation::assertReady(CancellationScopeDelivery::prepared($run->fresh(), $scope), $run->fresh());
    }

    /**
     * @return array{WorkflowRun, WorkflowTask, string, string, string}
     */
    private function tree(): array
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class);
        $workflow->start();
        $run = $workflow->run();
        $task = $run->tasks()
            ->where('task_type', TaskType::Workflow)->sole();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'child-scope-owner',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinutes(5),
        ])->save();
        $scope = CancellationScopeHistory::open($run, $task, 1, '1.20')->payload['scope_id'];
        $sibling = CancellationScopeHistory::open($run, $task, 2, '1.20')->payload['scope_id'];
        $shield = CancellationScopeHistory::open($run, $task, 3, '1.20', $scope, true)->payload['scope_id'];
        return [$run, $task->refresh(), $scope, $sibling, $shield];
    }

    /**
     * @return array<string, mixed>
     */
    private function child(
        WorkflowRun $run,
        WorkflowTask $task,
        string $scope,
        int $sequence,
        string $policy = 'try_cancel'
    ): array {
        $result = app(DefaultWorkflowTaskBridge::class)->checkpointCancellationScopePrefix(
            $task->id,
            $task->lease_owner,
            $task->attempt_count,
            'child-' . $sequence,
            $sequence,
            [[
                'type' => 'start_child_workflow',
                'workflow_type' => 'scoped-child-fixture',
                'arguments' => Serializer::serializeWithCodec('avro', []),
                'payload_codec' => 'avro',
                'cancellation_scope_id' => $scope,
                'cancellation_policy' => $policy,
            ]],
            '1.20'
        );
        $this->assertTrue($result['checkpointed'], $result['reason'] ?? '');
        return $run->historyEvents()
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled)
            ->where('payload->sequence', $sequence)
            ->sole()
->payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function prepare(WorkflowRun $run, WorkflowTask $task, string $scope, int $sequence = 4): array
    {
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $result = app(DefaultWorkflowTaskBridge::class)->prepareCancellationScopeDelivery(
            $task->id,
            $task->lease_owner,
            $task->attempt_count,
            $scope,
            $request->payload['request_id'],
            $sequence,
            'child',
            protocolVersion: '1.20'
        );
        $this->assertTrue($result['prepared'], $result['reason'] ?? '');
        return $result;
    }

    /**
     * @param array<string, mixed> $child
     */
    private function started(WorkflowRun $run, WorkflowTask $task, array $child, string $childRunId): void
    {
        WorkflowHistoryEvent::record($run->fresh(), HistoryEventType::ChildRunStarted, [
            ...array_diff_key($child, [
                'task' => true,
            ]),
            'child_workflow_run_id' => $childRunId,
        ], $task);
    }

    /**
     * @return array<string, mixed>
     */
    private function deliver(WorkflowTask $task, string $scope, string $request, int $sequence = 4): array
    {
        return app(DefaultWorkflowTaskBridge::class)->deliverCancellationScope(
            $task->id,
            $task->lease_owner,
            $task->attempt_count,
            $scope,
            $request,
            $sequence,
            'child',
            protocolVersion: '1.20'
        );
    }
}
