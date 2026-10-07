<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestAliasedUpdateWorkflow;
use Tests\Fixtures\V2\TestQueryLocalActivityWorkflow;
use Tests\Fixtures\V2\TestQueryReplayGuardActivity;
use Tests\Fixtures\V2\TestSignalThenUpdateWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\LocalActivityCall;
use Workflow\V2\Support\LocalActivityRuntime;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\SignalCall;
use Workflow\V2\Support\TimerCall;
use Workflow\V2\Workflow;

final class QueryStateReplayerOutcomeTest extends TestCase
{
    #[DataProvider('localActivityPrefixes')]
    public function testUnresolvedLocalActivityStaysSuspendedWithoutExecutingIt(?string $eventType): void
    {
        TestQueryReplayGuardActivity::$executions = 0;
        $run = $this->createRun(TestQueryLocalActivityWorkflow::class);
        if ($eventType !== null) {
            $this->appendEvent($run, HistoryEventType::from($eventType), $this->localActivityPayload());
        }
        $before = $this->runtimeSnapshot($run);

        $state = (new QueryStateReplayer())->replayState($run->fresh());
        $queried = (new QueryStateReplayer())->query($run->fresh(), 'currentState');

        $this->assertInstanceOf(LocalActivityCall::class, $state->current);
        $this->assertSame([
            'stage' => 'waiting-for-local-activity',
            'greeting' => null,
            'failure' => null,
        ], $queried);
        $this->assertSame($queried, $state->workflow->currentState());
        $this->assertSame(0, TestQueryReplayGuardActivity::$executions);
        $this->assertSame($before, $this->runtimeSnapshot($run));
    }

    public static function localActivityPrefixes(): array
    {
        return [
            'not scheduled' => [null],
            'scheduled' => [HistoryEventType::ActivityScheduled->value],
            'started' => [HistoryEventType::ActivityStarted->value],
        ];
    }

    #[DataProvider('localActivityOutcomes')]
    public function testLocalActivityOutcomeRestoresQueryStateAndStopsAtTheNextWait(
        string $eventType,
        array $payload,
        ?string $greeting,
        ?array $failure,
    ): void {
        TestQueryReplayGuardActivity::$executions = 0;
        $run = $this->createRun(TestQueryLocalActivityWorkflow::class);
        if (array_key_exists('result_value', $payload)) {
            $payload['result'] = Serializer::serializeWithCodec($run->payload_codec, $payload['result_value']);
            unset($payload['result_value']);
        }
        $this->appendEvent($run, HistoryEventType::ActivityScheduled, $this->localActivityPayload());
        $this->appendEvent($run, HistoryEventType::from($eventType), $payload + $this->localActivityPayload());
        $before = $this->runtimeSnapshot($run);
        $expected = [
            'stage' => 'waiting-for-timer',
            'greeting' => $greeting,
            'failure' => $failure,
        ];

        foreach ([1, 2] as $replay) {
            $state = (new QueryStateReplayer())->replayState(WorkflowRun::query()->findOrFail($run->id));

            $this->assertInstanceOf(TimerCall::class, $state->current);
            $this->assertSame(2, $state->sequence);
            $this->assertSame($expected, $state->workflow->currentState());
            $this->assertSame($expected, (new QueryStateReplayer())->query($run->fresh(), 'currentState'));
            $this->assertSame(0, TestQueryReplayGuardActivity::$executions);
            $this->assertSame($before, $this->runtimeSnapshot($run));
        }
    }

    public static function localActivityOutcomes(): array
    {
        return [
            'completed' => [HistoryEventType::ActivityCompleted->value, [
                'result_value' => 'recorded greeting',
            ], 'recorded greeting', null],
            'failed with exception' => [
                HistoryEventType::ActivityFailed->value, [
                    'exception' => [
                        'class' => RuntimeException::class,
                        'message' => 'recorded failure',
                        'code' => 7,
                    ],
                ], null, [
                    'class' => RuntimeException::class,
                    'message' => 'recorded failure',
                    'code' => 7,
                ]],
            'timed out with portable metadata' => [
                HistoryEventType::ActivityTimedOut->value, [
                    'exception_class' => RuntimeException::class,
                    'message' => 'original timeout',
                    'code' => 8,
                ], null, [
                    'class' => RuntimeException::class,
                    'message' => 'original timeout',
                    'code' => 8,
                ]],
            'cancelled without exception metadata' => [HistoryEventType::ActivityCancelled->value, [], null, [
                'class' => RuntimeException::class,
                'message' => 'Activity cancelled',
                'code' => 0,
            ]],
        ];
    }

    public function testLocalActivityRejectsHistoryForAQueuedActivity(): void
    {
        $run = $this->createRun(TestQueryLocalActivityWorkflow::class);
        $payload = $this->localActivityPayload();
        unset($payload['execution_mode'], $payload['local_activity']);
        $this->appendEvent($run, HistoryEventType::ActivityScheduled, $payload);

        $this->expectException(HistoryEventShapeMismatchException::class);
        $this->expectExceptionMessage('local activity');

        (new QueryStateReplayer())->query($run->fresh(), 'currentState');
    }

