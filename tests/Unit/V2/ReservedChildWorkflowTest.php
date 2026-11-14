<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;
use Workflow\QueryMethod;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StateMachine\Binding\BindingAuthorization;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;
use Workflow\V2\StateMachine\Binding\NeverAdmittedChild;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class ReservedChildWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'queue.default' => 'redis',
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.namespaces' => ['tenant-a'],
        ]);
    }

    public function testRecordedReservationAndColdNativeReplayKeepOneChildAndOriginalAdmission(): void
    {
        [$stub, $parent, $reservation] = $this->reserveFromRecordedActivity();
        $this->runNext($parent, TaskType::Workflow);
        $original = (new QueryStateReplayer())->query($parent->fresh(), 'admitted');
        $this->assertSame($reservation->childInstanceId(), $original['childInstanceId']);
        $this->assertSame(2, WorkflowInstance::query()->count());
        $this->assertSame(2, WorkflowRun::query()->count());
        $this->assertSame(1, ActivityExecution::query()->count());
        $this->assertSame(1, ActivityAttempt::query()->count());

        // A fresh task/executor/fiber replays the lost workflow acknowledgement.
        WorkflowTask::query()->create([
            'workflow_run_id' => $parent->id,
            'namespace' => $parent->namespace,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Ready,
            'available_at' => now(),
            'payload' => [],
            'connection' => $parent->connection,
            'queue' => $parent->queue,
            'compatibility' => $parent->compatibility,
        ]);
        $this->runNext($parent, TaskType::Workflow);
        $this->assertSame($original, (new QueryStateReplayer())->query($parent->fresh(), 'admitted'));
        $this->assertSame(2, WorkflowRun::query()->count());
        $this->assertSame(1, ActivityAttempt::query()->count());
        $binding = (new ChildReservationStore(new ReservationFixtureAuthorization()))->close(
            $reservation,
            'fixture-owner'
        );
        $this->assertInstanceOf(ChildBinding::class, $binding);
        $this->assertSame($original, $binding->admission->toArray());

        $child = WorkflowRun::query()->findOrFail($original['childRunId']);
        $this->runNext($child, TaskType::Workflow);
        $this->assertSame(RunStatus::Completed, $child->fresh()->status);
        $stub->signal('finish');
        $this->runNext($parent, TaskType::Workflow);
        $this->assertSame(RunStatus::Completed, $parent->fresh()->status);
        $this->assertSame('Hello, Taylor!', $stub->refresh()->output());
        $this->assertSame(2, WorkflowRun::query()->count());
    }

    public function testCloseAfterReservationActivityFencesTheDelayedParentTask(): void
    {
        [, $parent, $reservation] = $this->reserveFromRecordedActivity();
        $store = new ChildReservationStore(new ReservationFixtureAuthorization());
        $closed = $store->close($reservation, 'fixture-owner');
        $this->assertInstanceOf(NeverAdmittedChild::class, $closed);
        $this->runNext($parent, TaskType::Workflow);
        $this->assertSame(RunStatus::Failed, $parent->fresh()->status);
        $this->assertSame(
            0,
            WorkflowRun::query()->where('workflow_instance_id', $reservation->childInstanceId())->count()
        );
        $this->assertSame($closed->closureId, $store->close($reservation, 'fixture-owner')->closureId);
        $this->assertSame(1, ActivityAttempt::query()->count());
    }

    private function reserveFromRecordedActivity(): array
    {
        $stub = WorkflowStub::make(ReservedParentFixture::class, 'reserved-parent-fixture', 'tenant-a');
        $stub->start();
        $parent = WorkflowRun::query()->findOrFail($stub->runId());
        $this->runNext($parent, TaskType::Workflow);
        $this->runNext($parent, TaskType::Activity);
        $reservation = (new QueryStateReplayer())->query($parent->fresh(), 'reserved');
        return [$stub, $parent, ChildReservation::fromArray($reservation)];
    }

    private function runNext(WorkflowRun $run, TaskType $type): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->where('task_type', $type)
            ->where('status', TaskStatus::Ready)->firstOrFail();
        $job = $type === TaskType::Activity ? new RunActivityTask($task->id) : new RunWorkflowTask($task->id);
        app()
            ->call([$job, 'handle']);
    }
}

final class ReservationFixtureAuthorization implements BindingAuthorization
{
    public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
    {
        if ($principal !== 'fixture-owner' || $identity->namespace !== 'tenant-a'
            || $identity->parentExecution->scope !== 'fixture-app'
            || $identity->parentExecution->subject !== 'fixture-subject') {
            throw new LogicException('fixture_authorization_refused');
        }
        return 'fixture-policy-v1';
    }
}

final class ReserveChildFixtureActivity extends Activity
{
    public function handle(): array
    {
        $identity = new BindingIdentity(
            new ExecutionReference('fixture-app', 'fixture-subject', 'execution-1', str_repeat(
                'a',
                64
            ), 'ready', 'rev-1'),
            'tenant-a',
            $this->workflowId(),
            $this->runId(),
            'child',
            'entry-1',
            'working',
            TypeRegistry::for(ReservedChildFixture::class),
            str_repeat('b', 64),
            'delivered',
            ChildReservation::inputFingerprint(['Taylor']),
        );
        return (new ChildReservationStore(new ReservationFixtureAuthorization()))->reserve(
            $identity,
            ReservedChildFixture::class,
            'fixture-owner',
        )->toArray();
    }
}

#[Signal('finish')]
final class ReservedParentFixture extends Workflow
{
    public array $reservation = [];

    public array $admission = [];

    public function handle(): mixed
    {
        $this->reservation = self::activity(ReserveChildFixtureActivity::class);
        $child = ChildReservation::fromArray($this->reservation)->start('Taylor');
        $this->admission = $child->toArray();
        self::awaitSignal('finish');
        return $child->await();
    }

    #[QueryMethod]
    public function admitted(): array
    {
        return $this->admission;
    }

    #[QueryMethod]
    public function reserved(): array
    {
        return $this->reservation;
    }
}

final class ReservedChildFixture extends Workflow
{
    public function handle(string $name): string
    {
        return 'Hello, ' . $name . '!';
    }
}
