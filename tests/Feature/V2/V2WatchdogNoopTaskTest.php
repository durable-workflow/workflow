<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestAwaitedChildThenSignalWorkflow;
use Tests\Fixtures\V2\TestChildOrSignalSelectionWorkflow;
use Tests\Fixtures\V2\TestNeverAwaitedChildSignalWorkflow;
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

        $childId = WorkflowLink::query()->where('parent_workflow_run_id', $runId)->sole()->child_workflow_run_id;
        $this->drain($childId);
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($childId)->status);

        if ($drainParent) {
            $this->drain($runId);
        }

        return [$workflow, $runId];
    }

    private function assertWaitingForSignal(string $runId): void
    {
        $summary = WorkflowRunSummary::query()->findOrFail($runId);

        $this->assertSame(RunStatus::Waiting->value, $summary->status);
        $this->assertSame('signal', $summary->wait_kind);
        $this->assertSame('waiting_for_signal', $summary->liveness_state);
        $this->assertSame('Waiting for signal proceed', $summary->wait_reason);
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
