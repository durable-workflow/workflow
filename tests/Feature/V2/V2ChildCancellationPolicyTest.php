<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestParentChildPolicyWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\CooperativeCancellationDelivery;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class V2ChildCancellationPolicyTest extends TestCase
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

    public function testWaitDefersParentDeliveryUntilChildCleanupHasCanonicalTerminalHistory(): void
    {
        [$parent, $child] = $this->start(CancellationPolicy::WaitCancellationCompleted);
        $root = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();
        $this->runTask($parent, TaskType::Workflow);

        $this->assertSame(RunStatus::Waiting, $parent->run()->fresh()->status);
        $this->assertSame([], $parent->refresh()->memo());
        $this->assertSame(0, $this->eventCount($parent, HistoryEventType::CooperativeCancellationDelivered));
        $context = CooperativeCancellationDelivery::context($child->run()->fresh());
        $this->assertNotNull($context);
        $this->assertSame($root->rootRequestId, $context->rootRequestId);
        $this->assertNotSame($root->requestId, $context->requestId);
        $this->assertSame($root->deadline()->toISOString(), $context->deadline()->toISOString());
        $this->assertFalse($child->run()->fresh()->status->isTerminal());

        $this->runTask($child, TaskType::Workflow);
        $this->assertSame([], $child->refresh()->memo());
        $this->assertSame(1, $this->eventCount($child, HistoryEventType::CooperativeCancellationDelivered));
        $duplicate = $parent->requestCancellation('different reason', 90);
        $this->assertSame($root->toArray(), $duplicate->cancellationContext()->toArray());
        $this->finishChild($child);
        $this->runTask($parent, TaskType::Workflow);

        $this->assertTrue($parent->refresh()->cancelled());
        $this->assertSame([
            'parent_cleanup' => 'complete',
        ], $parent->memo());
        $this->assertSame(1, $this->eventCount($parent, HistoryEventType::ChildCancellationRequested));
        $this->assertSame(1, $this->eventCount($parent, HistoryEventType::ChildCancellationResolved));
        $this->assertSame(1, $this->eventCount($parent, HistoryEventType::CooperativeCancellationDelivered));
        $ack = $parent->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::ChildCancellationResolved)->sole();
        $terminal = $child->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::WorkflowCancelled)->sole();
        $this->assertSame($terminal->id, $ack->payload['child_terminal_history_event_id']);
        $this->assertSame($root->rootRequestId, $ack->payload['root_request_id']);
        $this->assertTrue($parent->run()->fresh()->closed_at->lessThan($root->deadline()));
        $points = collect(HistoryTimeline::forRun($parent->run()->fresh()))
            ->whereIn(
                'type',
                [
                    HistoryEventType::ChildCancellationRequested->value,
                    HistoryEventType::ChildCancellationResolved->value,
                ]
            );
        $this->assertCount(2, $points);
        foreach ($points as $point) {
            $this->assertSame('child', $point['kind']);
            $this->assertSame($child->runId(), $point['child_workflow_run_id']);
            $this->assertSame($root->rootRequestId, $point['child']['cancellation']['root_request_id']);
            $this->assertSame(
                CancellationPolicy::WaitCancellationCompleted->value,
                $point['child']['cancellation']['policy']
            );
        }
    }

    public function testTryCancelRequestsCooperationButDoesNotWaitForChildCleanup(): void
    {
        [$parent, $child] = $this->start(CancellationPolicy::TryCancel);
        $root = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();
        $this->runTask($parent, TaskType::Workflow);

        $this->assertTrue($parent->refresh()->cancelled());
        $this->assertSame([
            'parent_cleanup' => 'complete',
        ], $parent->memo());
        $this->assertFalse($child->run()->fresh()->status->isTerminal());
        $this->assertSame(
            $root->rootRequestId,
            CooperativeCancellationDelivery::context($child->run()->fresh())->rootRequestId
        );
        $this->assertSame(0, $this->eventCount($parent, HistoryEventType::ChildCancellationResolved));
        $this->runTask($child, TaskType::Workflow);
        $this->finishChild($child);
    }

    public function testAbandonLeavesChildUntouched(): void
    {
        [$parent, $child] = $this->start(CancellationPolicy::Abandon);
        $parent->requestCancellation('maintenance', 30);
        $this->runTask($parent, TaskType::Workflow);

        $this->assertTrue($parent->refresh()->cancelled());
        $this->assertNull($child->run()->fresh()->cancellation_request_command_id);
        $this->assertSame(0, $this->eventCount($parent, HistoryEventType::ChildCancellationRequested));
        $this->assertSame(RunStatus::Waiting, $child->run()->fresh()->status);
    }

    public function testMixedParallelWaitFencesActivityButStillWaitsForChildCleanup(): void
    {
        [$parent, $child] = $this->start(CancellationPolicy::WaitCancellationCompleted, true);
        $parent->requestCancellation('maintenance', 30);
        $this->runTask($parent, TaskType::Workflow);

        $this->assertSame(ActivityStatus::Cancelled, $parent->run()->activityExecutions()->sole()->status);
        $this->assertSame([], $parent->refresh()->memo());
        $this->assertSame(0, $this->eventCount($parent, HistoryEventType::CooperativeCancellationDelivered));
        $this->runTask($child, TaskType::Workflow);
        $this->finishChild($child);
        $this->runTask($parent, TaskType::Workflow);
        $this->assertTrue($parent->refresh()->cancelled());
        $this->assertSame([
            'parent_cleanup' => 'complete',
        ], $parent->memo());
    }

    public function testHistoricalMissingPolicyRemainsAbandonOnColdExecution(): void
    {
        [$parent, $child] = $this->start(CancellationPolicy::WaitCancellationCompleted);
        $event = $parent->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled)->sole();
        $payload = $event->payload;
        unset($payload['cancellation_policy']);
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $parent->requestCancellation('maintenance', 30);
        $this->runTask($parent, TaskType::Workflow);

        $this->assertTrue($parent->refresh()->cancelled());
        $this->assertNull($child->run()->fresh()->cancellation_request_command_id);
    }

    public function testSelectedChildHandleWaitsAtItsOriginalOperationRange(): void
    {
        [$parent, $child] = $this->start(CancellationPolicy::WaitCancellationCompleted, selection: true);
        $parent->requestCancellation('maintenance', 30);
        $this->runTask($parent, TaskType::Workflow);
        $this->assertSame([], $parent->refresh()->memo());
        $this->assertSame(0, $this->eventCount($parent, HistoryEventType::CooperativeCancellationDelivered));
        $this->runTask($child, TaskType::Workflow);
        $this->finishChild($child);
        $this->runTask($parent, TaskType::Workflow);

        $this->assertTrue($parent->refresh()->cancelled());
        $delivery = $parent->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::CooperativeCancellationDelivered)->sole();
        $this->assertSame('selection_handle', $delivery->payload['call_kind']);
        $this->assertSame(1, $delivery->payload['operation_sequence']);
        $this->assertSame([
            'parent_cleanup' => 'complete',
        ], $parent->memo());
    }

    public function testIndependentChildRequestKeepsItsRootAndReportsPropagationConflict(): void
    {
        [$parent, $child] = $this->start(CancellationPolicy::WaitCancellationCompleted);
        $childRoot = $child->requestCancellation('independent child request', 45)
            ->cancellationContext();
        $parentRoot = $parent->requestCancellation('parent request', 30)
            ->cancellationContext();
        $this->runTask($parent, TaskType::Workflow);

        $this->assertSame([], $parent->refresh()->memo());
        $this->assertSame(
            $childRoot->toArray(),
            CooperativeCancellationDelivery::context($child->run()->fresh())->toArray()
        );
        $event = $parent->run()
            ->historyEvents()
            ->where('event_type', HistoryEventType::ChildCancellationRequested)->sole();
        $this->assertSame('rejected', $event->payload['request_outcome']);
        $this->assertSame('cancellation_root_conflict', $event->payload['rejection_reason']);
        $this->assertSame($parentRoot->rootRequestId, $event->payload['root_request_id']);
        $this->assertSame($childRoot->rootRequestId, $event->payload['child_root_request_id']);
        $this->runTask($child, TaskType::Workflow);
        $this->finishChild($child);
        $this->runTask($parent, TaskType::Workflow);
        $this->assertTrue($parent->refresh()->cancelled());
        $this->assertSame(
            $parentRoot->deadline()
                ->toISOString(),
            $parent->run()
                ->fresh()
                ->cancellation_deadline_at->toISOString()
        );
    }

    public function testWaitingCannotExtendTheOriginalDeadlineWhenChildCleanupDoesNotFinish(): void
    {
        [$parent, $child] = $this->start(CancellationPolicy::WaitCancellationCompleted);
        $root = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();
        $this->runTask($parent, TaskType::Workflow);
        Carbon::setTestNow($root->deadline()->addSecond());
        TaskWatchdog::runPass(respectThrottle: false, runIds: [$parent->runId(), $child->runId()]);

        foreach ([$parent, $child] as $workflow) {
            $this->assertTrue($workflow->refresh()->cancelled());
            $this->assertSame([], $workflow->memo());
            $this->assertSame(
                $root->deadline()
                    ->toISOString(),
                $workflow->run()
                    ->fresh()
                    ->cancellation_deadline_at->toISOString()
            );
        }
        $this->assertSame(0, $this->eventCount($parent, HistoryEventType::CooperativeCancellationDelivered));
    }

    /**
     * @return array{WorkflowStub, WorkflowStub}
     */
    private function start(CancellationPolicy $policy, bool $parallel = false, bool $selection = false): array
    {
        $parent = WorkflowStub::make(TestParentChildPolicyWorkflow::class);
        $parent->start($policy->value, $parallel, $selection);
        $this->runTask($parent, TaskType::Workflow);
        $link = WorkflowLink::query()->where('parent_workflow_run_id', $parent->runId())->sole();
        $child = WorkflowStub::loadRun($link->child_workflow_run_id);
        $this->runTask($child, TaskType::Workflow);

        return [$parent, $child];
    }

    private function finishChild(WorkflowStub $child): void
    {
        Carbon::setTestNow(now()->addSeconds(2));
        $this->runTask($child, TaskType::Timer);
        $this->runTask($child, TaskType::Workflow);
        $this->assertTrue($child->refresh()->cancelled());
        $this->assertSame([
            'child_cleanup' => 'complete',
        ], $child->memo());
    }

    private function runTask(WorkflowStub $workflow, TaskType $type): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())
            ->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();
        $this->assertFalse($task->available_at?->isFuture() ?? false);
        $job = match ($type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            default => throw new \LogicException('Unexpected test task type.'),
        };
        $this->app->call([$job, 'handle']);
    }

    private function eventCount(WorkflowStub $workflow, HistoryEventType $type): int
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())
            ->where('event_type', $type->value)
            ->count();
    }
}
