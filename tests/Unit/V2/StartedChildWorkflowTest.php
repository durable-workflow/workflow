<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestChildPolicyCleanupWorkflow;
use Tests\TestCase;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartedChildWorkflow;
use Workflow\V2\Support\ChildWorkflowOptions;
use Workflow\V2\Support\CooperativeCancellationDelivery;
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
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ChildWorkflowScheduled)->count()
        );
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ChildWorkflowAwaited)->count()
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
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ChildWorkflowAwaited)->count()
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

    public static function substitutedTargets(): iterable
    {
        yield 'unrecorded current continuation' => [false];
        yield 'foreign terminal resolution in the same instance' => [true];
    }

    #[DataProvider('substitutedTargets')]
    public function testLaterSameInstanceRunsCannotReplaceTheAdmittedTarget(bool $resolution): void
    {
        [$parent, $run] = $this->startParent();
        $receipt = $this->queryAdmission($run);
        $parent->signal('proceed');
        $this->drainParent($run);
        $initial = WorkflowRun::query()->findOrFail($receipt['childRunId']);
        $later = $initial->replicate();
        $later->run_number = 2;
        $later->status = RunStatus::Completed;
        $later->closed_at = now();
        $later->closed_reason = 'completed';
        $later->save();
        WorkflowHistoryEvent::record($later, HistoryEventType::WorkflowStarted, [
            'workflow_type' => $later->workflow_type,
            'workflow_instance_id' => $later->workflow_instance_id,
            'workflow_run_id' => $later->id,
        ]);
        WorkflowHistoryEvent::record($later, HistoryEventType::WorkflowCompleted, []);
        $initial->instance()
            ->update([
                'current_run_id' => $later->id,
                'run_count' => 2,
            ]);
        if ($resolution) {
            WorkflowHistoryEvent::record($run, HistoryEventType::ChildRunCompleted, [
                'sequence' => $receipt['startSequence'],
                'child_workflow_instance_id' => $receipt['childInstanceId'],
                'child_workflow_run_id' => $later->id,
                'child_call_id' => $receipt['childCallId'],
                'child_workflow_type' => $receipt['workflowType'],
            ]);
        } else {
            $initial->update([
                'status' => RunStatus::Completed,
                'closed_reason' => 'continued',
            ]);
        }
        $this->assertReadAndExecutionRefuseWithoutHistoryWrites($run);
    }

    public static function childSuccessions(): iterable
    {
        yield 'native continue as new' => ['continue', false];
        yield 'native retry' => ['retry', false];
        yield 'substituted continuation edge' => ['continue', true];
        yield 'substituted retry edge' => ['retry', true];
    }

    #[DataProvider('childSuccessions')]
    public function testOnlyRecordedNativeSuccessionsExtendTheReceipt(string $mode, bool $substitute): void
    {
        [$parent, $run] = $this->startParent(childMode: $mode);
        $receipt = $this->queryAdmission($run);
        if ($mode === 'retry') {
            // The embedded options do not author retry policy yet. Exercise
            // the real native retry producer with its recorded policy input.
            $scheduled = $run->historyEvents()
                ->where('event_type', HistoryEventType::ChildWorkflowScheduled)->sole();
            $scheduled->update([
                'payload' => array_merge($scheduled->payload, [
                    'retry_policy' => [
                        'snapshot_version' => 1,
                        'max_attempts' => 2,
                        'backoff_seconds' => [],
                        'non_retryable_error_types' => [],
                    ],
                ]),
            ]);
        }
        $this->runTaskFor(WorkflowRun::query()->findOrFail($receipt['childRunId']));
        $successor = WorkflowRun::query()->where('workflow_instance_id', $receipt['childInstanceId'])
            ->where('run_number', 2)
            ->sole();
        $this->assertSame(RunStatus::Pending, $successor->status);
        $this->assertSame($receipt, $this->queryAdmission($run->fresh()));
        $parent->signal('proceed');
        $this->drainParent($run);
        $this->assertSame(RunStatus::Waiting, $run->fresh()->status);
        if ($substitute) {
            $started = $successor->historyEvents()
                ->where('event_type', HistoryEventType::WorkflowStarted)->sole();
            $payload = $started->payload;
            $payload[$mode === 'retry' ? 'retry_of_child_workflow_run_id' : 'continued_from_run_id'] = 'unrelated';
            $started->update([
                'payload' => $payload,
            ]);
            $this->assertReadAndExecutionRefuseWithoutHistoryWrites($run);
            return;
        }
        $this->runTaskFor($successor);
        $this->drainParent($run);
        $this->assertSame(RunStatus::Completed, $run->fresh()->status);
        $this->assertSame('child-result', WorkflowStub::loadRun($run->id)->output()['result']);
        $this->assertSame($receipt, $this->queryAdmission($run->fresh()));
    }

    public static function cancellationPolicies(): iterable
    {
        foreach (CancellationPolicy::cases() as $policy) {
            yield $policy->value => [$policy];
        }
    }

    #[DataProvider('cancellationPolicies')]
    public function testStartedAwaitCancellationHasACanonicalColdBoundary(CancellationPolicy $policy): void
    {
        Carbon::setTestNow('2026-11-13T12:00:00Z');
        try {
            [$parent, $run, $child] = $this->startCancellationParent($policy);
            $root = $parent->requestCancellation('maintenance', 30)
                ->cancellationContext();
            $this->drainParent($run);
            if ($policy === CancellationPolicy::WaitCancellationCompleted) {
                $this->assertSame(RunStatus::Waiting, $run->fresh()->status);
                $this->assertNull(CooperativeCancellationDelivery::recorded($run->fresh(['historyEvents'])));
                $this->runTaskFor($child);
                Carbon::setTestNow(now()->addSeconds(2));
                $timer = WorkflowTask::query()->where('workflow_run_id', $child->id)
                    ->where('task_type', TaskType::Timer)->where('status', TaskStatus::Ready)->firstOrFail();
                app()
                    ->call([new RunTimerTask($timer->id), 'handle']);
                $this->drainParent($child);
                $this->assertSame(RunStatus::Cancelled, $child->fresh()->status);
                $this->drainParent($run);
            }
            $fresh = $run->fresh(['historyEvents']);
            $this->assertSame(RunStatus::Waiting, $fresh->status);
            $event = CooperativeCancellationDelivery::recorded($fresh);
            $this->assertNotNull($event);
            $this->assertSame('started_child_await', $event->payload['call_kind']);
            $this->assertSame(2, $event->payload['sequence']);
            $this->assertSame(1, $event->payload['operation_sequence']);
            $this->assertNull(CooperativeCancellationDelivery::validate(
                $fresh,
                $root->requestId,
                2,
                'started_child_await',
                operationSequence: 1
            ));
            $before = $fresh->historyEvents()
                ->get()
                ->toArray();
            $this->assertSame('waiting', (new QueryStateReplayer())->query($fresh, 'phase'));
            $this->assertSame($before, $fresh->historyEvents()->get()->toArray());
            $this->resume($run);
            $this->assertSame($before, $run->historyEvents()->get()->toArray());
            Carbon::setTestNow(now()->addSecond());
            $timer = WorkflowTask::query()->where('workflow_run_id', $run->id)
                ->where('task_type', TaskType::Timer)->where('status', TaskStatus::Ready)->firstOrFail();
            app()
                ->call([new RunTimerTask($timer->id), 'handle']);
            $this->drainParent($run);
            $this->assertSame(RunStatus::Cancelled, $run->fresh()->status);
            $this->assertSame('cleaned', (new QueryStateReplayer())->query($run->fresh(), 'phase'));
            $this->assertSame(
                $policy === CancellationPolicy::Abandon,
                $child->fresh()->cancellation_request_command_id === null
            );
            if ($policy !== CancellationPolicy::WaitCancellationCompleted) {
                $this->assertSame(RunStatus::Waiting, $child->fresh()->status);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testCancellationCannotReplaceTheOriginalOperationWithAnotherAdmittedChild(): void
    {
        [$parent, $run] = $this->startCancellationParent(CancellationPolicy::Abandon, secondChild: true);
        $root = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();
        $this->drainParent($run);
        $event = CooperativeCancellationDelivery::recorded($run->fresh(['historyEvents']));
        $this->assertNotNull($event);
        $event->update([
            'payload' => array_merge($event->payload, [
                'operation_sequence' => 2,
            ]),
        ]);
        $fresh = $run->fresh(['historyEvents']);
        $this->assertNotNull(CooperativeCancellationDelivery::validate(
            $fresh,
            $root->requestId,
            3,
            'started_child_await',
            operationSequence: 2
        ));
        $before = $fresh->historyEvents()
            ->get()
            ->toArray();
        try {
            (new QueryStateReplayer())->query($fresh, 'phase');
            $this->fail('A later valid child admission cannot replace the awaited receipt.');
        } catch (HistoryEventShapeMismatchException) {
            $this->assertSame($before, $fresh->historyEvents()->get()->toArray());
        }
        $this->resume($run);
        $this->assertSame($before, $run->historyEvents()->get()->toArray());
        $this->assertSame(RunStatus::Waiting, $run->fresh()->status);
        $blocked = WorkflowTask::query()->where('workflow_run_id', $run->id)->where(
            'status',
            TaskStatus::Failed
        )->sole();
        $this->assertSame('history_shape_mismatch', $blocked->payload['replay_blocked_reason']);
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
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ChildWorkflowAwaited)->count()
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

    private function startCancellationParent(CancellationPolicy $policy, bool $secondChild = false): array
    {
        $parent = WorkflowStub::make(CancellationMilestoneParent::class, 'cancelling-parent', 'client-a');
        $parent->start($policy->value, $secondChild);
        $run = WorkflowRun::query()->findOrFail($parent->runId());
        $this->runTaskFor($run);
        $first = $run->historyEvents()
            ->where('event_type', HistoryEventType::ChildRunStarted)->firstOrFail();
        $child = WorkflowRun::query()->findOrFail($first->payload['child_workflow_run_id']);
        $this->runTaskFor($child);
        return [$parent, $run, $child];
    }

    private function assertReadAndExecutionRefuseWithoutHistoryWrites(WorkflowRun $run): void
    {
        $before = $run->historyEvents()
            ->get()
            ->toArray();
        try {
            $this->queryAdmission($run->fresh());
            $this->fail('A receipt must refuse an unrecorded or substituted descendant.');
        } catch (HistoryEventShapeMismatchException) {
            $this->assertSame($before, $run->historyEvents()->get()->toArray());
        }
        $this->resume($run);
        $this->assertSame($before, $run->historyEvents()->get()->toArray());
        $this->assertSame(RunStatus::Waiting, $run->fresh()->status);
        $failed = WorkflowTask::query()->where('workflow_run_id', $run->id)->where(
            'status',
            TaskStatus::Failed
        )->sole();
        $this->assertSame('history_shape_mismatch', $failed->payload['replay_blocked_reason']);
    }

    private function startParent(string $mutation = '', string $childMode = ''): array
    {
        $stub = WorkflowStub::make(MilestoneParent::class, 'milestone-parent', 'client-a');
        $stub->start($mutation, $childMode);
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

    public function handle(string $mutation, string $childMode = ''): array
    {
        $child = self::startChild(MilestoneChild::class, $childMode);
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
    public function handle(string $mode = '', int $generation = 0): string
    {
        if ($mode === 'continue' && $generation === 0) {
            self::continueAsNew($mode, 1);
        }
        if ($mode === 'retry' && $this->run->run_number === 1) {
            throw new RuntimeException('first child attempt fails');
        }
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

final class CancellationMilestoneParent extends Workflow
{
    public string $state = 'waiting';

    public function handle(string $policy, bool $secondChild): void
    {
        try {
            $child = self::startChild(
                TestChildPolicyCleanupWorkflow::class,
                new ChildWorkflowOptions(cancellationPolicy: CancellationPolicy::from($policy))
            );
            if ($secondChild) {
                self::startChild(MilestoneChild::class);
            }
            $child->await();
        } catch (WorkflowCancellationRequestedException) {
            \Workflow\V2\cancellationShield(function (): void {
                \Workflow\V2\timer(1);
                \Workflow\V2\upsertMemo([
                    'parent_cleanup' => 'complete',
                ]);
                $this->state = 'cleaned';
            });
        }
    }

    #[QueryMethod]
    public function phase(): string
    {
        return $this->state;
    }
}
