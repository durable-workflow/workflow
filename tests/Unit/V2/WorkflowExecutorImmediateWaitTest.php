<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Closure;
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
use Workflow\V2\Support\ConditionWaitDefinition;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\RunTimelineProjector;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

final class WorkflowExecutorImmediateWaitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 18:30:00');
        Queue::fake();
        config()->set([
            'queue.default' => 'redis',
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.workflow_task_lease_seconds' => 300,
            'workflows.v2.compatibility.current' => 'immediate-build',
            'workflows.v2.compatibility.supported' => ['immediate-build'],
            'workflows.v2.compatibility.namespace' => 'immediate-waits',
        ]);
        WorkerCompatibilityFleet::clear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, bool, bool, mixed}> */
    public static function outcomes(): iterable
    {
        foreach ([false, true] as $nested) {
            $layout = $nested ? 'nested' : 'single';
            yield $layout . ' condition times out' => ['condition', false, $nested, false];
            yield $layout . ' satisfied condition wins' => ['condition', true, $nested, true];
            yield $layout . ' signal times out' => ['signal', false, $nested, null];
            yield $layout . ' ordinary timer fires' => ['timer', false, $nested, true];
        }
    }

    #[DataProvider('outcomes')]
    public function testImmediateOutcomesKeepDurableIdentityAndReplayWithoutSchedulingDelayedWork(
        string $kind,
        bool $ready,
        bool $nested,
        mixed $value,
    ): void {
        $connection = config('database.default');
        $this->assertIsString($connection);
        config()->set('queue.connections.' . $connection, [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'immediate-waits',
        ]);
        $workflow = WorkflowStub::make(ImmediateWaitWorkflow::class, 'immediate-one', 'immediate-waits');
        $this->assertTrue($workflow->attemptStart($kind, $ready, $nested, new WorkflowOptions(
            connection: $connection,
            queue: 'immediate-waits',
        ))->accepted());
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        $bridge = $this->app->make(DefaultWorkflowTaskBridge::class);
        $execution = $bridge->execute($task->id);
        $this->assertTrue($execution['executed']);
        $this->assertSame('completed', $execution['run_status']);
        $this->assertNull($execution['next_task_id']);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $expected = [
            'phase' => 'done',
            'kind' => $kind,
            'result' => $nested ? [[$value, true]] : $value,
        ];
        $this->assertSame($expected, $workflow->refresh()->output());
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $run->id)->where(
            'task_type', TaskType::Timer->value
        )->count());
        $context = [
            'task' => [
                'id' => $task->id,
                'type' => 'workflow',
                'status' => 'leased',
                'available_at' => $task->available_at?->toJSON(),
                'leased_at' => '2026-10-08T18:30:00.000000Z',
                'lease_owner' => $task->id,
                'lease_expires_at' => '2026-10-08T18:35:00.000000Z',
                'attempt_count' => 1,
                'repair_count' => 0,
                'connection' => $connection,
                'queue' => 'immediate-waits',
                'compatibility' => 'immediate-build',
                'last_dispatch_attempt_at' => $task->last_dispatch_attempt_at?->toJSON(),
                'last_dispatched_at' => $task->last_dispatched_at?->toJSON(),
            ],
        ];
        $groupKind = $kind === 'timer' ? 'timer' : 'mixed';
        $metadata = $this->metadata($nested, $groupKind, 0);
        $waitId = null;
        $fingerprint = null;
        if ($kind !== 'timer') {
            $type = $kind === 'condition'
                ? HistoryEventType::ConditionWaitOpened : HistoryEventType::SignalWaitOpened;
            $opened = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->where(
                'event_type', $type->value
            )->sole();
            $field = $kind === 'condition' ? 'condition_wait_id' : 'signal_wait_id';
            $waitId = $opened->payload[$field];
            $this->assertIsString($waitId);
            $this->assertNotSame('', $waitId);
            $waitPayload = [$field => $waitId, 'sequence' => 1, 'timeout_seconds' => 0];
            if ($kind === 'condition') {
                $fingerprint = ConditionWaitDefinition::fingerprint(ImmediateWaitWorkflow::condition($ready));
                $this->assertIsString($fingerprint);
                $waitPayload += [
                    'condition_key' => 'approval.ready',
                    'condition_definition_fingerprint' => $fingerprint,
                ];
            } else {
                $waitPayload['signal_name'] = 'approve';
            }
            $this->assertSame($this->ordered($waitPayload + $metadata + $context), $this->ordered($opened->payload));
        }
        $timers = WorkflowTimer::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get();
        $firstTimesOut = $kind !== 'condition' || !$ready;
        $this->assertCount(($firstTimesOut ? 1 : 0) + ($nested ? 1 : 0), $timers);
        foreach ($timers as $timer) {
            $this->assertSame(TimerStatus::Fired, $timer->status);
            $this->assertSame(0, $timer->delay_seconds);
            $this->assertSame('2026-10-08T18:30:00.000000Z', $timer->fire_at?->toJSON());
            $this->assertSame($timer->fire_at?->toJSON(), $timer->fired_at?->toJSON());
            $sequence = (int) $timer->sequence;
            $common = ['timer_id' => $timer->id, 'sequence' => $sequence, 'delay_seconds' => 0];
            if ($sequence === 1 && $kind !== 'timer') {
                $common['timer_kind'] = $kind . '_timeout';
                if ($kind === 'condition') {
                    $common += [
                        'condition_wait_id' => $waitId,
                        'condition_key' => 'approval.ready',
                        'condition_definition_fingerprint' => $fingerprint,
                    ];
                } else {
                    $common += ['signal_wait_id' => $waitId, 'signal_name' => 'approve'];
                }
            }
            foreach ([HistoryEventType::TimerScheduled, HistoryEventType::TimerFired] as $type) {
                $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->where(
                    'event_type', $type->value
                )->get()->sole(fn (WorkflowHistoryEvent $row): bool => $row->payload['timer_id'] === $timer->id);
                $timestamp = $type === HistoryEventType::TimerScheduled ? 'fire_at' : 'fired_at';
                $payload = $common + [$timestamp => '2026-10-08T18:30:00.000000Z']
                    + $this->metadata($nested, $groupKind, $sequence - 1) + $context;
                $this->assertSame($this->ordered($payload), $this->ordered($event->payload));
            }
        }
        if ($kind === 'condition') {
            $type = $ready ? HistoryEventType::ConditionWaitSatisfied : HistoryEventType::ConditionWaitTimedOut;
            $resolved = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->where(
                'event_type', $type->value
            )->sole();
            $payload = [
                'condition_wait_id' => $waitId,
                'condition_key' => 'approval.ready',
                'condition_definition_fingerprint' => $fingerprint,
                'sequence' => 1,
                'timeout_seconds' => 0,
            ];
            if (!$ready) {
                $payload['timer_id'] = $timers->first()->id;
            }
            $this->assertSame($this->ordered($payload + $metadata + $context), $this->ordered($resolved->payload));
        }
        app(RunTimelineProjector::class)->project($run->id);
        $before = $this->records();
        $this->assertFalse($bridge->execute($task->id)['executed']);
        $this->assertSame($before, $this->records());
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            $loaded = WorkflowStub::load('immediate-one', 'immediate-waits');
            $this->assertSame($expected, $loaded->state());
            $this->assertSame($expected, $loaded->output());
            $this->assertSame($before, $this->records());
        }
    }

    /** @return array<string, mixed> */
    private function metadata(bool $nested, string $kind, int $index): array
    {
        if (!$nested) {
            return [];
        }
        $entry = [
            'parallel_group_id' => ($kind === 'timer' ? 'parallel-timers' : 'parallel-calls') . ':1:2',
            'parallel_group_kind' => $kind,
            'parallel_group_base_sequence' => 1,
            'parallel_group_size' => 2,
            'parallel_group_index' => $index,
        ];
        return $entry + ['parallel_group_path' => [$entry, $entry]];
    }

    /** @param array<mixed> $value @return array<mixed> */
    private function ordered(array $value): array
    {
        foreach ($value as &$entry) {
            if (is_array($entry)) {
                $entry = $this->ordered($entry);
            }
        }
        unset($entry);
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }

    /** @return array<string, list<array<string, mixed>>> */
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

#[Type('immediate-wait-workflow')]
#[Signal('approve')]
final class ImmediateWaitWorkflow extends Workflow
{
    /** @var array<string, mixed> */
    private array $observed = ['phase' => 'before'];

    public static function condition(bool $ready): Closure
    {
        return static fn (): bool => $ready;
    }

    /** @return array<string, mixed> */
    public function handle(string $kind, bool $ready, bool $nested): array
    {
        $operation = static fn (): mixed => match ($kind) {
            'condition' => Workflow::awaitWithTimeout(0, self::condition($ready), 'approval.ready'),
            'signal' => Workflow::await('approve', 0),
            default => Workflow::timer(0),
        };
        $result = $nested
            ? Workflow::all([static fn () => Workflow::all([$operation, static fn () => Workflow::timer(0)])])
            : $operation();
        return $this->observed = ['phase' => 'done', 'kind' => $kind, 'result' => $result];
    }

    /** @return array<string, mixed> */
    #[QueryMethod]
    public function state(): array
    {
        return $this->observed;
    }
}
