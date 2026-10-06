<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestCancellationContextWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Support\CooperativeCancellationDelivery;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class CancellationPropagationContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'database',
        ]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testChildAndGrandchildInheritTheOriginalRootAndDeadlineWithoutClosingImmediately(): void
    {
        Carbon::setTestNow('2026-10-01T00:00:00Z');
        $parent = $this->workflow();
        $child = $this->workflow();
        $grandchild = $this->workflow();
        $this->link($parent, $child);
        $this->link($child, $grandchild);
        $root = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();

        Carbon::setTestNow('2026-10-01T00:00:05Z');
        $childRequest = $child->attemptRequestCancellationFromParent($parent->runId());
        $this->assertTrue($childRequest->accepted());
        $childContext = $childRequest->cancellationContext();
        $this->assertNotSame($root->requestId, $childContext->requestId);
        $this->assertSame($root->requestId, $childContext->rootRequestId);
        $this->assertSame($root->requestId, $childContext->parentRequestId);
        $this->assertSame($root->requestedAt()->toISOString(), $childContext->requestedAt()->toISOString());
        $this->assertSame($root->deadline()->toISOString(), $childContext->deadline()->toISOString());
        $this->assertSame($root->requester, $childContext->requester);
        $this->assertSame($root->source, $childContext->source);
        $this->assertSame('maintenance', $childContext->reason);
        $this->assertFalse($child->run()->fresh()->status->isTerminal());
        $this->assertSame(
            $childContext->toArray(),
            CooperativeCancellationDelivery::context($child->run()->fresh())->toArray()
        );

        Carbon::setTestNow('2026-10-01T00:00:10Z');
        $grandchildRequest = $grandchild->attemptRequestCancellationFromParent($child->runId());
        $grandchildContext = $grandchildRequest->cancellationContext();
        $this->assertSame($root->requestId, $grandchildContext->rootRequestId);
        $this->assertSame($childContext->requestId, $grandchildContext->parentRequestId);
        $this->assertSame($root->deadline()->toISOString(), $grandchildContext->deadline()->toISOString());
        $this->assertCount(3, $grandchildContext->lineage);
        $this->assertFalse($grandchild->run()->fresh()->status->isTerminal());

        $duplicate = $child->attemptRequestCancellationFromParent($parent->runId());
        $this->assertSame($childRequest->commandId(), $duplicate->commandId());
        $this->assertSame($childContext->toArray(), $duplicate->cancellationContext()->toArray());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $child->runId())
            ->where('event_type', HistoryEventType::CooperativeCancellationRequested->value)->count());
    }

    public function testLatePropagationDoesNotGrantAFreshBudgetAndRepairClosesIt(): void
    {
        Carbon::setTestNow('2026-10-01T00:00:00Z');
        $parent = $this->workflow();
        $child = $this->workflow();
        $this->link($parent, $child);
        $root = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();
        Carbon::setTestNow('2026-10-01T00:00:35Z');
        $propagated = $child->attemptRequestCancellationFromParent($parent->runId());
        $this->assertTrue($propagated->accepted());
        $this->assertSame(
            $root->deadline()
                ->toISOString(),
            $child->run()
                ->fresh()
                ->cancellation_deadline_at->toISOString()
        );
        $report = TaskWatchdog::runPass();
        $this->assertSame(2, $report['cancellation_deadlines_enforced']);
        $this->assertSame(RunStatus::Cancelled, $child->run()->fresh()->status);
        $duplicate = $child->attemptRequestCancellationFromParent($parent->runId());
        $this->assertSame($propagated->commandId(), $duplicate->commandId());
        $this->assertSame(
            $root->deadline()
                ->toISOString(),
            $duplicate->cancellationContext()
                ->deadline()
                ->toISOString()
        );
    }

    public function testAnIndependentChildRequestWinsAndACompetingRootIsExplicitlyRejected(): void
    {
        $parent = $this->workflow();
        $child = $this->workflow();
        $this->link($parent, $child);
        $independent = $child->requestCancellation('child operator request', 60);
        $root = $parent->requestCancellation('ancestor request', 30)
            ->cancellationContext();
        $conflict = $child->attemptRequestCancellationFromParent($parent->runId());
        $this->assertTrue($conflict->rejected());
        $this->assertSame('cancellation_root_conflict', $conflict->rejectionReason());
        $this->assertSame([
            'existing_request_id' => $independent->commandId(),
            'existing_root_request_id' => $independent->commandId(),
            'existing_cleanup_deadline_at' => $independent->cancellationContext()
                ->deadline()
                ->toISOString(),
            'incoming_root_request_id' => $root->requestId,
            'incoming_cleanup_deadline_at' => $root->deadline()
                ->toISOString(),
        ], $conflict->payloadValues([
            'existing_request_id', 'existing_root_request_id', 'existing_cleanup_deadline_at',
            'incoming_root_request_id', 'incoming_cleanup_deadline_at',
        ]));
        $this->assertSame($independent->commandId(), $child->run()->fresh()->cancellation_request_command_id);
        $this->assertSame(
            $independent->cancellationContext()
                ->toArray(),
            $child->requestCancellation('duplicate', 3600)
                ->cancellationContext()
                ->toArray()
        );
    }

    public function testAnInheritedRequestWinsOverALaterIndependentDuplicate(): void
    {
        $parent = $this->workflow();
        $child = $this->workflow();
        $this->link($parent, $child);
        $parent->requestCancellation('ancestor request', 30);
        $inherited = $child->attemptRequestCancellationFromParent($parent->runId());
        $duplicate = $child->requestCancellation('independent later request', 3600);
        $this->assertSame($inherited->commandId(), $duplicate->commandId());
        $this->assertSame($inherited->cancellationContext()->toArray(), $duplicate->cancellationContext()->toArray());
    }

    public function testAnotherParentWithTheSameRootKeepsTheFirstRecordedDeliveryRoute(): void
    {
        $root = $this->workflow();
        $firstParent = $this->workflow();
        $otherParent = $this->workflow();
        $child = $this->workflow();
        $this->link($root, $firstParent);
        $this->link($root, $otherParent);
        $this->link($firstParent, $child);
        $this->link($otherParent, $child);
        $root->requestCancellation('cascade', 30);
        $firstParent->attemptRequestCancellationFromParent($root->runId());
        $otherParent->attemptRequestCancellationFromParent($root->runId());
        $first = $child->attemptRequestCancellationFromParent($firstParent->runId());
        $second = $child->attemptRequestCancellationFromParent($otherParent->runId());
        $this->assertSame($first->commandId(), $second->commandId());
        $this->assertSame($first->cancellationContext()->toArray(), $second->cancellationContext()->toArray());
    }

    public function testUnlinkedAndUnrequestedParentsCannotSupplyCancellationAuthority(): void
    {
        $parent = $this->workflow();
        $child = $this->workflow();
        $parent->requestCancellation('request', 30);
        $unlinked = $child->attemptRequestCancellationFromParent($parent->runId());
        $this->assertSame('cancellation_parent_not_linked', $unlinked->rejectionReason());
        $unrequested = $this->workflow();
        $this->link($unrequested, $child);
        $missing = $child->attemptRequestCancellationFromParent($unrequested->runId());
        $this->assertSame('cancellation_parent_context_unavailable', $missing->rejectionReason());
        $this->assertNull($child->run()->fresh()->cancellation_request_command_id);
    }

    public function testLegacyParentRequestsRequireAnExplicitContextCapability(): void
    {
        $parent = $this->workflow();
        $child = $this->workflow();
        $this->link($parent, $child);
        $request = $parent->requestCancellation('legacy', 30);
        $command = WorkflowCommand::query()->findOrFail($request->commandId());
        $command->forceFill([
            'payload' => \Workflow\Serializers\Serializer::serializeWithCodec($command->payload_codec, [
                'reason' => 'legacy',
                'cleanup_deadline_at' => $parent->run()
                    ->fresh()
                    ->cancellation_deadline_at->toISOString(),
            ]),
        ])->save();
        $result = $child->attemptRequestCancellationFromParent($parent->runId());
        $this->assertSame('cancellation_parent_context_unavailable', $result->rejectionReason());
        $this->assertNull($child->run()->fresh()->cancellation_request_command_id);
    }

    public function testAStoredChildLinkCannotCreateAnAncestorCycle(): void
    {
        $parent = $this->workflow();
        $child = $this->workflow();
        $this->link($parent, $child);
        $this->link($child, $parent);
        $root = $parent->requestCancellation('cascade', 30);
        $child->attemptRequestCancellationFromParent($parent->runId());
        $cycle = $parent->attemptRequestCancellationFromParent($child->runId());
        $this->assertSame('cancellation_lineage_cycle', $cycle->rejectionReason());
        $this->assertSame($root->commandId(), $parent->run()->fresh()->cancellation_request_command_id);
    }

    public function testParentContextMustMatchTheAcceptedCommandAndItsStoredParentRun(): void
    {
        $parent = $this->workflow();
        $child = $this->workflow();
        $unrelated = $this->workflow();
        $this->link($parent, $child);
        $original = $parent->requestCancellation('parent', 30);
        $other = $unrelated->requestCancellation('other', 60);
        $command = WorkflowCommand::query()->findOrFail($original->commandId());
        $command->forceFill([
            'payload' => \Workflow\Serializers\Serializer::serializeWithCodec($command->payload_codec, [
                'reason' => 'other',
                'cleanup_deadline_at' => $other->cancellationContext()
                    ->deadline()
                    ->toISOString(),
                'cancellation' => $other->cancellationContext()
                    ->toArray(),
            ]),
        ])->save();
        $result = $child->attemptRequestCancellationFromParent($parent->runId());
        $this->assertSame('cancellation_parent_context_mismatch', $result->rejectionReason());
        $this->assertNull($child->run()->fresh()->cancellation_request_command_id);
    }

    private function workflow(): WorkflowStub
    {
        $workflow = WorkflowStub::make(TestCancellationContextWorkflow::class);
        $workflow->start();

        return $workflow;
    }

    private function link(WorkflowStub $parent, WorkflowStub $child): void
    {
        WorkflowLink::query()->create([
            'link_type' => 'child_workflow',
            'parent_workflow_instance_id' => $parent->id(),
            'parent_workflow_run_id' => $parent->runId(),
            'child_workflow_instance_id' => $child->id(),
            'child_workflow_run_id' => $child->runId(),
            'is_primary_parent' => true,
        ]);
    }
}
