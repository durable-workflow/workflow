<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Casts\Json;
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
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\ActivityCancellation;
use Workflow\V2\Support\ChildRunHistory;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\TerminalCancellationHistory;
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

final class TerminalCancellationCustomCommand extends WorkflowCommand
{
    private static int $labels = 0;

    public function callerLabel(): ?string
    {
        return 'configured-command-' . ++self::$labels;
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

    public function testParentMetadataLookupDoesNotHydrateTheWholeHistory(): void
    {
        [, $run] = $this->admit(198, 0);
        $run->unsetRelation('historyEvents');
        $hydrated = 0;
        Event::listen('eloquent.retrieved: ' . WorkflowHistoryEvent::class, static function () use (&$hydrated): void {
            ++$hydrated;
        });
        $this->assertNull(ChildRunHistory::parentReferenceForRun($run));
        $this->assertSame(1, $hydrated);
        $this->assertFalse($run->relationLoaded('historyEvents'));
        $this->assertNull(ChildRunHistory::continuedFromRunId($run));
        $this->assertSame(2, $hydrated);
    }

    public function testParentMetadataLookupPreservesAnAlreadyLoadedApplicationView(): void
    {
        [, $run] = $this->admit(3, 0);
        $run->load('historyEvents');
        $started = $run->historyEvents->firstWhere('event_type', HistoryEventType::WorkflowStarted);
        $this->assertInstanceOf(WorkflowHistoryEvent::class, $started);
        $started->payload = [
            ...$started->payload,
            'parent_workflow_instance_id' => 'application-parent-instance',
            'parent_workflow_run_id' => 'application-parent-run',
            'parent_sequence' => 7,
            'child_call_id' => 'application-child-call',
        ];
        $this->assertSame([
            'parent_workflow_instance_id' => 'application-parent-instance',
            'parent_workflow_run_id' => 'application-parent-run',
            'parent_sequence' => 7,
            'child_call_id' => 'application-child-call',
        ], ChildRunHistory::parentReferenceForRun($run));
    }

    public function testParentMetadataUsesOneRetrievedApplicationViewOfTheStartedEvent(): void
    {
        [, $run] = $this->admit(2, 0);
        $started = $run->historyEvents()
            ->where('event_type', HistoryEventType::WorkflowStarted)->sole();
        $started->payload = [
            ...$started->payload,
            'parent_workflow_instance_id' => 'observer-parent-instance',
            'parent_workflow_run_id' => 'observer-parent-run',
            'parent_sequence' => 7,
            'child_call_id' => 'stored-child-call',
        ];
        $started->save();
        $retrieved = 0;
        WorkflowHistoryEvent::retrieved(static function (WorkflowHistoryEvent $event) use (&$retrieved): void {
            ++$retrieved;
            $event->payload = [
                ...$event->payload,
                'child_call_id' => 'observer-child-' . $retrieved,
            ];
        });
        $this->assertSame([
            'parent_workflow_instance_id' => 'observer-parent-instance',
            'parent_workflow_run_id' => 'observer-parent-run',
            'parent_sequence' => 7,
            'child_call_id' => 'observer-child-1',
        ], ChildRunHistory::parentReferenceForRun($run->fresh()));
        $this->assertSame(1, $retrieved);
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

    public function testEveryCancellationRetainsTheAcceptedTerminalCommandSnapshot(): void
    {
        [$workflow, $run] = $this->admit(3, 3);
        $this->assertTrue($workflow->attemptTerminate('snapshot authority')->accepted());
        $requested = $run->historyEvents()
            ->where('event_type', HistoryEventType::TerminateRequested)->sole();
        $cancelled = $run->historyEvents()
            ->whereIn('event_type', [HistoryEventType::ActivityCancelled, HistoryEventType::TimerCancelled])->get();
        $this->assertCount(6, $cancelled);
        foreach ($cancelled as $event) {
            $this->assertSame($requested->payload['command'], $event->payload['command']);
        }
    }

    public function testChangedCommandAttributesAreSnapshottedAgainAfterAModelHook(): void
    {
        [$workflow, $run] = $this->admit(3, 3);
        $command = null;
        WorkflowCommand::created(static function (WorkflowCommand $created) use (&$command): void {
            if ($created->command_type->value === 'terminate') {
                $command = $created;
            }
        });
        WorkflowHistoryEvent::created(static function (WorkflowHistoryEvent $event) use (&$command): void {
            if ($event->event_type === HistoryEventType::ActivityCancelled && $command instanceof WorkflowCommand) {
                $command->forceFill([
                    'context' => [
                        'caller' => [
                            'label' => 'changed during close',
                        ],
                    ],
                ]);
            }
        });
        $this->assertTrue($workflow->attemptTerminate('model hook')->accepted());
        $cancelled = $run->historyEvents()
            ->whereIn('event_type', [
                HistoryEventType::ActivityCancelled, HistoryEventType::TimerCancelled,
            ])->orderBy('sequence')
            ->get();
        $this->assertCount(6, $cancelled);
        $this->assertNotSame('changed during close', $cancelled[0]->payload['command']['caller_label'] ?? null);
        foreach ($cancelled->slice(1) as $event) {
            $this->assertSame('changed during close', $event->payload['command']['caller_label'] ?? null);
        }
    }

    public static function requestedEvents(): iterable
    {
        yield 'cancel' => ['cancel', HistoryEventType::CancelRequested];
        yield 'terminate' => ['terminate', HistoryEventType::TerminateRequested];
    }

    #[DataProvider('requestedEvents')]
    public function testRequestedEventPayloadMutationCannotReplaceTheCommandSnapshot(
        string $operation,
        HistoryEventType $requestedType,
    ): void {
        [$workflow, $run] = $this->admit(2, 2);
        WorkflowHistoryEvent::created(static function (WorkflowHistoryEvent $event) use ($requestedType): void {
            if ($event->event_type === $requestedType) {
                $payload = $event->payload;
                $payload['command']['caller_label'] = 'event-only observer mutation';
                $event->forceFill([
                    'payload' => $payload,
                ]);
            }
        });
        $result = $operation === 'cancel' ? $workflow->attemptCancel('observer payload')
            : $workflow->attemptTerminate('observer payload');
        $this->assertTrue($result->accepted());
        $requested = $run->historyEvents()
            ->where('event_type', $requestedType)
            ->sole();
        $expected = $requested->payload['command'];
        $this->assertNotSame('event-only observer mutation', $expected['caller_label']);
        $cancelled = $run->historyEvents()
            ->whereIn('event_type', [HistoryEventType::ActivityCancelled, HistoryEventType::TimerCancelled])->get();
        $this->assertCount(4, $cancelled);
        foreach ($cancelled as $event) {
            $this->assertSame($expected, $event->payload['command']);
        }
    }

    #[DataProvider('requestedEvents')]
    public function testRequestedEventObserverChangesAreIncludedInEveryCancellationSnapshot(
        string $operation,
        HistoryEventType $requestedType,
    ): void {
        [$workflow, $run] = $this->admit(2, 2);
        $command = null;
        WorkflowCommand::created(static function (WorkflowCommand $created) use (&$command, $operation): void {
            if ($created->command_type->value === $operation) {
                $command = $created;
            }
        });
        WorkflowHistoryEvent::created(static function (WorkflowHistoryEvent $event) use (
            &$command,
            $requestedType
        ): void {
            if ($event->event_type === $requestedType && $command instanceof WorkflowCommand) {
                $command->forceFill([
                    'context' => [
                        'caller' => [
                            'label' => 'changed by requested event observer',
                        ],
                    ],
                ]);
            }
        });
        $result = $operation === 'cancel' ? $workflow->attemptCancel('observer context')
            : $workflow->attemptTerminate('observer context');
        $this->assertTrue($result->accepted());
        $requested = $run->historyEvents()
            ->where('event_type', $requestedType)
            ->sole();
        $this->assertNotSame('changed by requested event observer', $requested->payload['command']['caller_label']);
        $cancelled = $run->historyEvents()
            ->whereIn('event_type', [HistoryEventType::ActivityCancelled, HistoryEventType::TimerCancelled])->get();
        $this->assertCount(4, $cancelled);
        foreach ($cancelled as $event) {
            $this->assertSame('changed by requested event observer', $event->payload['command']['caller_label']);
        }
    }

    public function testCommandSubclassSnapshotHooksStillRunForEachCancellation(): void
    {
        [, $run] = $this->admit(3, 3);
        $command = TerminalCancellationCustomCommand::record($run->instance, $run, [
            'command_type' => 'terminate',
            'status' => 'accepted',
            'outcome' => 'terminated',
            'target_scope' => 'instance',
            'source' => 'php_api',
            'accepted_at' => now(),
        ]);
        $requested = WorkflowHistoryEvent::record($run, HistoryEventType::TerminateRequested, [
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'command_type' => 'terminate',
        ], null, $command);
        $history = TerminalCancellationHistory::forRun($run, $command, $requested);
        foreach ($run->activityExecutions as $execution) {
            ActivityCancellation::record($run, $execution, command: $command, terminalHistory: $history);
        }
        foreach ($run->timers as $timer) {
            TimerCancellation::record($run, $timer, command: $command, terminalHistory: $history);
        }
        $cancelled = $run->historyEvents()
            ->whereIn('event_type', [HistoryEventType::ActivityCancelled, HistoryEventType::TimerCancelled])->get();
        $this->assertCount(6, $cancelled);
        $labels = $cancelled->map(
            static fn (WorkflowHistoryEvent $event): string => $event->payload['command']['caller_label']
        )->all();
        $this->assertCount(6, array_unique($labels));
        foreach ($labels as $label) {
            $this->assertStringStartsWith('configured-command-', $label);
        }
    }

    public static function decoderActivation(): iterable
    {
        yield 'before snapshot preparation' => [false];
        yield 'after the first cancellation' => [true];
    }

    #[DataProvider('decoderActivation')]
    public function testApplicationJsonDecoderChangesKeepTheirSnapshotHooks(bool $duringClose): void
    {
        if (! class_exists(Json::class)) {
            $this->markTestSkipped('Laravel 9 has no configurable JSON decoder API.');
        }
        [$workflow, $run] = $this->admit(3, 3);
        $calls = 0;
        $install = static function () use (&$calls): void {
            Json::decodeUsing(static function (string $json, bool $asArray = true) use (&$calls): mixed {
                $value = json_decode($json, $asArray, 512, JSON_THROW_ON_ERROR);
                if (is_array($value) && is_array($value['caller'] ?? null)) {
                    $value['caller']['label'] = 'json-hook-' . ++$calls;
                }

                return $value;
            });
        };
        try {
            if ($duringClose) {
                WorkflowHistoryEvent::created(static function (WorkflowHistoryEvent $event) use ($install): void {
                    if ($event->event_type === HistoryEventType::ActivityCancelled) {
                        $install();
                    }
                });
            } else {
                $install();
            }
            $this->assertTrue($workflow->attemptTerminate('decoder hooks')->accepted());
        } finally {
            Json::decodeUsing(null);
        }
        $labels = $run->historyEvents()
            ->whereIn('event_type', [
                HistoryEventType::ActivityCancelled, HistoryEventType::TimerCancelled,
            ])->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): string => $event->payload['command']['caller_label'])->all();
        $this->assertCount(6, $labels);
        $hooked = $duringClose ? array_slice($labels, 1) : $labels;
        $this->assertCount(count($hooked), array_unique($hooked));
        foreach ($hooked as $label) {
            $this->assertStringStartsWith('json-hook-', $label);
        }
        $this->assertGreaterThan(6, $calls);
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
            $run->historyEvents()
                ->where('event_type', HistoryEventType::TimerCancelled)->count()
        );
        $this->assertSame(
            $activities,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancelled)->count()
        );
    }
}
