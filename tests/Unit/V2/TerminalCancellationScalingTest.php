<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\ActivityCancellation;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\TimerCancellation;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

#[Type('terminal-cancellation-scaling')]
#[Signal('advance')]
final class TerminalCancellationScalingWorkflow extends Workflow
{
    public function handle(): void
    {
    }
}

final class TerminalCancellationCountingHistory extends WorkflowHistoryEvent
{
    public static bool $watch = false;

    public static int $reads = 0;

    protected function castAttribute($key, $value)
    {
        if (self::$watch && in_array($key, ['event_type', 'payload'], true)) {
            ++self::$reads;
        }

        return parent::castAttribute($key, $value);
    }
}

final class TerminalCancellationScalingTest extends TestCase
{
    public static function terminalInventories(): iterable
    {
        foreach (['cancel', 'terminate'] as $command) {
            yield $command . ' timers' => [$command, 198, 0];
            yield $command . ' activities' => [$command, 0, 198];
            yield $command . ' mixed' => [$command, 99, 99];
        }
    }

    #[DataProvider('terminalInventories')]
    public function testTerminalCloseReadsHistoryInProportionToTheInventory(
        string $command,
        int $timers,
        int $activities,
    ): void {
        config([
            'workflows.v2.history_event_model' => TerminalCancellationCountingHistory::class,
        ]);
        [$workflow, $run] = $this->admit($timers, $activities);
        $before = $run->historyEvents()
            ->count();
        $hydrated = 0;
        Event::listen('eloquent.retrieved: *', static function (string $name, array $data) use (&$hydrated): void {
            if (TerminalCancellationCountingHistory::$watch && ($data[0] ?? null) instanceof WorkflowHistoryEvent) {
                ++$hydrated;
            }
        });
        TerminalCancellationCountingHistory::$reads = 0;
        TerminalCancellationCountingHistory::$watch = true;
        try {
            $result = $command === 'cancel' ? $workflow->attemptCancel('inventory close')
                : $workflow->attemptTerminate('inventory close');
        } finally {
            TerminalCancellationCountingHistory::$watch = false;
        }

        $this->assertTrue($result->accepted());
        $this->assertSame($command === 'cancel' ? 'cancelled' : 'terminated', $run->fresh()->status->value);
        $this->assertClosedInventory($run, $timers, $activities);

        // Count model reads rather than elapsed time so a slow runner keeps the
        // same contract. A terminal close must not repeatedly fold prior history
        // or rehydrate the growing cancellation prefix for each pending item.
        $historyUnits = $before + 2 * ($timers + $activities) + 64;
        $this->assertLessThanOrEqual(128 * $historyUnits, TerminalCancellationCountingHistory::$reads);
        $this->assertLessThanOrEqual(8 * $historyUnits, $hydrated);

        $historyCount = $run->historyEvents()
            ->count();
        $retry = $command === 'cancel' ? $workflow->attemptCancel('inventory close retry')
            : $workflow->attemptTerminate('inventory close retry');
        $this->assertFalse($retry->accepted());
        $this->assertSame($historyCount, $run->historyEvents()->count());
        $this->assertClosedInventory($run, $timers, $activities);
    }

    public function testRolledBackCloseDoesNotReuseCancellationHistoryOnRetry(): void
    {
        [$workflow, $run] = $this->admit(3, 3);
        $before = $run->historyEvents()
            ->count();
        DB::beginTransaction();
        try {
            $this->assertTrue($workflow->attemptTerminate('rolled back')->accepted());
            $this->assertClosedInventory($run, 3, 3);
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->assertTrue($workflow->refresh()->attemptCancel('committed retry')->accepted());
        $this->assertClosedInventory($run, 3, 3);
    }

    public function testClosePreservesPriorCancellationsAndLatestScheduledTimerMetadata(): void
    {
        [$workflow, $run] = $this->admit(3, 3);
        $timers = $run->timers()
            ->orderBy('sequence')
            ->get();
        $priorTimer = TimerCancellation::record($run, $timers[0]);
        $execution = ActivityExecution::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->firstOrFail();
        $priorActivity = ActivityCancellation::record($run, $execution);
        // A repaired mutable row cannot erase an authoritative cancellation.
        $execution->forceFill([
            'status' => 'pending',
            'closed_at' => null,
        ])->save();
        $metadata = [
            'timer_id' => $timers[1]->id,
            'sequence' => $timers[1]->sequence,
            'delay_seconds' => 123,
            'fire_at' => now()
                ->addSeconds(123)
                ->toJSON(),
            'signal_wait_id' => 'metadata-wait',
            'signal_name' => 'advance',
        ];
        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, $metadata);

        $this->assertTrue($workflow->attemptTerminate('complete inventory')->accepted());
        $this->assertClosedInventory($run, 3, 3);
        $this->assertNotNull($priorActivity);
        $events = $run->historyEvents()
            ->orderBy('sequence')
            ->get();
        $this->assertTrue($events->contains('id', $priorTimer->id));
        $this->assertTrue($events->contains('id', $priorActivity->id));
        $cancelled = $events->first(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::TimerCancelled
            && ($event->payload['timer_id'] ?? null) === $timers[1]->id);
        $this->assertNotNull($cancelled);
        $this->assertSame($metadata, array_intersect_key($cancelled->payload, $metadata));
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('workflows.v1.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
            '--force' => true,
        ])->assertExitCode(0);
    }

    /**
     * @return array{WorkflowStub, WorkflowRun}
     */
    private function admit(int $timers, int $activities): array
    {
        Queue::fake();
        $workflow = WorkflowStub::make(TerminalCancellationScalingWorkflow::class);
        $workflow->start();
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $commands = [
            ...array_fill(0, $timers, [
                'type' => 'start_timer',
                'delay_seconds' => 3600,
            ]),
            ...array_fill(0, $activities, [
                'type' => 'schedule_activity',
                'activity_type' => 'inventory-activity',
            ]),
        ];
        while ($commands !== []) {
            $task = WorkflowTask::query()->where('workflow_run_id', $run->id)
                ->where('task_type', 'workflow')
                ->where('status', 'ready')
                ->sole();
            $this->assertNotNull($bridge->claim($task->id, 'inventory-worker'));
            $batch = array_splice($commands, 0, 99);
            $batch[] = [
                'type' => 'open_signal_wait',
                'signal_name' => 'advance',
            ];
            $result = $bridge->completeForProtocol($task->id, $batch, '1.20');
            $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
            if ($commands !== []) {
                $this->assertTrue($workflow->signal('advance')->accepted());
            }
        }

        return [$workflow, $run];
    }

    private function assertClosedInventory(WorkflowRun $run, int $timers, int $activities): void
    {
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $run->id)
            ->whereIn('status', ['ready', 'leased'])->count());
        $this->assertSame($timers, WorkflowTimer::query()->where('workflow_run_id', $run->id)
            ->where('status', 'cancelled')
            ->count());
        $this->assertSame($activities, ActivityExecution::query()->where('workflow_run_id', $run->id)
            ->where('status', 'cancelled')
            ->count());
        $this->assertSame(
            $timers,
            $run->historyEvents()->where('event_type', HistoryEventType::TimerCancelled)->count()
        );
        $this->assertSame(
            $activities,
            $run->historyEvents()->where('event_type', HistoryEventType::ActivityCancelled)->count()
        );
    }
}
