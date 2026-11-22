<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestAllChildAndSignalWorkflow;
use Tests\Fixtures\V2\TestAwaitedChildThenSignalWorkflow;
use Tests\Fixtures\V2\TestChildAwaitedAfterSignalWorkflow;
use Tests\Fixtures\V2\TestChildOrSignalSelectionWorkflow;
use Tests\Fixtures\V2\TestContinueAsNewChildSignalWorkflow;
use Tests\Fixtures\V2\TestMultipleSignalWaitsAfterChildWorkflow;
use Tests\Fixtures\V2\TestNestedChildSignalGroupsWorkflow;
use Tests\Fixtures\V2\TestNeverAwaitedChildSignalWorkflow;
use Tests\Fixtures\V2\TestSelectionLoserHandleAfterStaleWaitWorkflow;
use Tests\Fixtures\V2\TestStaleSelectionSignalHandleWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

/**
 * A run that waits for a signal after a child it already passed has closed is healthy: the watchdog must not
 * give it a no-op workflow task on every pass (#1219). A run that a child closure must resume is still repaired.
 */
final class V2WatchdogNoopTaskTest extends TestCase
{
    private const NAMESPACE = 'watchdog-noop';

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'queue.default' => 'redis',
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.namespaces' => [self::NAMESPACE],
        ]);
    }

    public function testNeverAwaitedClosedChildDoesNotReofferASignalWait(): void
    {
        [$workflow, $runId] = $this->startUntilChildCloses(TestNeverAwaitedChildSignalWorkflow::class);

        $this->assertWaitingForSignal($runId);
        $this->assertWatchdogCreatesNoTasks($runId);

        $workflow->signal('proceed', 'go');
        $this->drain($runId);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'signal' => 'go',
        ], $workflow->output());
    }

    public function testNeverAwaitedChildWithoutParentResolutionDoesNotReofferASignalWait(): void
    {
        [$workflow, $runId] = $this->startUntilChildCloses(TestNeverAwaitedChildSignalWorkflow::class);

        // An unconsumed closure the parent history never recorded leaves the call open, as in #1219.
        $this->deleteChildResolution($runId);
        RunSummaryProjector::project(WorkflowRun::query()->findOrFail($runId));

        $this->assertWaitingForSignal($runId);
        $this->assertWatchdogCreatesNoTasks($runId);

        $workflow->signal('proceed', 'go');
        $this->drain($runId);

        $this->assertTrue($workflow->refresh()->completed());
    }

    public function testNeverAwaitedClosedChildDoesNotReofferASignalWaitWithTimeout(): void
    {
        [$workflow, $runId] = $this->startUntilChildCloses(TestNeverAwaitedChildSignalWorkflow::class, 3600);

        $summary = WorkflowRunSummary::query()->findOrFail($runId);
        $this->assertSame('signal', $summary->wait_kind);
        $this->assertSame('waiting_for_signal', $summary->liveness_state);
        $this->assertWatchdogCreatesNoTasks($runId);

        $workflow->signal('proceed', 'before-timeout');
        $this->drain($runId);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'signal' => 'before-timeout',
        ], $workflow->output());
    }

    public function testConsumedChildDoesNotReofferALaterSignalWait(): void
    {
        [$workflow, $runId] = $this->startUntilChildCloses(TestAwaitedChildThenSignalWorkflow::class);

        $this->assertWaitingForSignal($runId);
        $this->assertWatchdogCreatesNoTasks($runId);

        $workflow->signal('proceed', 'go');
        $this->drain($runId);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'child' => 'child:awaited',
            'signal' => 'go',
        ], $workflow->output());
    }

    public function testAwaitedChildClosureWithALostParentTaskIsRepairedOnce(): void
    {
        [$workflow, $runId] = $this->startUntilChildCloses(
            TestAwaitedChildThenSignalWorkflow::class,
            drainParent: false
        );

        $this->loseParentTask($runId);
        $this->assertSame('repair_needed', WorkflowRunSummary::query()->findOrFail($runId)->liveness_state);

        $this->assertRepairedOnce($runId);
        $this->assertWaitingForSignal($runId);
        $this->assertWatchdogCreatesNoTasks($runId);

        $workflow->signal('proceed', 'go');
        $this->drain($runId);

        $this->assertSame([
            'child' => 'child:awaited',
            'signal' => 'go',
        ], WorkflowStub::loadRun($runId)->output());
    }

    public function testAwaitedChildClosureWithoutParentResolutionOrTaskIsRepairedOnce(): void
    {
        [$workflow, $runId] = $this->startUntilChildCloses(
            TestAwaitedChildThenSignalWorkflow::class,
            drainParent: false
        );

        $this->loseParentTask($runId);
        $this->deleteChildResolution($runId);
        RunSummaryProjector::project(WorkflowRun::query()->findOrFail($runId));
        $this->assertSame('waiting_for_child', WorkflowRunSummary::query()->findOrFail($runId)->liveness_state);

        $this->assertRepairedOnce($runId);
        $this->assertWaitingForSignal($runId);
        $this->assertWatchdogCreatesNoTasks($runId);

        $workflow->signal('proceed', 'go');
        $this->drain($runId);

        $this->assertSame([
            'child' => 'child:awaited',
            'signal' => 'go',
        ], WorkflowStub::loadRun($runId)->output());
    }

    public function testChildClosureThatWinsAnOpenSignalSelectionIsRepairedOnce(): void
    {
        [$workflow, $runId] = $this->startUntilChildCloses(
            TestChildOrSignalSelectionWorkflow::class,
            drainParent: false
        );

        $this->loseParentTask($runId);
        $this->assertSame('repair_needed', WorkflowRunSummary::query()->findOrFail($runId)->liveness_state);

        $this->assertRepairedOnce($runId);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'winner' => 'child',
            'value' => 'child:selected',
        ], $workflow->output());
    }

    public function testStaleSelectionSignalWaitDoesNotHideAChildAwaitedThroughItsHandle(): void
    {
        $workflow = $this->startWorkflow(TestStaleSelectionSignalHandleWorkflow::class);
        $runId = $workflow->runId();
        $workflow->signal('other', 'x');
        $this->drain($runId);

        // The losing `proceed` wait stays open while the run awaits the child through its handle.
        $this->assertSame('waiting_for_child', WorkflowRunSummary::query()->findOrFail($runId)->liveness_state);

        $this->drain($this->childRunId($runId));
        $this->loseParentTask($runId);
        $this->assertSame('repair_needed', WorkflowRunSummary::query()->findOrFail($runId)->liveness_state);

        $this->assertRepairedOnce($runId);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'winner' => 'other',
            'child' => 'child:handle',
        ], $workflow->output());
    }

    public function testStaleSelectionSignalWaitDoesNotHideAChildAwaitedThroughASelectionHandle(): void
    {
        $workflow = $this->startWorkflow(TestSelectionLoserHandleAfterStaleWaitWorkflow::class);
        $runId = $workflow->runId();
        $workflow->signal('first', 'f');
        $this->drain($runId);
        $workflow->signal('third', 't');
        $this->drain($runId);

        // The losing `second` wait stays open while the run awaits the losing child's handle.
        $this->assertSame('waiting_for_child', WorkflowRunSummary::query()->findOrFail($runId)->liveness_state);

        $this->drain($this->childRunId($runId));
        $this->loseParentTask($runId);
        $this->assertSame('repair_needed', WorkflowRunSummary::query()->findOrFail($runId)->liveness_state);

        $this->assertRepairedOnce($runId);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'race' => 'first',
            'later' => 'third',
            'child' => 'child:loser',
        ], $workflow->output());
    }

    public static function selectionMemberShapes(): iterable
    {
        yield 'child' => [false, 1];
        yield 'group of children' => [true, 2];
    }

    #[DataProvider('selectionMemberShapes')]
    public function testMissingSignalSelectionMetadataDoesNotHideAnAwaitedChild(bool $nested, int $childCount): void
    {
        $workflow = $this->startWorkflow(TestSelectionLoserHandleAfterStaleWaitWorkflow::class, false, $nested);
        $runId = $workflow->runId();
        $workflow->signal('first', 'f');
        $this->drain($runId);
        $workflow->signal('third', 't');
        $this->drain($runId);
        $this->assertSame('waiting_for_child', WorkflowRunSummary::query()->findOrFail($runId)->liveness_state);
        $wait = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ChildWorkflowAwaited)->sole();
        $this->assertSame('waiting', $wait->payload['selection_handle']['status']);
        $this->assertSame($childCount, $wait->payload['selection_handle']['member_size']);
        $this->assertArrayNotHasKey('sequence', $wait->payload);

        // Model a stale open wait whose history cannot prove it belonged to the earlier selection.
        $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SignalWaitOpened)->get()
            ->sole(static fn (WorkflowHistoryEvent $event): bool => $event->payload['signal_name'] === 'second');
        $payload = array_filter(
            $event->payload,
            static fn (string $key): bool => ! str_starts_with($key, 'parallel_group_')
                && ! str_starts_with($key, 'selection_member_'),
            ARRAY_FILTER_USE_KEY,
        );
        $this->assertNotSame($event->payload, $payload);
        $event->forceFill([
            'payload' => $payload,
        ])->save();

        RunSummaryProjector::project(WorkflowRun::query()->findOrFail($runId));
        $this->assertSame('waiting_for_child', WorkflowRunSummary::query()->findOrFail($runId)->liveness_state);

        $childRunIds = WorkflowLink::query()->where('parent_workflow_run_id', $runId)
            ->where('link_type', 'child_workflow')
            ->pluck('child_workflow_run_id');
        $this->assertCount($childCount, $childRunIds);
        foreach ($childRunIds as $childRunId) {
            $this->drain($childRunId);
        }
        $this->loseParentTask($runId);
        $this->assertSame('repair_needed', WorkflowRunSummary::query()->findOrFail($runId)->liveness_state);
        $this->assertSame(1, TaskWatchdog::runPass(respectThrottle: false)['repaired_missing_tasks']);
        $this->assertSame(1, $this->openWorkflowTasks($runId)->count());
        $this->assertSame(0, TaskWatchdog::runPass(respectThrottle: false)['repaired_missing_tasks']);
    }

    public function testResolvedSelectionHandleAllowsTheFollowingSignalWaitAtTheSamePosition(): void
    {
        $workflow = $this->startWorkflow(TestSelectionLoserHandleAfterStaleWaitWorkflow::class, true);
        $runId = $workflow->runId();
        $workflow->signal('first', 'f');
        $this->drain($runId);
        $workflow->signal('third', 't');
        $this->drain($runId);
        $this->drain($this->childRunId($runId));
        $this->drain($runId);

        $run = WorkflowRun::query()->with('historyEvents')->findOrFail($runId);
        $records = $run->historyEvents->where('event_type', HistoryEventType::ChildWorkflowAwaited)->values();
        $signal = $run->historyEvents->sole(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::SignalWaitOpened && ($event->payload['signal_name'] ?? null) === 'after');
        $this->assertSame(['waiting', 'resumed'], $records->pluck('payload.selection_handle.status')->all());
        $this->assertSame($records[0]->payload['selection_handle']['await_position'], $signal->payload['sequence']);
        $this->assertGreaterThan($records[1]->sequence, $signal->sequence);
        $this->assertWaitingForSignal($runId, 'after');
        $this->assertWatchdogCreatesNoTasks($runId);

        // An older worker could resume the handle and open the next wait
        // without writing the resume record.
        $run->setRelation('historyEvents', $run->historyEvents->reject(
            static fn (WorkflowHistoryEvent $event): bool => $event->id === $records[1]->id
        ));
        RunSummaryProjector::project($run);
        $this->assertWaitingForSignal($runId, 'after');
        $this->assertWatchdogCreatesNoTasks($runId);

        $workflow->signal('after', 'go');
        $this->drain($runId);
        $this->assertSame([
            'race' => 'first',
            'later' => 'third',
            'child' => 'child:loser',
            'after' => 'go',
        ], $workflow->refresh()
            ->output());
    }

    public function testChildCompletedInsideAnAllGroupThatStillNeedsASignalIsNotReoffered(): void
    {
        [$workflow, $runId] = $this->startUntilChildCloses(TestAllChildAndSignalWorkflow::class);

        $this->assertWaitingForSignal($runId);
        $this->assertWatchdogCreatesNoTasks($runId);

        $workflow->signal('proceed', 'go');
        $this->drain($runId);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(['child:all', 'go'], $workflow->output());
    }

    public function testChildFailureInsideAnAllGroupWithASignalWaitIsStillRepaired(): void
    {
        [$workflow, $runId] = $this->startUntilChildCloses(TestAllChildAndSignalWorkflow::class, drainParent: false);

        // A successful member of an unfinished all() group wakes nothing; a failed one fails the group.
        $this->assertSame(0, $this->openWorkflowTasks($runId)->count());
        WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->update([
                'event_type' => HistoryEventType::ChildRunFailed->value,
            ]);
        RunSummaryProjector::project(WorkflowRun::query()->findOrFail($runId));

        $this->assertSame('repair_needed', WorkflowRunSummary::query()->findOrFail($runId)->liveness_state);
        $this->assertSame(1, TaskWatchdog::runPass(respectThrottle: false)['repaired_missing_tasks']);
        $this->assertSame(1, $this->openWorkflowTasks($runId)->count());
    }

    public function testChildCompletedInsideNestedAllGroupsThatStillNeedSignalsIsNotReoffered(): void
    {
        [$workflow, $runId] = $this->startUntilChildCloses(TestNestedChildSignalGroupsWorkflow::class, 'waits');

        $this->assertWaitingForSignal($runId, 'outer');
        $this->assertWatchdogCreatesNoTasks($runId);

        $workflow->signal('outer', 'o');
        $this->drain($runId);
        $this->assertWaitingForSignal($runId, 'inner');
        $this->assertWatchdogCreatesNoTasks($runId);

        $workflow->signal('inner', 'i');
        $this->drain($runId);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([['child:nested', 'i'], 'o'], $workflow->output());
    }

    public function testChildWhoseNestedGroupWinsASelectionIsRepairedOnce(): void
    {
        [$workflow, $runId] = $this->startUntilChildCloses(
            TestNestedChildSignalGroupsWorkflow::class,
            'wakes',
            drainParent: false
        );

        $this->loseParentTask($runId);
        $this->assertSame('repair_needed', WorkflowRunSummary::query()->findOrFail($runId)->liveness_state);

        $this->assertRepairedOnce($runId);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'winner' => 'group',
            'value' => ['child:nested'],
        ], $workflow->output());
    }

    public function testStaleAndCurrentSignalWaitsAfterAnUnawaitedChildAreNotReoffered(): void
    {
        $workflow = $this->startWorkflow(TestMultipleSignalWaitsAfterChildWorkflow::class);
        $runId = $workflow->runId();
        $workflow->signal('second', 'b');
        $this->drain($runId);
        $this->drain($this->childRunId($runId));
        $this->drain($runId);

        $this->assertWaitingForSignal($runId, 'right');
        $this->assertWatchdogCreatesNoTasks($runId);

        $workflow->signal('left', 'l');
        $this->drain($runId);
        $this->assertWaitingForSignal($runId, 'right');
        $this->assertWatchdogCreatesNoTasks($runId);

        $workflow->signal('right', 'r');
        $this->drain($runId);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'winner' => 'second',
            'pair' => ['l', 'r'],
        ], $workflow->output());
    }

    public function testChildAwaitedThroughItsHandleAfterASignalIsReofferedOnlyOnceAwaited(): void
    {
        [$workflow, $runId] = $this->startUntilChildCloses(TestChildAwaitedAfterSignalWorkflow::class);

        // Until the signal arrives, the closed child is not awaited yet.
        $this->assertWaitingForSignal($runId);
        $this->assertWatchdogCreatesNoTasks($runId);

        $workflow->signal('proceed', 'go');
        $this->drain($runId);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'signal' => 'go',
            'child' => 'child:later',
        ], $workflow->output());
    }

    public function testChildAwaitedThroughItsHandleAfterASignalIsRepairedWhenItsWakeIsLost(): void
    {
        $workflow = $this->startWorkflow(TestChildAwaitedAfterSignalWorkflow::class);
        $runId = $workflow->runId();
        $workflow->signal('proceed', 'go');
        $this->drain($runId);
        $this->assertSame('waiting_for_child', WorkflowRunSummary::query()->findOrFail($runId)->liveness_state);

        $this->drain($this->childRunId($runId));
        $this->loseParentTask($runId);

        $this->assertRepairedOnce($runId);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'signal' => 'go',
            'child' => 'child:later',
        ], $workflow->output());
    }

    public function testContinuedRunWaitingForASignalIsNotReofferedWhenHandedOverAndOwnChildrenClose(): void
    {
        $workflow = $this->startWorkflow(TestContinueAsNewChildSignalWorkflow::class);
        $runs = WorkflowRun::query()->where('workflow_instance_id', $workflow->id())
            ->orderBy('run_number')
            ->get();
        $this->assertCount(2, $runs);
        [$first, $continued] = $runs->all();
        $firstRunId = $first->id;
        $this->assertSame(RunStatus::Completed, $first->status);
        $this->drain($continued->id);

        $handedOverChildId = $this->childRunId($firstRunId);
        $ownChildId = $this->childRunId($continued->id);
        $this->drain($handedOverChildId);
        $this->drain($ownChildId);
        $this->drain($continued->id);
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($handedOverChildId)->status);
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($ownChildId)->status);

        $this->assertWaitingForSignal($continued->id);
        $this->assertWatchdogCreatesNoTasks($continued->id);

        $workflow->signal('proceed', 'go');
        $this->drain($continued->id);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'generation' => 1,
            'signal' => 'go',
        ], $workflow->output());
    }

    private function startWorkflow(string $workflowClass, mixed ...$arguments): WorkflowStub
    {
        $workflow = WorkflowStub::make($workflowClass, sprintf('watchdog-noop-%s', $this->name()), self::NAMESPACE);
        $workflow->start(...$arguments);
        $this->drain($workflow->runId());

        return $workflow;
    }

    private function childRunId(string $runId): string
    {
        return WorkflowLink::query()->where('parent_workflow_run_id', $runId)
            ->where('link_type', 'child_workflow')
            ->sole()
            ->child_workflow_run_id;
    }

    /**
     * @param class-string $workflowClass
     * @return array{0: WorkflowStub, 1: string}
     */
    private function startUntilChildCloses(string $workflowClass, mixed ...$arguments): array
    {
        $drainParent = $arguments['drainParent'] ?? true;
        unset($arguments['drainParent']);

        $workflow = WorkflowStub::make($workflowClass, sprintf('watchdog-noop-%s', $this->name()), self::NAMESPACE);
        $workflow->start(...$arguments);
        $runId = $workflow->runId();
        $this->drain($runId);

        $childId = $this->childRunId($runId);
        $this->drain($childId);
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($childId)->status);

        if ($drainParent) {
            $this->drain($runId);
        }

        return [$workflow, $runId];
    }

    private function assertWaitingForSignal(string $runId, string $signal = 'proceed'): void
    {
        $summary = WorkflowRunSummary::query()->findOrFail($runId);

        $this->assertSame(RunStatus::Waiting->value, $summary->status);
        $this->assertSame('signal', $summary->wait_kind);
        $this->assertSame('waiting_for_signal', $summary->liveness_state);
        $this->assertSame(sprintf('Waiting for signal %s', $signal), $summary->wait_reason);
        $this->assertSame(0, $this->openWorkflowTasks($runId)->count());
    }

    private function assertWatchdogCreatesNoTasks(string $runId): void
    {
        $tasks = WorkflowTask::query()->where('workflow_run_id', $runId)->count();

        for ($pass = 0; $pass < 3; ++$pass) {
            $report = TaskWatchdog::runPass(respectThrottle: false);

            $this->assertSame(0, $report['selected_missing_task_candidates']);
            $this->assertSame(0, $report['repaired_missing_tasks']);
            $this->assertSame(0, $report['dispatched_tasks']);
        }

        $this->assertSame($tasks, WorkflowTask::query()->where('workflow_run_id', $runId)->count());
    }

    private function assertRepairedOnce(string $runId): void
    {
        $report = TaskWatchdog::runPass(respectThrottle: false);

        $this->assertSame(1, $report['repaired_missing_tasks']);
        $this->assertSame([], $report['missing_run_failures']);
        $this->assertSame(1, $this->openWorkflowTasks($runId)->count());

        $this->assertSame(0, TaskWatchdog::runPass(respectThrottle: false)['repaired_missing_tasks']);
        $this->assertSame(1, $this->openWorkflowTasks($runId)->count());

        $this->drain($runId);
    }

    /**
     * The child closure's parent wake-up task is lost before any worker runs it.
     */
    private function loseParentTask(string $runId): void
    {
        $this->assertSame(1, $this->openWorkflowTasks($runId)->delete());
        RunSummaryProjector::project(WorkflowRun::query()->findOrFail($runId));
    }

    private function deleteChildResolution(string $runId): void
    {
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->delete());
        $run = WorkflowRun::query()->findOrFail($runId);
        $run->forceFill([
            'last_history_sequence' => $run->historyEvents()
                ->max('sequence'),
        ])->save();
    }

    private function openWorkflowTasks(string $runId): Builder
    {
        return WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value]);
    }

    private function drain(string $runId): void
    {
        for ($step = 0; $step < 8; ++$step) {
            $task = $this->openWorkflowTasks($runId)
                ->where('status', TaskStatus::Ready->value)
                ->first();

            if (! $task instanceof WorkflowTask) {
                return;
            }

            $this->app->call([new RunWorkflowTask($task->id), 'handle']);
        }

        $this->fail('The finite workflow task drain did not settle.');
    }
}
