<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Workflow\QueryMethod;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Exceptions\DurableOperationCancelledException;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
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

final class WorkflowExecutorSelectionCancellationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 19:55:00');
        Queue::fake();
        SelectionLosingActivity::$executions = 0;
        config()
            ->set([
                'queue.default' => 'redis',
                'workflows.v2.task_dispatch_mode' => 'queue',
                'workflows.v2.workflow_task_lease_seconds' => 300,
                'workflows.v2.compatibility.current' => 'selection-build',
                'workflows.v2.compatibility.supported' => ['selection-build'],
                'workflows.v2.compatibility.namespace' => 'selection-handles',
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
     * @return iterable<string, array{string}>
     */
    public static function pendingOperations(): iterable
    {
        foreach ([
            'activity',
            'timer',
            'signal',
            'signal-timeout',
            'condition',
            'condition-timeout',
            'group',
        ] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('pendingOperations')]
    public function testLosingHandleCancellationIsDurableTypedAndIdempotent(string $kind): void
    {
        $connection = config('database.default');
        $this->assertIsString($connection);
        config()
            ->set('queue.connections.' . $connection, [
                'driver' => 'redis',
                'connection' => 'default',
                'queue' => 'selection-handles',
            ]);
        $workflow = WorkflowStub::make(SelectionCancellationWorkflow::class, 'selection-one', 'selection-handles');
        $this->assertTrue($workflow->attemptStart($kind, new WorkflowOptions(
            connection: $connection,
            queue: 'selection-handles',
        ))->accepted());
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $execution = $bridge->execute($task->id);
        $this->assertTrue($execution['executed']);
        $this->assertSame('completed', $execution['run_status']);
        $this->assertNull($execution['next_task_id']);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $size = $kind === 'group' ? 2 : 1;
        $groupId = 'select-calls:1:' . ($size + 1);
        $operationKind = match ($kind) {
            'signal-timeout' => 'signal',
            'condition-timeout' => 'condition',
            default => $kind,
        };
        $winner = WorkflowTimer::query()->where('workflow_run_id', $run->id)->where('sequence', $size + 1)->sole();
        $identity = match ($operationKind) {
            'activity' => ActivityExecution::query()->where('workflow_run_id', $run->id)->sole()->id,
            'timer' => WorkflowTimer::query()->where('workflow_run_id', $run->id)->where('sequence', 1)->sole()->id,
            'signal', 'condition' => WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->where(
                'event_type',
                $operationKind === 'signal' ? HistoryEventType::SignalWaitOpened->value : HistoryEventType::ConditionWaitOpened->value
            )->sole()
->payload[$operationKind . '_wait_id'],
            default => 'group:1:2',
        };
        $failure = [
            'class' => DurableOperationCancelledException::class,
            'message' => 'Durable ' . $operationKind . ' operation [' . $identity . '] was cancelled.',
            'selection_group_id' => $groupId,
            'member_key' => 'pending',
            'member_index' => 0,
            'operation_kind' => $operationKind,
            'operation_identity' => $identity,
        ];
        $expected = [
            'winner' => [
                'key' => 'deadline',
                'index' => 1,
                'kind' => 'timer',
                'identity' => $winner->id,
                'value' => true,
            ],
            'remaining_keys' => ['pending'],
            'failures' => [$failure, $failure],
        ];
        $this->assertSame($expected, $workflow->refresh()->output());
        $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->where(
            'event_type',
            HistoryEventType::SelectionOperationCancelled->value
        )->sole();
        $this->assertSame($this->ordered([
            'selection_group_id' => $groupId,
            'member_key' => 'pending',
            'member_index' => 0,
            'member_base_sequence' => 1,
            'member_size' => $size,
            'operation_kind' => $operationKind,
            'operation_identity' => $identity,
            'cancelled_at' => '2026-10-08T19:55:00.000000Z',
            'task' => [
                'id' => $task->id,
                'type' => 'workflow',
                'status' => 'leased',
                'available_at' => $task->available_at?->toJSON(),
                'leased_at' => '2026-10-08T19:55:00.000000Z',
                'lease_owner' => $task->id,
                'lease_expires_at' => '2026-10-08T20:00:00.000000Z',
                'attempt_count' => 1,
                'repair_count' => 0,
                'connection' => $connection,
                'queue' => 'selection-handles',
                'compatibility' => 'selection-build',
                'last_dispatch_attempt_at' => $task->last_dispatch_attempt_at?->toJSON(),
                'last_dispatched_at' => $task->last_dispatched_at?->toJSON(),
            ],
        ]), $this->ordered($event->payload));
        $this->assertSame(TimerStatus::Fired, $winner->status);
        $this->assertSame(0, $winner->delay_seconds);
        $activities = ActivityExecution::query()->where('workflow_run_id', $run->id)->get();
        $this->assertCount(in_array($kind, ['activity', 'group'], true) ? 1 : 0, $activities);
        foreach ($activities as $activity) {
            $this->assertSame(ActivityStatus::Cancelled, $activity->status);
            $this->assertSame(0, $activity->attempt_count);
            $this->assertSame('2026-10-08T19:55:00.000000Z', $activity->closed_at?->toJSON());
        }
        $losingTimers = WorkflowTimer::query()->where('workflow_run_id', $run->id)->where(
            'sequence',
            '<=',
            $size
        )->get();
        $this->assertCount(
            in_array($kind, ['timer', 'signal-timeout', 'condition-timeout', 'group'], true) ? 1 : 0,
            $losingTimers
        );
        foreach ($losingTimers as $timer) {
            $this->assertSame(TimerStatus::Cancelled, $timer->status);
            $this->assertSame(300, $timer->delay_seconds);
        }
        $pendingTasks = WorkflowTask::query()->where('workflow_run_id', $run->id)->whereIn('task_type', [
            TaskType::Activity->value, TaskType::Timer->value,
        ])->get();
        $this->assertCount($activities->count() + $losingTimers->count(), $pendingTasks);
        foreach ($pendingTasks as $pendingTask) {
            $this->assertSame(TaskStatus::Cancelled, $pendingTask->status);
            $this->assertNull($pendingTask->lease_expires_at);
        }
        $this->assertSame(0, ActivityAttempt::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, SelectionLosingActivity::$executions);
        app(RunTimelineProjector::class)->project($run->fresh());
        $before = $this->records();
        $this->assertFalse($bridge->execute($task->id)['executed']);
        $this->assertSame($before, $this->records());
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            $loaded = WorkflowStub::load('selection-one', 'selection-handles');
            $this->assertSame($expected, $loaded->query('snapshot'));
            $this->assertSame($expected, $loaded->output());
            $this->assertSame($before, $this->records());
            $this->assertSame(0, SelectionLosingActivity::$executions);
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
            WorkflowHistoryEvent::class, WorkflowTimer::class, ActivityExecution::class, ActivityAttempt::class,
            WorkflowRunSummary::class] as $model) {
            $records[$model] = $model::query()->orderBy('id')->get()->map(
                static fn (Model $row): array => $row->getRawOriginal()
            )->all();
        }
        return $records;
    }
}

