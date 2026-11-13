<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartedChildWorkflow;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Support\WorkflowStepHistory;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class StartedChildWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'queue.default' => 'redis',
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.namespaces' => ['client-a'],
        ]);
    }

    public function testConfirmedStartPrecedesChildExecutionAndSurvivesAColdDuplicateResume(): void
    {
        [$parent, $run] = $this->startParent();
        $receipt = $this->queryAdmission($run);
        $this->assertSame($run->id, $receipt['parentRunId']);
        $child = WorkflowRun::query()->findOrFail($receipt['childRunId']);
        $this->assertSame(RunStatus::Pending, $child->status);
        $this->assertSame($child->workflow_instance_id, $receipt['childInstanceId']);
        $this->assertSame('client-a', $receipt['namespace']);
        $this->assertSame(1, WorkflowLink::query()->where('parent_workflow_run_id', $run->id)->count());

        // The acknowledgement can be lost while admission has committed.
        // A completely new task/fiber must reuse the original start.
        $this->resume($run);
        $this->assertSame($receipt, $this->queryAdmission($run->fresh()));
        $this->assertSame(2, WorkflowInstance::query()->count());
        $this->assertSame(
            1,
            $run->historyEvents()->where('event_type', HistoryEventType::ChildWorkflowScheduled)->count()
        );
        $this->assertSame(
            0,
            $run->historyEvents()->where('event_type', HistoryEventType::ChildWorkflowAwaited)->count()
        );
        $this->assertFalse($parent->completed());
    }

    public function testLaterAwaitUsesTheSameChildWithoutAnotherAdmission(): void
    {
        [$parent, $run] = $this->startParent();
        $receipt = $this->queryAdmission($run);
        $parent->signal('proceed');
        $this->drainParent($run);
        $this->assertSame(
            1,
            $run->historyEvents()->where('event_type', HistoryEventType::ChildWorkflowAwaited)->count()
        );
        $this->assertFalse($parent->completed());
        $child = WorkflowRun::query()->findOrFail($receipt['childRunId']);
        $this->runTaskFor($child);
        $this->drainParent($run);
        $parent = WorkflowStub::loadRun($run->id);
        $this->assertTrue($parent->completed());
        $this->assertSame([
            'admission' => $receipt,
            'result' => 'child-result',
        ], $parent->output());
        $this->assertSame(2, WorkflowInstance::query()->count());
        $this->assertSame(1, WorkflowLink::query()->where('parent_workflow_run_id', $run->id)->count());
        $this->assertSame($receipt, $this->queryAdmission($run->fresh()));
    }

    public function testChildMayCompleteBeforeTheLaterAwaitWithoutChangingAdmissionIdentity(): void
    {
        [$parent, $run] = $this->startParent();
        $receipt = $this->queryAdmission($run);
        $this->runTaskFor(WorkflowRun::query()->findOrFail($receipt['childRunId']));
        $this->drainParent($run);
        $this->assertSame($receipt, $this->queryAdmission($run->fresh()));
        $this->assertFalse($parent->completed());
        $parent->signal('proceed');
        $this->drainParent($run);
        $this->assertSame('child-result', WorkflowStub::loadRun($run->id)->output()['result']);
        $this->assertSame(2, WorkflowInstance::query()->count());
    }

    public static function receiptSubstitutions(): iterable
    {
        foreach (['namespace', 'parentInstanceId', 'parentRunId', 'childInstanceId', 'childRunId',
            'childCallId', 'workflowType', 'startSequence'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('receiptSubstitutions')]
    public function testForeignOrStaleReceiptsCannotAuthorizeAnAwait(string $field): void
    {
        [, $run] = $this->startParent($field);
        $stub = WorkflowStub::loadRun($run->id);
        $stub->signal('proceed');
        $this->drainParent($run);
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->assertSame(
            0,
            $run->historyEvents()->where('event_type', HistoryEventType::ChildWorkflowAwaited)->count()
        );
        $this->assertSame(2, WorkflowInstance::query()->count());
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::WorkflowFailed)->count());
        $blocked = WorkflowTask::query()->where('workflow_run_id', $run->id)->where(
            'status',
            TaskStatus::Failed
        )->sole();
        $this->assertTrue($blocked->payload['replay_blocked']);
        $this->assertSame('history_shape_mismatch', $blocked->payload['replay_blocked_reason']);
    }

    public function testReadOnlyReplayRefusesAnIncompleteAdmissionWithoutInventingAChild(): void
    {
        [, $run] = $this->startParent();
        $run->historyEvents()
            ->where('event_type', HistoryEventType::ChildRunStarted)->delete();
        $before = $run->historyEvents()
            ->get()
            ->toArray();
        try {
            $this->queryAdmission($run->fresh());
            $this->fail('A scheduled request alone must not become a start receipt.');
        } catch (HistoryEventShapeMismatchException) {
            $this->assertSame($before, $run->historyEvents()->get()->toArray());
            $this->assertSame(2, WorkflowInstance::query()->count());
        }
    }

    public function testOrdinaryChildStillWaitsForItsTerminalResult(): void
    {
        $parent = WorkflowStub::make(OrdinaryMilestoneParent::class, 'ordinary-parent', 'client-a');
        $parent->start();
        $run = WorkflowRun::query()->findOrFail($parent->runId());
        $this->runTaskFor($run);
        $this->assertFalse($parent->completed());
        $child = WorkflowRun::query()->where('id', '!=', $run->id)->sole();
        $this->runTaskFor($child);
        $this->drainParent($run);
        $this->assertSame('child-result', WorkflowStub::loadRun($run->id)->output());
        $this->assertArrayNotHasKey('child_completion_mode', $run->historyEvents()->where(
            'event_type',
            HistoryEventType::ChildWorkflowScheduled
        )->sole()
->payload);
    }

    public function testPortableWorkerCannotMistakeAQueuedCommandForNativeAdmission(): void
    {
        try {
            WorkflowFiberRunner::forClass(
                MilestoneParent::class,
                'portable-parent',
                'portable-run',
                [''],
                'avro'
            )->step();
            $this->fail('Portable admission is not implemented by the embedded milestone.');
        } catch (UnsupportedWorkflowYieldException $error) {
            $this->assertStringContainsString('embedded native execution', $error->getMessage());
            $this->assertSame(0, WorkflowInstance::query()->count());
        }
    }

    public function testAStartedMilestoneCannotBeReplayedAsAnOrdinaryTerminalChildCall(): void
    {
        [, $run] = $this->startParent();
        $before = $run->historyEvents()
            ->get()
            ->toArray();
        try {
            WorkflowStepHistory::assertCompatible(
                $run->fresh(['historyEvents']),
                1,
                WorkflowStepHistory::CHILD_WORKFLOW,
                [
                    'child_completion_mode' => 'result',
                ]
            );
            $this->fail('Changing child call completion semantics must require a compatible definition.');
        } catch (HistoryEventShapeMismatchException) {
            $this->assertSame($before, $run->historyEvents()->get()->toArray());
        }
    }

    private function startParent(string $mutation = ''): array
    {
        $stub = WorkflowStub::make(MilestoneParent::class, 'milestone-parent', 'client-a');
        $stub->start($mutation);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $this->runTaskFor($run);
        return [$stub, $run];
    }

    private function queryAdmission(WorkflowRun $run): array
    {
        return (new QueryStateReplayer())->query($run->fresh(), 'admitted');
    }

    private function runTaskFor(WorkflowRun $run): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow)->where('status', TaskStatus::Ready)->firstOrFail();
        app()
            ->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function resume(WorkflowRun $run): void
    {
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Ready,
            'payload' => [],
            'available_at' => now(),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
        ]);
        app()
            ->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function drainParent(WorkflowRun $run): void
    {
        for ($i = 0; $i < 8; ++$i) {
            if (! WorkflowTask::query()->where('workflow_run_id', $run->id)->where('task_type', TaskType::Workflow)
                ->where('status', TaskStatus::Ready)->exists()) {
                return;
            }
            $this->runTaskFor($run);
        }
        $this->fail('The finite parent task drain did not settle.');
    }
}

#[Signal('proceed')]
final class MilestoneParent extends Workflow
{
    public array $admission = [];

    public function handle(string $mutation): array
    {
        $child = self::startChild(MilestoneChild::class);
        $this->admission = $child->toArray();
        self::awaitSignal('proceed');
        if ($mutation !== '') {
            $value = $child->toArray();
            $value[$mutation] = $mutation === 'startSequence' ? 99 : 'foreign-value';
            $child = StartedChildWorkflow::fromArray($value);
        }
        return [
            'admission' => $this->admission,
            'result' => $child->await(),
        ];
    }

    #[QueryMethod]
    public function admitted(): array
    {
        return $this->admission;
    }
}

final class MilestoneChild extends Workflow
{
    public function handle(): string
    {
        return 'child-result';
    }
}

final class OrdinaryMilestoneParent extends Workflow
{
    public function handle(): mixed
    {
        return self::child(MilestoneChild::class);
    }
}