    public function testRecordedUpdatesApplyAtTheirOwnWaitInHistoryOrder(): void
    {
        $run = $this->createRun(TestSignalThenUpdateWorkflow::class);
        $this->appendEvent($run, HistoryEventType::SignalWaitOpened, [
            'sequence' => 10,
            'signal_name' => 'advance',
        ]);
        $this->appendEvent($run, HistoryEventType::UpdateApplied, [
            'sequence' => 10,
            'update_name' => 'approve',
            'arguments' => Serializer::serializeWithCodec($run->payload_codec, [true, 'first-wait']),
        ]);
        $this->appendEvent($run, HistoryEventType::SignalApplied, [
            'sequence' => 10,
            'signal_name' => 'advance',
            'arguments' => Serializer::serializeWithCodec($run->payload_codec, ['Ada']),
        ]);
        $this->appendEvent($run, HistoryEventType::SignalWaitOpened, [
            'sequence' => 20,
            'signal_name' => 'finish',
        ]);
        foreach ([[false, 'second-wait'], [true, 'latest']] as $arguments) {
            $this->appendEvent($run, HistoryEventType::UpdateApplied, [
                'sequence' => 20,
                'update_name' => 'approve',
                'arguments' => Serializer::serializeWithCodec($run->payload_codec, $arguments),
            ]);
        }
        $this->appendEvent($run, HistoryEventType::UpdateApplied, [
            'sequence' => 30,
            'update_name' => 'approve',
            'arguments' => Serializer::serializeWithCodec($run->payload_codec, [false, 'future-wait']),
        ]);
        $before = $this->runtimeSnapshot($run);
        $expected = [
            'stage' => 'waiting-for-finish',
            'name' => 'Ada',
            'approved' => true,
            'events' => [
                'started',
                'approved:yes:first-wait',
                'signal:Ada',
                'approved:no:second-wait',
                'approved:yes:latest',
            ],
        ];

        for ($replay = 0; $replay < 2; ++$replay) {
            $state = (new QueryStateReplayer())->replayState($run->fresh());

            $this->assertInstanceOf(SignalCall::class, $state->current);
            $this->assertSame('finish', $state->current->name);
            $this->assertSame(2, $state->sequence);
            $this->assertSame($expected, (new QueryStateReplayer())->query($run->fresh(), 'currentState'));
            $this->assertSame($before, $this->runtimeSnapshot($run));
        }
    }

    #[DataProvider('updateSources')]
    public function testAliasedUpdateRestoresArgumentsFromHistoryOrItsCommand(bool $historyArguments): void
    {
        $run = $this->createRun(TestAliasedUpdateWorkflow::class);
        $this->appendEvent($run, HistoryEventType::SignalWaitOpened, [
            'sequence' => 10,
            'signal_name' => 'name-provided',
        ]);
        $command = WorkflowCommand::query()->create([
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'command_type' => 'update',
            'target_scope' => 'run',
            'status' => 'accepted',
            'payload' => Serializer::serializeWithCodec($run->payload_codec, [
                'name' => 'mark-approved',
                'arguments' => [true, 'command'],
            ]),
        ]);
        $payload = [
            'sequence' => 10,
        ];
        if ($historyArguments) {
            $payload += [
                'update_name' => 'mark-approved',
                'arguments' => Serializer::serializeWithCodec($run->payload_codec, [false, 'history']),
            ];
        }
        $event = $this->appendEvent($run, HistoryEventType::UpdateApplied, $payload);
        $event->forceFill([
            'workflow_command_id' => $command->id,
        ])->save();
        $before = $this->runtimeSnapshot($run);

        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => ! $historyArguments,
            'events' => ['started', $historyArguments ? 'approved:no:history' : 'approved:yes:command'],
        ], (new QueryStateReplayer())->query($run->fresh(), 'currentState'));
        $this->assertSame($before, $this->runtimeSnapshot($run));
    }

    public static function updateSources(): array
    {
        return [
            'history is authoritative' => [true],
            'command fallback' => [false],
        ];
    }

    public function testRecordedUpdateWithoutAMethodFailsWithAnActionableDiagnostic(): void
    {
        $run = $this->createRun(TestAliasedUpdateWorkflow::class);
        $this->appendEvent($run, HistoryEventType::SignalWaitOpened, [
            'sequence' => 10,
            'signal_name' => 'name-provided',
        ]);
        $event = $this->appendEvent($run, HistoryEventType::UpdateApplied, [
            'sequence' => 10,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Workflow update event [{$event->id}] is missing an update method name.");

        (new QueryStateReplayer())->query($run->fresh(), 'currentState');
    }

    /**
     * @param class-string<Workflow> $workflowClass
     */
    private function createRun(string $workflowClass): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'query-outcome-' . strtolower((string) Str::ulid()),
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowClass,
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowClass,
            'status' => RunStatus::Waiting->value,
            'payload_codec' => CodecRegistry::defaultCodec(),
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), []),
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function appendEvent(WorkflowRun $run, HistoryEventType $type, array $payload): WorkflowHistoryEvent
    {
        $run->increment('last_history_sequence');

        return WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $run->last_history_sequence,
            'event_type' => $type->value,
            'payload' => $payload,
            'recorded_at' => now(),
        ]);
    }

    private function localActivityPayload(): array
    {
        return [
            'sequence' => 10,
            'activity_type' => TestQueryReplayGuardActivity::class,
            'execution_mode' => LocalActivityRuntime::EXECUTION_MODE,
            'local_activity' => true,
        ];
    }

    private function runtimeSnapshot(WorkflowRun $run): array
    {
        return [
            'run' => $run->fresh()
                ->getAttributes(),
            'history' => WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy(
                'sequence'
            )->get()
                ->toArray(),
            'commands' => WorkflowCommand::query()->where('workflow_run_id', $run->id)->orderBy('id')->get()->toArray(),
            'activities' => ActivityExecution::query()->where('workflow_run_id', $run->id)->count(),
            'tasks' => WorkflowTask::query()->where('workflow_run_id', $run->id)->count(),
            'timers' => WorkflowTimer::query()->where('workflow_run_id', $run->id)->count(),
        ];
    }
}
