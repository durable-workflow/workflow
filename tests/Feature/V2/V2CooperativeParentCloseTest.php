<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestParentChildPolicyWorkflow;
use Tests\Fixtures\V2\TestParentCloseCooperativeWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ChildCallStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\CooperativeCancellationDelivery;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\ParentCloseCancellation;
use Workflow\V2\Support\ParentClosePolicyEnforcer;
use Workflow\V2\Support\RunLineageView;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class V2CooperativeParentCloseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'database',
        ]);
        Queue::fake();
        Carbon::setTestNow('2026-10-01T00:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testParentCloseInheritsOriginalRequestAndKeepsChildOpenDuringRealCleanup(): void
    {
        [$parent, $child] = $this->startWaitingParent();
        $root = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();
        $this->runTask($parent, TaskType::Workflow);

        $this->assertTrue($parent->refresh()->cancelled());
        $context = CooperativeCancellationDelivery::context($child->run()->fresh());
        $this->assertNotNull($context);
        $this->assertSame($root->rootRequestId, $context->rootRequestId);
        $this->assertSame($root->requestId, $context->parentRequestId);
        $this->assertSame($root->deadline()->toISOString(), $context->deadline()->toISOString());
        $this->assertSame('maintenance', $context->reason);
        $this->assertSame($root->requester, $context->requester);
        $this->assertCount(2, $context->lineage);
        $this->assertFalse($child->run()->fresh()->status->isTerminal());
        $this->assertSame(ChildCallStatus::Started, $this->childCall($parent)->status);
        $this->assertSame(0, $this->eventCount($parent, HistoryEventType::ParentCloseCancellationRequested));
        $this->assertSame(1, $this->eventCount($parent, HistoryEventType::ParentClosePolicyApplied));

        $entry = collect(RunLineageView::continuedWorkflowsForRun($parent->run()->fresh()))
            ->firstWhere('child_workflow_run_id', $child->runId());
        $this->assertNotNull($entry);
        $this->assertSame($context->toArray(), $entry['parent_close_cancellation']);
        $point = collect(HistoryTimeline::forRun($parent->run()->fresh()))
            ->firstWhere('type', HistoryEventType::ParentClosePolicyApplied->value);
        $this->assertSame($context->toArray(), $point['parent_close']['cancellation']);

        $this->runTask($child, TaskType::Workflow);
        $this->assertSame([], $child->refresh()->memo());
        $this->assertFalse($child->run()->fresh()->status->isTerminal());
        $this->finishCleanup($child);
        $this->assertTrue($child->run()->fresh()->closed_at->lessThan($root->deadline()));
    }

    public function testNormallyCompletedParentCreatesOneCanonicalOriginForBothChildren(): void
    {
        $parent = WorkflowStub::make(TestParentCloseCooperativeWorkflow::class, 'normal-close');
        $parent->start();
        $this->runTask($parent, TaskType::Workflow);
        $children = $this->children($parent);
        $this->assertCount(2, $children);
        foreach ($children as $child) {
            $this->runTask($child, TaskType::Workflow);
        }
        Carbon::setTestNow(now()->addSecond());
        $this->runTask($parent, TaskType::Timer);
        $this->runTask($parent, TaskType::Workflow);

        $this->assertTrue($parent->refresh()->completed());
        $this->assertNull($parent->run()->fresh()->cancellation_request_command_id);
        $root = ParentCloseCancellation::context($parent->run()->fresh());
        $this->assertNotNull($root);
        $this->assertSame($parent->runId(), $root->rootWorkflowRunId);
        $this->assertSame('parent_close_policy', $root->source);
        $this->assertSame(1, $this->eventCount($parent, HistoryEventType::ParentCloseCancellationRequested));
        $closed = $parent->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::WorkflowCompleted)->sole();
        $this->assertTrue($root->requestedAt()->equalTo($closed->recorded_at));
        $this->assertSame(600.0, (float) $root->requestedAt()->diffInSeconds($root->deadline()));
        $localIds = [];
        foreach ($children as $child) {
            $context = CooperativeCancellationDelivery::context($child->run()->fresh());
            $this->assertSame($root->rootRequestId, $context->rootRequestId);
            $this->assertSame($root->requestId, $context->parentRequestId);
            $this->assertSame($root->deadline()->toISOString(), $context->deadline()->toISOString());
            $localIds[] = $context->requestId;
        }
        $this->assertCount(2, array_unique($localIds));
        Carbon::setTestNow(now()->addSeconds(20));
        $this->assertSame([], ParentClosePolicyEnforcer::enforce($parent->run()->fresh()));
        $this->assertSame($root->toArray(), ParentCloseCancellation::context($parent->run()->fresh())->toArray());
        $this->assertSame(2, $this->eventCount($parent, HistoryEventType::ParentClosePolicyApplied));
        foreach ($children as $index => $child) {
            $duplicate = $child->requestCancellation('later caller', 90)
                ->cancellationContext();
            $this->assertSame($localIds[$index], $duplicate->requestId);
            $this->assertSame($root->deadline()->toISOString(), $duplicate->deadline()->toISOString());
            $this->runTask($child, TaskType::Workflow);
            $this->finishCleanup($child);
        }
        $this->assertTrue($parent->refresh()->completed());
    }

    public function testTerminalParentCloseAlsoRequestsCooperativeChildCleanup(): void
    {
        [$parent, $child] = $this->startWaitingParent();
        $this->assertTrue($parent->attemptTerminate('operator emergency')->accepted());
        $this->assertTrue($parent->refresh()->terminated());
        $root = ParentCloseCancellation::context($parent->run()->fresh());
        $this->assertNotNull($root);
        $this->assertSame(
            $root->rootRequestId,
            CooperativeCancellationDelivery::context($child->run()->fresh())->rootRequestId
        );
        $this->assertFalse($child->run()->fresh()->status->isTerminal());
        $this->runTask($child, TaskType::Workflow);
        $this->finishCleanup($child);
    }

    public function testExpiredOriginalBudgetIsInheritedWithoutAnotherTenMinutes(): void
    {
        [$parent, $child] = $this->startWaitingParent();
        $root = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();
        Carbon::setTestNow(now()->addSeconds(31));
        $parent->attemptTerminate('cleanup worker absent');

        $childContext = CooperativeCancellationDelivery::context($child->run()->fresh());
        $this->assertSame($root->deadline()->toISOString(), $childContext->deadline()->toISOString());
        $this->assertTrue($childContext->deadline()->isPast());
        $this->assertSame(0, $this->eventCount($parent, HistoryEventType::ParentCloseCancellationRequested));
        TaskWatchdog::runPass(respectThrottle: false, runIds: [$child->runId()]);
        $this->assertTrue($child->refresh()->cancelled());
        $this->assertSame([], $child->memo());
    }

    public function testDifferentChildRootIsDiagnosedWithoutClaimingPolicyApplied(): void
    {
        [$parent, $child] = $this->startWaitingParent();
        $existing = $child->requestCancellation('independent request', 90)
            ->cancellationContext();
        $incoming = $parent->requestCancellation('parent request', 30)
            ->cancellationContext();
        $this->runTask($parent, TaskType::Workflow);

        $this->assertSame(0, $this->eventCount($parent, HistoryEventType::ParentClosePolicyApplied));
        $failed = $parent->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::ParentClosePolicyFailed)->sole();
        $this->assertSame('cancellation_root_conflict', $failed->payload['error']);
        $this->assertSame(
            $existing->rootRequestId,
            $failed->payload['request_diagnostics']['existing_root_request_id']
        );
        $this->assertSame(
            $incoming->rootRequestId,
            $failed->payload['request_diagnostics']['incoming_root_request_id']
        );
        $this->assertSame(
            $existing->toArray(),
            CooperativeCancellationDelivery::context($child->run()->fresh())->toArray()
        );
        $this->assertFalse($child->run()->fresh()->status->isTerminal());
        $this->runTask($child, TaskType::Workflow);
        $this->finishCleanup($child);
    }

    public function testMutableTerminalProjectionCannotCreateANewCancellationOrigin(): void
    {
        [$parent, $child] = $this->startWaitingParent();
        $parent->run()
            ->forceFill([
                'status' => RunStatus::Completed,
            ])->save();
        $this->assertSame([], ParentClosePolicyEnforcer::enforce($parent->run()->fresh()));
        $this->assertNull($child->run()->fresh()->cancellation_request_command_id);
        $failed = $parent->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::ParentClosePolicyFailed)->sole();
        $this->assertSame('cancellation_parent_closure_unavailable', $failed->payload['error']);
        $this->assertSame(0, $this->eventCount($parent, HistoryEventType::ParentCloseCancellationRequested));
    }

    public function testLegacyRequestCancelStillClosesChildImmediately(): void
    {
        [$parent, $child] = $this->startWaitingParent('request_cancel');
        $parent->attemptTerminate('operator emergency');
        $this->assertTrue($child->refresh()->cancelled());
        $this->assertSame([], $child->memo());
        $this->assertNull($child->run()->fresh()->cancellation_request_command_id);
        $this->assertSame(0, $this->eventCount($parent, HistoryEventType::ParentCloseCancellationRequested));
        $this->assertSame(ChildCallStatus::Cancelled, $this->childCall($parent)->status);
    }

    public function testDelayedEnforcementUsesRecordedClosureInsteadOfRepairTime(): void
    {
        [$parent, $child] = $this->startWaitingParent();
        $run = $parent->run()
            ->fresh();
        // Reconstruct a closed history with no policy receipt, as seen by repair.
        $closed = WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted);
        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_at' => now(),
        ])->save();
        Carbon::setTestNow(now()->addSeconds(601));
        $this->assertSame([$child->id()], ParentClosePolicyEnforcer::enforce($run->fresh()));

        $root = ParentCloseCancellation::context($run->fresh());
        $this->assertTrue($root->requestedAt()->equalTo($closed->recorded_at));
        $this->assertTrue($root->deadline()->isPast());
        $inherited = CooperativeCancellationDelivery::context($child->run()->fresh());
        $this->assertSame($root->deadline()->toISOString(), $inherited->deadline()->toISOString());
        TaskWatchdog::runPass(respectThrottle: false, runIds: [$child->runId()]);
        $this->assertTrue($child->refresh()->cancelled());
        $this->assertSame([], $child->memo());
    }

    public function testLegacyMissingContextIsDiagnosedWithoutReplacingOriginalBudget(): void
    {
        [$parent, $child] = $this->startWaitingParent();
        $request = $parent->requestCancellation('legacy caller', 30);
        $command = $parent->run()
            ->commands()
            ->findOrFail($request->commandId());
        $command->forceFill([
            'payload' => \Workflow\Serializers\Serializer::serialize([
                'reason' => 'legacy caller',
                'cleanup_deadline_at' => $request->cancellationContext()
                    ->deadline()
                    ->toISOString(),
            ]),
        ])->save();
        $parent->attemptTerminate('operator emergency');

        $failed = $parent->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::ParentClosePolicyFailed)->sole();
        $this->assertSame('cancellation_parent_context_unavailable', $failed->payload['error']);
        $this->assertNull($child->run()->fresh()->cancellation_request_command_id);
        $this->assertSame($request->commandId(), $parent->run()->fresh()->cancellation_request_command_id);
        $this->assertSame(0, $this->eventCount($parent, HistoryEventType::ParentCloseCancellationRequested));
    }

    /**
     * @return array{WorkflowStub, WorkflowStub}
     */
    private function startWaitingParent(string $parentClosePolicy = 'request_cancellation'): array
    {
        $parent = WorkflowStub::make(TestParentChildPolicyWorkflow::class, 'parent-close');
        $parent->start('abandon', false, false, $parentClosePolicy);
        $this->runTask($parent, TaskType::Workflow);
        $child = $this->children($parent)[0];
        $this->runTask($child, TaskType::Workflow);

        return [$parent, $child];
    }

    /**
     * @return list<WorkflowStub>
     */
    private function children(WorkflowStub $parent): array
    {
        return WorkflowLink::query()->where('parent_workflow_run_id', $parent->runId())
            ->where('link_type', 'child_workflow')
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowLink $link): WorkflowStub => WorkflowStub::load($link->child_workflow_instance_id))
            ->all();
    }

    private function childCall(WorkflowStub $parent): WorkflowChildCall
    {
        return WorkflowChildCall::query()->where('parent_workflow_run_id', $parent->runId())->sole();
    }

    private function runTask(WorkflowStub $workflow, TaskType $type): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())
            ->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();
        $this->assertFalse($task->available_at?->isFuture() ?? false);
        $job = $type === TaskType::Workflow ? new RunWorkflowTask($task->id) : new RunTimerTask($task->id);
        $this->app->call([$job, 'handle']);
    }

    private function finishCleanup(WorkflowStub $child): void
    {
        Carbon::setTestNow(now()->addSeconds(2));
        $this->runTask($child, TaskType::Timer);
        $this->runTask($child, TaskType::Workflow);
        $this->assertTrue($child->refresh()->cancelled());
        $this->assertSame([
            'child_cleanup' => 'complete',
        ], $child->memo());
    }

    private function eventCount(WorkflowStub $workflow, HistoryEventType $type): int
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())
            ->where('event_type', $type->value)
            ->count();
    }
}
