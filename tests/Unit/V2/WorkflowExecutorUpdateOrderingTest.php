<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Ulid;
use Tests\TestCase;
use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use function Workflow\V2\signal;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class WorkflowExecutorUpdateOrderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');
        Queue::fake();
        config()
            ->set([
                'queue.default' => 'redis',
                'workflows.v2.compatibility.current' => 'build-a',
                'workflows.v2.compatibility.supported' => ['build-a'],
                'workflows.v2.compatibility.namespace' => 'update-ordering',
                'workflows.v2.task_dispatch_mode' => 'queue',
            ]);
        WorkerCompatibilityFleet::clear();
    }

    protected function tearDown(): void
    {
        Str::createUlidsNormally();
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();
        parent::tearDown();
    }

    /**
     * @param list<int|null> $acceptedOffsets
     * @param list<int> $order
     */
    #[DataProvider('orders')]
    public function testPendingUpdatesRetainDeterministicOrderThroughExecutionReplayAndRedelivery(
        string $projection,
        array $acceptedOffsets,
        array $order
    ): void {
        $workflow = WorkflowStub::make(UpdateOrderingWorkflow::class, 'ordered-updates', 'update-ordering');
        $workflow->start();
        $this->assertSame('waiting', $this->execute($workflow)['run_status']);
        Queue::fake();

        // IDs need not sort in admission order; use the framework's public fixture generator.
        $alphabet = str_split('0123456789ABCDEFGHJKMNPQRSTVWXYZ');
        $counter = 511;
        Str::createUlidsUsing(static function () use (&$counter, $alphabet): Ulid {
            $id = new Ulid(str_pad('01J', 24, '0') . $alphabet[intdiv($counter, 32)] . $alphabet[$counter % 32]);
            --$counter;
            return $id;
        });
        $updates = [];
        $commands = [];
        try {
            foreach ($acceptedOffsets as $index => $offset) {
                Carbon::setTestNow(Carbon::parse('2026-10-08 10:00:00')->addSeconds($offset ?? 0));
                $result = $workflow->submitUpdate('record', 'update-' . $index);
                $this->assertTrue($result->accepted());
                $this->assertFalse($result->completed());
                $this->assertSame($index + 2, $result->commandSequence());
                $updates[] = WorkflowUpdate::query()->findOrFail($result->updateId());
                $commands[] = WorkflowCommand::query()->findOrFail($result->commandId())->getRawOriginal();
            }
        } finally {
            Str::createUlidsNormally();
        }

        $this->assertGreaterThan($updates[1]->id, $updates[0]->id);
        $this->assertGreaterThan($updates[2]->id, $updates[1]->id);
        foreach ($updates as $index => $update) {
            // Legacy projections can lack copied admission metadata. Restored row creation
            // times must not become the execution order instead of sequence/time/stable ID.
            $update->forceFill([
                'command_sequence' => $projection === 'current' || ($projection === 'mixed' && $index === 0)
                    ? $update->command_sequence : null,
                'accepted_at' => $acceptedOffsets[$index] === null ? null : $update->accepted_at,
                'created_at' => Carbon::parse('2026-10-08 10:00:00')->addMinutes($index),
                'updated_at' => Carbon::parse('2026-10-08 10:00:00')->addMinutes($index),
            ])->save();
            $this->assertSame($commands[$index], WorkflowCommand::query()->findOrFail(
                $update->workflow_command_id
            )->getRawOriginal());
            $this->assertSame(UpdateStatus::Accepted, $update->fresh()->status);
        }
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())->where(
            'status',
            TaskStatus::Ready->value
        )->sole();
        Queue::assertPushed(RunWorkflowTask::class, 3);
        $this->assertCount(3, Queue::pushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        ));
        Carbon::setTestNow('2026-10-08 10:05:00');
        $result = $this->execute($workflow);
        $this->assertSame('waiting', $result['run_status']);
        $this->assertNull($result['next_task_id']);
        $expectedIds = array_map(static fn (int $index): string => $updates[$index]->id, $order);
        $expectedEvents = ['started'];
        foreach ($order as $index) {
            $expectedEvents[] = 'update-' . $index;
            $update = $updates[$index]->fresh();
            $this->assertSame(UpdateStatus::Completed, $update->status);
            $this->assertSame('update_completed', $update->outcome->value);
            $this->assertSame($expectedEvents, $update->updateResult());
            $this->assertSame(1, $update->workflow_sequence);
            $this->assertNotNull($update->applied_at);
            $this->assertNotNull($update->closed_at);
            $command = WorkflowCommand::query()->findOrFail($update->workflow_command_id);
            $this->assertSame('update_completed', $command->outcome->value);
            $this->assertTrue($command->applied_at->equalTo($update->applied_at));
        }
        foreach (['UpdateApplied', 'UpdateCompleted'] as $type) {
            $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())->where(
                'event_type',
                $type
            )->orderBy('sequence')
                ->get();
            $this->assertSame(
                $expectedIds,
                $events->map(static fn ($event): string => $event->payload['update_id'])->all()
            );
            $this->assertSame(
                array_map(static fn (int $index): string => $updates[$index]->workflow_command_id, $order),
                $events->map(static fn ($event): string => $event->payload['workflow_command_id'])->all()
            );
        }
        $before = $this->records($workflow->runId());
        $bridge = $this->app->make(DefaultWorkflowTaskBridge::class);
        $duplicate = $bridge->execute($result['task_id']);
        $this->assertFalse($duplicate['executed']);
        $this->assertSame($before, $this->records($workflow->runId()));
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            $loaded = WorkflowStub::load('ordered-updates', 'update-ordering');
            $this->assertSame($expectedEvents, $loaded->state());
            $this->assertSame($before, $this->records($workflow->runId()));
        }

        $this->assertTrue($workflow->signal('finish')->accepted());
        $this->assertSame('completed', $this->execute($workflow)['run_status']);
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($workflow->runId())->status);
        $this->assertSame($expectedEvents, $workflow->refresh()->output());
        $this->assertSame(3, WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())->where(
            'event_type',
            'UpdateApplied'
        )->count());
    }

    /**
     * @return iterable<string, array{string, list<int|null>, list<int>}>
     */
    public static function orders(): iterable
    {
        yield 'command sequence wins over clock skew and descending IDs' => ['current', [2, 1, 0], [0, 1, 2]];
        yield 'legacy sequence falls back to acceptance time' => ['legacy', [2, 1, 0], [2, 1, 0]];
        yield 'legacy equal acceptance uses stable ID before restored creation time' => [
            'legacy',
            [0, 0, 0],
            [2, 1, 0],
        ];
        yield 'legacy missing acceptance uses stable ID' => ['legacy', [null, null, null], [2, 1, 0]];
        yield 'legacy known acceptance precedes missing acceptance' => ['legacy', [null, 0, 1], [1, 2, 0]];
        yield 'sequenced projection precedes legacy projections' => ['mixed', [null, 0, 0], [0, 2, 1]];
    }

    /**
     * @return array<string, mixed>
     */
    private function execute(WorkflowStub $workflow): array
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())->where(
            'status',
            TaskStatus::Ready->value
        )->sole();
        $result = $this->app->make(DefaultWorkflowTaskBridge::class)->execute($task->id);
        $this->assertTrue($result['executed']);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        return $result + [
            'task_id' => $task->id,
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function records(string $runId): array
    {
        $records = [
            WorkflowRun::class => [WorkflowRun::query()->findOrFail($runId)->getRawOriginal()],
        ];
        foreach ([
            WorkflowTask::class,
            WorkflowCommand::class,
            WorkflowHistoryEvent::class,
            WorkflowUpdate::class,
        ] as $model) {
            $records[$model] = $model::query()->where('workflow_run_id', $runId)->orderBy('id')->get()
                ->map(static fn ($row): array => $row->getRawOriginal())
                ->all();
        }
        return $records;
    }
}

#[Signal('finish')]
final class UpdateOrderingWorkflow extends Workflow
{
    public ?string $connection = 'redis';

    public ?string $queue = 'ordered-updates';

    /**
     * @var list<string>
     */
    private array $events = [];

    public function handle(): array
    {
        $this->events[] = 'started';
        signal('finish');
        return $this->events;
    }

    #[UpdateMethod]
    public function record(string $name): array
    {
        $this->events[] = $name;
        return $this->events;
    }

    #[QueryMethod]
    public function state(): array
    {
        return $this->events;
    }
}