#[Type('selection-cancellation-workflow')]
#[Signal('approval')]
final class SelectionCancellationWorkflow extends Workflow
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
        $pending = static fn (): mixed => match ($kind) {
            'activity' => Workflow::activity(SelectionLosingActivity::class),
            'timer' => Workflow::timer(300),
            'signal' => Workflow::awaitSignal('approval'),
            'signal-timeout' => Workflow::await('approval', 300),
            'condition' => Workflow::await(static fn (): bool => false, null, 'selection.ready'),
            'condition-timeout' => Workflow::awaitWithTimeout(300, static fn (): bool => false, 'selection.ready'),
            default => Workflow::all([
                static fn () => Workflow::activity(SelectionLosingActivity::class),
                static fn () => Workflow::timer(300),
            ]),
        };
        $selected = Workflow::select([
            'pending' => $pending,
            'deadline' => static fn () => Workflow::timer(0),
        ]);
        $failures = [];
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            $selected->handles['pending']->cancel();
            try {
                $selected->handles['pending']->await();
                throw new RuntimeException('The cancelled losing operation returned a value.');
            } catch (DurableOperationCancelledException $exception) {
                $failures[] = [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                    'selection_group_id' => $exception->selectionGroupId,
                    'member_key' => $exception->memberKey,
                    'member_index' => $exception->memberIndex,
                    'operation_kind' => $exception->operationKind,
                    'operation_identity' => $exception->operationIdentity,
                ];
            }
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
            'failures' => $failures,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[QueryMethod]
    public function snapshot(): array
    {
        return $this->observed;
    }
}

#[Type('selection-losing-activity')]
final class SelectionLosingActivity extends Activity
{
    public static int $executions = 0;

    public function handle(): string
    {
        ++self::$executions;
        return 'The losing activity executed unexpectedly.';
    }
}
