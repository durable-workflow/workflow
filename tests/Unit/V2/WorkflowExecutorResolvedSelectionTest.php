<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\RunTimelineProjector;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

final class WorkflowExecutorResolvedSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 20:30:00');
        Queue::fake();
        config()
            ->set([
                'queue.default' => 'redis',
                'workflows.v2.task_dispatch_mode' => 'queue',
                'workflows.v2.compatibility.current' => 'resolved-build',
                'workflows.v2.compatibility.supported' => ['resolved-build'],
                'workflows.v2.compatibility.namespace' => 'resolved-handles',
            ]);
        WorkerCompatibilityFleet::clear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, mixed, int, int}>
     */
    public static function terminalOperations(): iterable
    {
        yield 'timer' => ['timer', true, 1, 2];
        yield 'signal deadline' => ['signal', null, 1, 2];
        yield 'satisfied condition' => ['condition-true', true, 1, 1];
        yield 'condition deadline' => ['condition-false', false, 1, 2];
        yield 'timer group' => ['group-timer', [true, true], 2, 3];
        yield 'mixed group' => ['group-mixed', [true, null, false], 3, 4];
        yield 'nested mixed group' => ['nested-mixed', [[true, null, false]], 3, 4];
    }

    #[DataProvider('terminalOperations')]
    public function testResolvedLosingHandlesKeepTheirTypedOutcomesAndWinningHistory(
        string $kind,
        mixed $value,
        int $memberSize,
        int $timerCount,
    ): void {
        $connection = config('database.default');
        $this->assertIsString($connection);
        config()
            ->set('queue.connections.' . $connection, [
                'driver' => 'redis',
                'connection' => 'default',
                'queue' => 'resolved-handles',
            ]);
        $workflow = WorkflowStub::make(ResolvedSelectionWorkflow::class, 'resolved-one', 'resolved-handles');
        $this->assertTrue($workflow->attemptStart($kind, new WorkflowOptions(
            connection: $connection,
            queue: 'resolved-handles',
        ))->accepted());
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $execution = $bridge->execute($task->id);
        $this->assertTrue($execution['executed']);
        $this->assertSame('completed', $execution['run_status']);
        $this->assertNull($execution['next_task_id']);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $winner = WorkflowTimer::query()->where('workflow_run_id', $run->id)->where('sequence', 1)->sole();
        $expected = [
            'winner' => [
                'key' => 'deadline',
                'index' => 0,
                'kind' => 'timer',
                'identity' => $winner->id,
                'value' => true,
            ],
            'remaining_keys' => ['resolved'],
            'values' => [$value, $value],
        ];
        $this->assertSame($expected, $workflow->refresh()->output());
        $resolution = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->where(
            'event_type',
            HistoryEventType::TimerFired->value
        )->get()
            ->sole(static fn (WorkflowHistoryEvent $event): bool => $event->payload['timer_id'] === $winner->id);
        $selected = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->where(
            'event_type',
            HistoryEventType::SelectionResolved->value
        )->sole();
        $this->assertSame($this->ordered([
            'selection_group_id' => 'select-calls:1:' . ($memberSize + 1),
            'selection_group_base_sequence' => 1,
            'selection_group_size' => $memberSize + 1,
            'member_key' => 'deadline',
            'member_index' => 0,
            'member_base_sequence' => 1,
            'member_size' => 1,
            'operation_kind' => 'timer',
            'operation_identity' => $winner->id,
            'outcome' => 'completed',
            'resolution_event_id' => $resolution->id,
            'resolution_event_type' => HistoryEventType::TimerFired->value,
        ]), $this->ordered($selected->payload));
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->where(
            'event_type',
            HistoryEventType::SelectionOperationCancelled->value
        )->count());
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $run->id)->where(
            'task_type',
            TaskType::Timer->value
        )->count());
        $timers = WorkflowTimer::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get();
        $this->assertCount($timerCount, $timers);
        foreach ($timers as $timer) {
            $this->assertSame(TimerStatus::Fired, $timer->status);
            $this->assertSame(0, $timer->delay_seconds);
            $this->assertSame('2026-10-08T20:30:00.000000Z', $timer->fire_at?->toJSON());
            $this->assertSame($timer->fire_at?->toJSON(), $timer->fired_at?->toJSON());
        }
        app(RunTimelineProjector::class)->project($run->fresh());
        $before = $this->records();
        $this->assertFalse($bridge->execute($task->id)['executed']);
        $this->assertSame($before, $this->records());
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            $loaded = WorkflowStub::load('resolved-one', 'resolved-handles');
            $this->assertSame($expected, $loaded->query('stateSnapshot'));
            $this->assertSame($expected, $loaded->output());
            $this->assertSame($before, $this->records());
        }
    }

    /**
     * @param array<mixed> $value @return array<mixed>
     */
    private function ordered(array $value): array
    {
        foreach ($value as &$entry) {
            if (is_array($entry)) {
                $entry = $this->ordered($entry);
            }
        }
        unset($entry);
        if (! array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function records(): array
    {
        $records = [];
        foreach ([WorkflowInstance::class, WorkflowRun::class, WorkflowTask::class, WorkflowCommand::class,
            WorkflowHistoryEvent::class, WorkflowTimer::class, WorkflowRunSummary::class] as $model) {
            $records[$model] = $model::query()->orderBy('id')->get()->map(
                static fn (Model $row): array => $row->getRawOriginal()
            )->all();
        }
        return $records;
    }
}

#[Type('resolved-selection-workflow')]
#[Signal('approval')]
final class ResolvedSelectionWorkflow extends Workflow
{
    /**
     * @var array<string, mixed>
     */
    private array $observed = [];

    /**
     * @return array<string, mixed>
     */
    public function handle(string $kind): array
    {
        $mixed = static fn (): mixed => Workflow::all([
            static fn () => Workflow::timer(0),
            static fn () => Workflow::await('approval', 0),
            static fn () => Workflow::awaitWithTimeout(0, static fn (): bool => false, 'resolved.ready'),
        ]);
        $other = static fn (): mixed => match ($kind) {
            'timer' => Workflow::timer(0),
            'signal' => Workflow::await('approval', 0),
            'condition-true' => Workflow::awaitWithTimeout(0, static fn (): bool => true, 'resolved.ready'),
            'condition-false' => Workflow::awaitWithTimeout(0, static fn (): bool => false, 'resolved.ready'),
            'group-timer' => Workflow::all([
                static fn () => Workflow::timer(0),
                static fn () => Workflow::timer(0),
            ]),
            'group-mixed' => $mixed(),
            default => Workflow::all([$mixed]),
        };
        $selected = Workflow::select([
            'deadline' => static fn () => Workflow::timer(0),
            'resolved' => $other,
        ]);
        $values = [];
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            $selected->handles['resolved']->cancel();
            $values[] = $selected->handles['resolved']->await();
        }
        return $this->observed = [
            'winner' => [
                'key' => $selected->key,
                'index' => $selected->index,
                'kind' => $selected->kind,
                'identity' => $selected->identity,
                'value' => $selected->result(),
            ],
            'remaining_keys' => array_keys($selected->remaining()),
            'values' => $values,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[QueryMethod]
    public function stateSnapshot(): array
    {
        return $this->observed;
    }
}
