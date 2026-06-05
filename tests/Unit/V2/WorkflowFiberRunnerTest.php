<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Carbon\CarbonImmutable;
use Generator;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Workflow\Exceptions\VersionNotSupportedException;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Exceptions\RestoredWorkflowException;
use Workflow\V2\Exceptions\StraightLineWorkflowRequiredException;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\ChildWorkflowOptions;
use Workflow\V2\Support\SideEffectCall;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Worker\WorkflowFiberRunner;
use Workflow\V2\Worker\WorkflowStep;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class WorkflowFiberRunnerTest extends TestCase
{
    public function testRunnerStepsThroughActivitySuspensionAndCompletion(): void
    {
        $runner = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerFixtureWorkflow::class,
            'workflow-1',
            'run-1',
            ['polyglot'],
        );

        $scheduled = $runner->step();

        $this->assertFalse($scheduled->completed);
        $this->assertInstanceOf(ActivityCall::class, $scheduled->activity);
        $this->assertInstanceOf(ActivityCall::class, $scheduled->yielded);
        $this->assertSame('demo.reverse', $scheduled->activity->activity);
        $this->assertSame(['polyglot'], $scheduled->activity->arguments);
        $this->assertSame('schedule_activity', $scheduled->command['type']);
        $this->assertSame('demo.reverse', $scheduled->command['activity_type']);
        $this->assertSame(['polyglot'], Serializer::unserializeWithCodec('avro', $scheduled->command['arguments']));

        $completed = $runner->step('tolygolp');

        $this->assertTrue($completed->completed);
        $this->assertNull($completed->activity);
        $this->assertNull($completed->yielded);
        $this->assertSame('complete_workflow', $completed->command['type']);
        $this->assertSame($completed->result, Serializer::unserializeWithCodec('avro', $completed->command['result']));
        $this->assertSame([
            'workflow_id' => 'workflow-1',
            'run_id' => 'run-1',
            'result' => 'tolygolp',
        ], $completed->result);
    }

    public function testRunnerSurfacesTimerCommand(): void
    {
        $scheduled = $this->runnerFor(WorkerProtocolRunnerTimerWorkflow::class)->step();

        $this->assertFalse($scheduled->completed);
        $this->assertSame([[
            'type' => 'start_timer',
            'delay_seconds' => 30,
        ]], $scheduled->commands);
    }

    public function testRunnerSurfacesConditionWaitCommand(): void
    {
        $scheduled = $this->runnerFor(WorkerProtocolRunnerConditionWaitWorkflow::class)->step();

        $this->assertFalse($scheduled->completed);
        $this->assertSame([[
            'type' => 'open_condition_wait',
            'condition_key' => 'done',
            'condition_definition_fingerprint' => $scheduled->command['condition_definition_fingerprint'],
        ]], $scheduled->commands);
        $this->assertIsString($scheduled->command['condition_definition_fingerprint']);
    }

    public function testRunnerSurfacesSignalWaitCommand(): void
    {
        $scheduled = $this->runnerFor(WorkerProtocolRunnerSignalWaitWorkflow::class)->step();

        $this->assertFalse($scheduled->completed);
        $this->assertSame([[
            'type' => 'open_signal_wait',
            'signal_name' => 'increment',
        ]], $scheduled->commands);
    }

    public function testRunnerWaitsWhenSignalWaitHistoryIsOpen(): void
    {
        $waiting = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerSignalWaitWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'recorded_at' => '2026-05-12T10:11:12+00:00',
            ], [
                'sequence' => 2,
                'event_type' => 'SignalWaitOpened',
                'payload' => [
                    'sequence' => 1,
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-1',
                ],
                'recorded_at' => '2026-05-12T10:12:13+00:00',
            ]],
        )->step();

        $this->assertFalse($waiting->completed);
        $this->assertNull($waiting->command);
        $this->assertSame([], $waiting->commands);
    }

    public function testRunnerReplaysSignalReceivedAndEmitsNextSignalWait(): void
    {
        WorkerProtocolRunnerCounterSignalWorkflow::reset();

        $scheduled = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerCounterSignalWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'recorded_at' => '2026-05-12T10:11:12+00:00',
            ], [
                'sequence' => 2,
                'event_type' => 'SignalWaitOpened',
                'payload' => [
                    'sequence' => 1,
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-1',
                ],
                'recorded_at' => '2026-05-12T10:12:13+00:00',
            ], [
                'sequence' => 3,
                'event_type' => 'SignalReceived',
                'payload' => [
                    'signal_name' => 'increment',
                    'signal_wait_id' => 'wait-1',
                    'arguments' => [
                        'codec' => 'avro',
                        'blob' => Serializer::serializeWithCodec('avro', [5]),
                    ],
                ],
                'recorded_at' => '2026-05-12T10:13:14+00:00',
            ]],
        )->step();

        $this->assertFalse($scheduled->completed);
        $this->assertSame([[
            'type' => 'open_signal_wait',
            'signal_name' => 'increment',
        ]], $scheduled->commands);
        $this->assertSame(5, WorkerProtocolRunnerCounterSignalWorkflow::lastCount());
    }

    public function testRunnerSurfacesChildWorkflowCommand(): void
    {
        $scheduled = $this->runnerFor(WorkerProtocolRunnerChildWorkflow::class)->step();

        $this->assertSame('start_child_workflow', $scheduled->command['type']);
        $this->assertSame('demo.child', $scheduled->command['workflow_type']);
        $this->assertSame('request_cancel', $scheduled->command['parent_close_policy']);
        $this->assertSame('children', $scheduled->command['queue']);
        $this->assertSame(['payload'], Serializer::unserializeWithCodec('avro', $scheduled->command['arguments']));
    }

    public function testRunnerSeedsWorkflowTimeFromStartedHistoryEvent(): void
    {
        $startedAt = '2026-05-12T10:11:12+00:00';
        $completed = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerNowWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'recorded_at' => $startedAt,
            ]],
        )->step();

        $this->assertTrue($completed->completed);
        $this->assertSame(CarbonImmutable::parse($startedAt)->getTimestampMs(), $completed->result);
    }

    public function testRunnerReplaysCompletedActivityBeforeEmittingNextCommand(): void
    {
        $completedAt = '2026-05-12T10:12:13+00:00';
        $scheduled = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerActivityThenActivityWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'recorded_at' => '2026-05-12T10:11:12+00:00',
            ], [
                'sequence' => 2,
                'event_type' => 'ActivityCompleted',
                'payload' => [
                    'sequence' => 1,
                    'result' => Serializer::serializeWithCodec('avro', 'tolygolp'),
                    'payload_codec' => 'avro',
                ],
                'recorded_at' => $completedAt,
            ]],
        )->step();

        $this->assertFalse($scheduled->completed);
        $this->assertSame('schedule_activity', $scheduled->command['type']);
        $this->assertSame('demo.consume-activity', $scheduled->command['activity_type']);
        $this->assertSame([
            'tolygolp',
            CarbonImmutable::parse($completedAt)->getTimestampMs(),
        ], Serializer::unserializeWithCodec('avro', $scheduled->command['arguments']));
    }

    public function testRunnerDoesNotReplayEarlierNonContiguousActivityOutcomeForNewPosition(): void
    {
        $scheduled = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerFourActivityWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'recorded_at' => '2026-05-12T10:11:12+00:00',
            ], [
                'sequence' => 4,
                'event_type' => 'ActivityCompleted',
                'payload' => [
                    'sequence' => 3,
                    'result' => Serializer::serializeWithCodec('avro', 'first-result'),
                    'payload_codec' => 'avro',
                ],
                'recorded_at' => '2026-05-12T10:12:13+00:00',
            ], [
                'sequence' => 7,
                'event_type' => 'ActivityCompleted',
                'payload' => [
                    'sequence' => 6,
                    'result' => Serializer::serializeWithCodec('avro', 'second-result'),
                    'payload_codec' => 'avro',
                ],
                'recorded_at' => '2026-05-12T10:13:14+00:00',
            ]],
        )->step();

        $this->assertFalse($scheduled->completed);
        $this->assertSame('schedule_activity', $scheduled->command['type']);
        $this->assertSame('demo.third', $scheduled->command['activity_type']);
        $this->assertSame('sagas-php', $scheduled->command['queue']);
    }

    public function testRunnerWaitsWhenActivityHistoryIsOpen(): void
    {
        $waiting = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerFixtureWorkflow::class,
            'workflow-1',
            'run-1',
            ['polyglot'],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'recorded_at' => '2026-05-12T10:11:12+00:00',
            ], [
                'sequence' => 2,
                'event_type' => 'ActivityScheduled',
                'payload' => [
                    'sequence' => 1,
                ],
                'recorded_at' => '2026-05-12T10:12:13+00:00',
            ]],
        )->step();

        $this->assertFalse($waiting->completed);
        $this->assertSame([], $waiting->commands);
        $this->assertNull($waiting->command);
        $this->assertInstanceOf(ActivityCall::class, $waiting->activity);
    }

    public function testRunnerCanReplayWaitingStepAfterHistoryUpdate(): void
    {
        $runner = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerFixtureWorkflow::class,
            'workflow-1',
            'run-1',
            ['polyglot'],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'recorded_at' => '2026-05-12T10:11:12+00:00',
            ], [
                'sequence' => 2,
                'event_type' => 'ActivityScheduled',
                'payload' => [
                    'sequence' => 1,
                ],
                'recorded_at' => '2026-05-12T10:12:13+00:00',
            ]],
        );

        $this->assertSame([], $runner->step()->commands);

        $runner->withHistoryEvents([[
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [],
            'recorded_at' => '2026-05-12T10:11:12+00:00',
        ], [
            'sequence' => 2,
            'event_type' => 'ActivityScheduled',
            'payload' => [
                'sequence' => 1,
            ],
            'recorded_at' => '2026-05-12T10:12:13+00:00',
        ], [
            'sequence' => 3,
            'event_type' => 'ActivityCompleted',
            'payload' => [
                'sequence' => 1,
                'result' => Serializer::serializeWithCodec('avro', 'tolygolp'),
                'payload_codec' => 'avro',
            ],
            'recorded_at' => '2026-05-12T10:13:14+00:00',
        ]]);

        $completed = $runner->step();

        $this->assertTrue($completed->completed);
        $this->assertSame('complete_workflow', $completed->command['type']);
        $this->assertSame('tolygolp', $completed->result['result']);
    }

    public function testRunnerReplaysPendingYieldedAfterHistoryRefreshWithoutExplicitResume(): void
    {
        $runner = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerFixtureWorkflow::class,
            'workflow-1',
            'run-1',
            ['polyglot'],
        );

        $this->assertSame('schedule_activity', $runner->step()->command['type']);

        $runner->withHistoryEvents([[
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [],
            'recorded_at' => '2026-05-12T10:11:12+00:00',
        ], [
            'sequence' => 2,
            'event_type' => 'ActivityCompleted',
            'payload' => [
                'sequence' => 1,
                'result' => Serializer::serializeWithCodec('avro', 'tolygolp'),
                'payload_codec' => 'avro',
            ],
            'recorded_at' => '2026-05-12T10:13:14+00:00',
        ]]);

        $completed = $runner->step();

        $this->assertTrue($completed->completed);
        $this->assertSame('tolygolp', $completed->result['result']);
    }

    public function testRunnerDoesNotAdvancePendingActivityWithoutResumeOrHistory(): void
    {
        $runner = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerFixtureWorkflow::class,
            'workflow-1',
            'run-1',
            ['polyglot'],
        );

        $this->assertSame('schedule_activity', $runner->step()->command['type']);

        $waiting = $runner->step();

        $this->assertFalse($waiting->completed);
        $this->assertSame([], $waiting->commands);
        $this->assertNull($waiting->command);
        $this->assertInstanceOf(ActivityCall::class, $waiting->yielded);

        $completed = $runner->step('tolygolp');

        $this->assertTrue($completed->completed);
        $this->assertSame('tolygolp', $completed->result['result']);
    }

    public function testRunnerDoesNotAdvancePendingTimerWithoutResumeOrHistory(): void
    {
        $runner = $this->runnerFor(WorkerProtocolRunnerTimerWorkflow::class);

        $this->assertSame('start_timer', $runner->step()->command['type']);

        $waiting = $runner->step();

        $this->assertFalse($waiting->completed);
        $this->assertSame([], $waiting->commands);
        $this->assertNull($waiting->command);

        $completed = $runner->step(true);

        $this->assertTrue($completed->completed);
        $this->assertTrue($completed->result);
    }

    public function testRunnerDoesNotAdvancePendingChildWorkflowWithoutResumeOrHistory(): void
    {
        $runner = $this->runnerFor(WorkerProtocolRunnerChildWorkflow::class);

        $this->assertSame('start_child_workflow', $runner->step()->command['type']);

        $waiting = $runner->step();

        $this->assertFalse($waiting->completed);
        $this->assertSame([], $waiting->commands);
        $this->assertNull($waiting->command);

        $completed = $runner->step('child-result');

        $this->assertTrue($completed->completed);
        $this->assertSame('child-result', $completed->result);
    }

    public function testRunnerDoesNotAdvancePendingContinueAsNewWithoutResumeOrHistory(): void
    {
        $runner = $this->runnerFor(WorkerProtocolRunnerContinueAsNewWorkflow::class);

        $this->assertSame('continue_as_new', $runner->step()->command['type']);

        $waiting = $runner->step();

        $this->assertFalse($waiting->completed);
        $this->assertSame([], $waiting->commands);
        $this->assertNull($waiting->command);
    }

    public function testRunnerReplaysFiredTimerBeforeEmittingNextCommand(): void
    {
        $firedAt = '2026-05-12T10:15:00+00:00';
        $scheduled = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerTimerThenActivityWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'recorded_at' => '2026-05-12T10:11:12+00:00',
            ], [
                'sequence' => 2,
                'event_type' => 'TimerFired',
                'payload' => [
                    'sequence' => 1,
                    'fired_at' => $firedAt,
                ],
                'recorded_at' => $firedAt,
            ]],
        )->step();

        $this->assertSame('schedule_activity', $scheduled->command['type']);
        $this->assertSame('demo.after-timer', $scheduled->command['activity_type']);
        $this->assertSame([
            true,
            CarbonImmutable::parse($firedAt)->getTimestampMs(),
        ], Serializer::unserializeWithCodec('avro', $scheduled->command['arguments']));
    }

    public function testRunnerReplaysCompletedChildBeforeEmittingNextCommand(): void
    {
        $closedAt = '2026-05-12T10:16:00+00:00';
        $scheduled = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerChildThenActivityWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'recorded_at' => '2026-05-12T10:11:12+00:00',
            ], [
                'sequence' => 2,
                'event_type' => 'ChildRunCompleted',
                'payload' => [
                    'sequence' => 1,
                    'output' => Serializer::serializeWithCodec('avro', 'child-result'),
                    'closed_at' => $closedAt,
                ],
                'recorded_at' => $closedAt,
            ]],
        )->step();

        $this->assertSame('schedule_activity', $scheduled->command['type']);
        $this->assertSame('demo.after-child', $scheduled->command['activity_type']);
        $this->assertSame([
            'child-result',
            CarbonImmutable::parse($closedAt)->getTimestampMs(),
        ], Serializer::unserializeWithCodec('avro', $scheduled->command['arguments']));
    }

    public function testRunnerThrowsRecordedActivityFailureIntoWorkflow(): void
    {
        $failedAt = '2026-05-12T10:17:00+00:00';
        $scheduled = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerHandledActivityFailureWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'recorded_at' => '2026-05-12T10:11:12+00:00',
            ], [
                'sequence' => 2,
                'event_type' => 'ActivityFailed',
                'payload' => [
                    'sequence' => 1,
                    'exception_class' => RuntimeException::class,
                    'message' => 'activity exploded',
                    'code' => 0,
                ],
                'recorded_at' => $failedAt,
            ], [
                'sequence' => 3,
                'event_type' => 'FailureHandled',
                'payload' => [
                    'sequence' => 1,
                    'exception_class' => RuntimeException::class,
                    'message' => 'activity exploded',
                    'handled' => true,
                ],
                'recorded_at' => $failedAt,
            ]],
        )->step();

        $this->assertSame('schedule_activity', $scheduled->command['type']);
        $this->assertSame('demo.after-failure', $scheduled->command['activity_type']);
        $this->assertSame([
            'activity exploded',
            CarbonImmutable::parse($failedAt)->getTimestampMs(),
        ], Serializer::unserializeWithCodec('avro', $scheduled->command['arguments']));
    }

    public function testRunnerPropagatesUnmappedTypedSequentialCompensationFailureFromHistory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'compensation failed for cancel_flight: cancel_flight typed compensation failure',
        );

        try {
            WorkflowFiberRunner::forClass(
                WorkerProtocolRunnerSequentialCompensationFailureWorkflow::class,
                'workflow-1',
                'run-1',
                [],
                'avro',
                [[
                    'sequence' => 1,
                    'event_type' => 'WorkflowStarted',
                    'payload' => [],
                    'recorded_at' => '2026-05-12T10:11:12+00:00',
                ], [
                    'sequence' => 2,
                    'event_type' => 'ActivityCompleted',
                    'payload' => [
                        'sequence' => 1,
                        'activity_type' => 'reserve_flight',
                        'result' => Serializer::serializeWithCodec('avro', 'flight-1'),
                        'payload_codec' => 'avro',
                    ],
                    'recorded_at' => '2026-05-12T10:12:00+00:00',
                ], [
                    'sequence' => 3,
                    'event_type' => 'ActivityCompleted',
                    'payload' => [
                        'sequence' => 2,
                        'activity_type' => 'reserve_hotel',
                        'result' => Serializer::serializeWithCodec('avro', 'hotel-1'),
                        'payload_codec' => 'avro',
                    ],
                    'recorded_at' => '2026-05-12T10:12:05+00:00',
                ], [
                    'sequence' => 4,
                    'event_type' => 'ActivityCompleted',
                    'payload' => [
                        'sequence' => 3,
                        'activity_type' => 'charge_card',
                        'result' => Serializer::serializeWithCodec('avro', 'charge-1'),
                        'payload_codec' => 'avro',
                    ],
                    'recorded_at' => '2026-05-12T10:12:10+00:00',
                ], [
                    'sequence' => 5,
                    'event_type' => 'ActivityFailed',
                    'payload' => [
                        'sequence' => 4,
                        'activity_type' => 'saga_planned_failure',
                        'exception_class' => RuntimeException::class,
                        'exception_type' => 'PlannedSagaFailure',
                        'message' => 'charge_card planned saga failure',
                        'code' => 0,
                        'exception' => [
                            'class' => RuntimeException::class,
                            'type' => 'PlannedSagaFailure',
                            'message' => 'charge_card planned saga failure',
                            'code' => 0,
                        ],
                    ],
                    'recorded_at' => '2026-05-12T10:12:15+00:00',
                ], [
                    'sequence' => 6,
                    'event_type' => 'ActivityCompleted',
                    'payload' => [
                        'sequence' => 5,
                        'activity_type' => 'refund_card',
                        'result' => Serializer::serializeWithCodec('avro', ['activity' => 'refund_card']),
                        'payload_codec' => 'avro',
                    ],
                    'recorded_at' => '2026-05-12T10:12:20+00:00',
                ], [
                    'sequence' => 7,
                    'event_type' => 'ActivityCompleted',
                    'payload' => [
                        'sequence' => 6,
                        'activity_type' => 'cancel_hotel',
                        'result' => Serializer::serializeWithCodec('avro', ['activity' => 'cancel_hotel']),
                        'payload_codec' => 'avro',
                    ],
                    'recorded_at' => '2026-05-12T10:12:25+00:00',
                ], [
                    'sequence' => 8,
                    'event_type' => 'ActivityFailed',
                    'payload' => [
                        'sequence' => 7,
                        'activity_type' => 'cancel_flight',
                        'exception_class' => 'TypedCancelFlightError',
                        'exception_type' => 'TypedCancelFlightError',
                        'message' => 'cancel_flight typed compensation failure',
                        'code' => 0,
                        'exception' => [
                            'class' => 'TypedCancelFlightError',
                            'type' => 'TypedCancelFlightError',
                            'message' => 'cancel_flight typed compensation failure',
                            'code' => 0,
                        ],
                    ],
                    'recorded_at' => '2026-05-12T10:12:30+00:00',
                ]],
            )->step();
        } catch (RuntimeException $exception) {
            $previous = $exception->getPrevious();

            $this->assertInstanceOf(RestoredWorkflowException::class, $previous);
            $this->assertSame('cancel_flight typed compensation failure', $previous->getMessage());
            $this->assertSame('TypedCancelFlightError', $previous->failurePayload()['type'] ?? null);

            throw $exception;
        }
    }

    public function testRunnerThrowsRecordedChildFailureIntoWorkflow(): void
    {
        $failedAt = '2026-05-12T10:18:00+00:00';
        $scheduled = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerHandledChildFailureWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'recorded_at' => '2026-05-12T10:11:12+00:00',
            ], [
                'sequence' => 2,
                'event_type' => 'ChildRunFailed',
                'payload' => [
                    'sequence' => 1,
                    'exception_class' => RuntimeException::class,
                    'message' => 'child exploded',
                    'code' => 0,
                    'closed_at' => $failedAt,
                ],
                'recorded_at' => $failedAt,
            ], [
                'sequence' => 3,
                'event_type' => 'FailureHandled',
                'payload' => [
                    'sequence' => 1,
                    'exception_class' => RuntimeException::class,
                    'message' => 'child exploded',
                    'handled' => true,
                ],
                'recorded_at' => $failedAt,
            ]],
        )->step();

        $this->assertSame('schedule_activity', $scheduled->command['type']);
        $this->assertSame('demo.after-child-failure', $scheduled->command['activity_type']);
        $this->assertSame([
            'child exploded',
            CarbonImmutable::parse($failedAt)->getTimestampMs(),
        ], Serializer::unserializeWithCodec('avro', $scheduled->command['arguments']));
    }

    public function testRunnerAggregatesSideEffectBeforeCompletionCommand(): void
    {
        WorkerProtocolRunnerSideEffectWorkflow::reset();

        $scheduled = $this->runnerFor(WorkerProtocolRunnerSideEffectWorkflow::class)->step();

        $this->assertTrue($scheduled->completed);
        $this->assertSame('complete_workflow', $scheduled->command['type']);
        $this->assertSame('record_side_effect', $scheduled->commands[0]['type']);
        $this->assertSame(
            ['seed' => 123, 'source' => 'php-worker'],
            Serializer::unserializeWithCodec('avro', $scheduled->commands[0]['result']),
        );
        $this->assertSame(
            ['seed' => 123, 'source' => 'php-worker'],
            Serializer::unserializeWithCodec('avro', $scheduled->commands[1]['result']),
        );
        $this->assertSame(1, WorkerProtocolRunnerSideEffectWorkflow::sideEffectExecutions());
    }

    public function testRunnerAggregatesSideEffectBeforeActivityCommand(): void
    {
        WorkerProtocolRunnerSideEffectThenActivityWorkflow::reset();

        $scheduled = $this->runnerFor(WorkerProtocolRunnerSideEffectThenActivityWorkflow::class)->step();

        $this->assertFalse($scheduled->completed);
        $this->assertSame('schedule_activity', $scheduled->command['type']);
        $this->assertSame('record_side_effect', $scheduled->commands[0]['type']);
        $this->assertSame('schedule_activity', $scheduled->commands[1]['type']);
        $this->assertSame(
            ['seed' => 789, 'source' => 'callback'],
            Serializer::unserializeWithCodec('avro', $scheduled->commands[0]['result']),
        );
        $this->assertSame(
            [['seed' => 789, 'source' => 'callback']],
            Serializer::unserializeWithCodec('avro', $scheduled->commands[1]['arguments']),
        );
        $this->assertSame(1, WorkerProtocolRunnerSideEffectThenActivityWorkflow::sideEffectExecutions());
    }

    public function testRunnerReplaysRecordedSideEffectBeforeEmittingNextCommand(): void
    {
        WorkerProtocolRunnerSideEffectThenActivityWorkflow::reset();

        $recordedValue = ['seed' => 456, 'source' => 'history'];
        $scheduled = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerSideEffectThenActivityWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'SideEffectRecorded',
                'payload' => [
                    'sequence' => 1,
                    'result' => Serializer::serializeWithCodec('avro', $recordedValue),
                ],
                'recorded_at' => '2026-05-12T00:00:00+00:00',
            ]],
        )->step();

        $this->assertSame(0, WorkerProtocolRunnerSideEffectThenActivityWorkflow::sideEffectExecutions());
        $this->assertSame('schedule_activity', $scheduled->command['type']);
        $this->assertSame('demo.consume-side-effect', $scheduled->command['activity_type']);
        $this->assertSame([$recordedValue], Serializer::unserializeWithCodec('avro', $scheduled->command['arguments']));
    }

    public function testWorkflowStepDoesNotExecuteRawSideEffectCallbacks(): void
    {
        $executed = false;

        try {
            WorkflowStep::yielded(new SideEffectCall(static function () use (&$executed): string {
                $executed = true;

                return 'unsafe';
            }));
            $this->fail('Expected raw side effect conversion to require runner history resolution.');
        } catch (UnsupportedWorkflowYieldException) {
            $this->assertFalse($executed);
        }
    }

    public function testRunnerAggregatesVersionMarkerBeforeCompletionCommand(): void
    {
        $scheduled = $this->runnerFor(WorkerProtocolRunnerVersionWorkflow::class)->step();

        $this->assertTrue($scheduled->completed);
        $this->assertSame('complete_workflow', $scheduled->command['type']);
        $this->assertSame([
            'type' => 'record_version_marker',
            'change_id' => 'php-worker-version',
            'version' => 3,
            'min_supported' => 1,
            'max_supported' => 3,
        ], $scheduled->commands[0]);
        $this->assertSame(3, Serializer::unserializeWithCodec('avro', $scheduled->commands[1]['result']));
    }

    public function testRunnerAggregatesVersionMarkerBeforeActivityCommand(): void
    {
        $scheduled = $this->runnerFor(WorkerProtocolRunnerVersionThenActivityWorkflow::class)->step();

        $this->assertFalse($scheduled->completed);
        $this->assertSame('schedule_activity', $scheduled->command['type']);
        $this->assertSame([
            'type' => 'record_version_marker',
            'change_id' => 'php-worker-version',
            'version' => 5,
            'min_supported' => 1,
            'max_supported' => 5,
        ], $scheduled->commands[0]);
        $this->assertSame('schedule_activity', $scheduled->commands[1]['type']);
        $this->assertSame([5], Serializer::unserializeWithCodec('avro', $scheduled->commands[1]['arguments']));
    }

    public function testRunnerReplaysRecordedVersionMarkerBeforeEmittingNextCommand(): void
    {
        $scheduled = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerVersionThenActivityWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'VersionMarkerRecorded',
                'payload' => [
                    'sequence' => 1,
                    'change_id' => 'php-worker-version',
                    'version' => 2,
                    'min_supported' => 1,
                    'max_supported' => 3,
                ],
                'recorded_at' => '2026-05-12T00:00:00+00:00',
            ]],
        )->step();

        $this->assertSame('schedule_activity', $scheduled->command['type']);
        $this->assertSame('demo.use-version', $scheduled->command['activity_type']);
        $this->assertSame([2], Serializer::unserializeWithCodec('avro', $scheduled->command['arguments']));
    }

    public function testRunnerUsesLegacyDefaultWithoutRecordingMarkerForPreFingerprintHistory(): void
    {
        $completed = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerLegacyVersionThenActivityWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [
                    'workflow_class' => WorkerProtocolRunnerLegacyVersionThenActivityWorkflow::class,
                    'workflow_type' => WorkerProtocolRunnerLegacyVersionThenActivityWorkflow::class,
                ],
                'recorded_at' => '2026-05-12T00:00:00+00:00',
            ], [
                'sequence' => 2,
                'event_type' => 'ActivityCompleted',
                'payload' => [
                    'sequence' => 1,
                    'result' => Serializer::serializeWithCodec('avro', 'legacy-result'),
                    'payload_codec' => 'avro',
                ],
                'recorded_at' => '2026-05-12T00:00:01+00:00',
            ]],
        )->step();

        $this->assertTrue($completed->completed);
        $this->assertSame(['complete_workflow'], array_column($completed->commands, 'type'));
        $this->assertSame('legacy-result', $completed->result);
    }

    public function testRunnerUsesLegacyDefaultWithoutRecordingMarkerForFingerprintMismatch(): void
    {
        $completed = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerLegacyVersionThenActivityWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [
                    'workflow_class' => WorkerProtocolRunnerLegacyVersionThenActivityWorkflow::class,
                    'workflow_type' => WorkerProtocolRunnerLegacyVersionThenActivityWorkflow::class,
                    'workflow_definition_fingerprint' => 'sha256:'.str_repeat('0', 64),
                ],
                'recorded_at' => '2026-05-12T00:00:00+00:00',
            ], [
                'sequence' => 2,
                'event_type' => 'ActivityCompleted',
                'payload' => [
                    'sequence' => 1,
                    'result' => Serializer::serializeWithCodec('avro', 'legacy-result'),
                    'payload_codec' => 'avro',
                ],
                'recorded_at' => '2026-05-12T00:00:01+00:00',
            ]],
        )->step();

        $this->assertTrue($completed->completed);
        $this->assertSame(['complete_workflow'], array_column($completed->commands, 'type'));
        $this->assertSame('legacy-result', $completed->result);
    }

    public function testRunnerRecordsVersionMarkerWhenHistoryFingerprintMatchesCurrentDefinition(): void
    {
        $scheduled = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerVersionThenActivityWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [
                    'workflow_class' => WorkerProtocolRunnerVersionThenActivityWorkflow::class,
                    'workflow_type' => WorkerProtocolRunnerVersionThenActivityWorkflow::class,
                    'workflow_definition_fingerprint' => WorkflowDefinition::fingerprint(
                        WorkerProtocolRunnerVersionThenActivityWorkflow::class,
                    ),
                ],
                'recorded_at' => '2026-05-12T00:00:00+00:00',
            ]],
        )->step();

        $this->assertFalse($scheduled->completed);
        $this->assertSame(['record_version_marker', 'schedule_activity'], array_column($scheduled->commands, 'type'));
        $this->assertSame(5, $scheduled->commands[0]['version']);
        $this->assertSame([5], Serializer::unserializeWithCodec('avro', $scheduled->commands[1]['arguments']));
    }

    public function testRunnerRejectsRecordedVersionMarkerForDifferentChangeId(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Workflow version marker at workflow sequence [1] expected change ID [php-worker-version]',
        );

        WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerVersionWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'VersionMarkerRecorded',
                'payload' => [
                    'sequence' => 1,
                    'change_id' => 'other-change',
                    'version' => 2,
                    'min_supported' => 1,
                    'max_supported' => 3,
                ],
            ]],
        )->step();
    }

    public function testRunnerRejectsRecordedVersionMarkerOutsideSupportedRange(): void
    {
        $this->expectException(VersionNotSupportedException::class);
        $this->expectExceptionMessage("Version 4 for change ID 'php-worker-version' is not supported.");

        WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerVersionWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'VersionMarkerRecorded',
                'payload' => [
                    'sequence' => 1,
                    'change_id' => 'php-worker-version',
                    'version' => 4,
                    'min_supported' => 1,
                    'max_supported' => 4,
                ],
            ]],
        )->step();
    }

    public function testRunnerAggregatesSearchAttributeCommandBeforeCompletionCommand(): void
    {
        $scheduled = $this->runnerFor(WorkerProtocolRunnerSearchAttributesWorkflow::class)->step();

        $this->assertTrue($scheduled->completed);
        $this->assertSame('complete_workflow', $scheduled->command['type']);
        $this->assertSame([
            'type' => 'upsert_search_attributes',
            'attributes' => [
                'obsolete' => null,
                'phase' => 'worker-protocol',
                'priority' => 3,
            ],
        ], $scheduled->commands[0]);
        $this->assertNull(Serializer::unserializeWithCodec('avro', $scheduled->commands[1]['result']));
    }

    public function testRunnerAggregatesSearchAttributeCommandBeforeActivityCommand(): void
    {
        $scheduled = $this->runnerFor(WorkerProtocolRunnerSearchAttributesThenActivityWorkflow::class)->step();

        $this->assertFalse($scheduled->completed);
        $this->assertSame('schedule_activity', $scheduled->command['type']);
        $this->assertSame([
            'type' => 'upsert_search_attributes',
            'attributes' => [
                'phase' => 'before-activity',
            ],
        ], $scheduled->commands[0]);
        $this->assertSame('schedule_activity', $scheduled->commands[1]['type']);
        $this->assertSame(['ready'], Serializer::unserializeWithCodec('avro', $scheduled->commands[1]['arguments']));
    }

    public function testRunnerReplaysRecordedSearchAttributeUpsertBeforeEmittingNextCommand(): void
    {
        $scheduled = WorkflowFiberRunner::forClass(
            WorkerProtocolRunnerSearchAttributesThenActivityWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => 'SearchAttributesUpserted',
                'payload' => [
                    'sequence' => 1,
                    'attributes' => [
                        'phase' => 'before-activity',
                    ],
                    'merged' => [
                        'phase' => 'before-activity',
                    ],
                ],
                'recorded_at' => '2026-05-12T00:00:00+00:00',
            ]],
        )->step();

        $this->assertFalse($scheduled->completed);
        $this->assertSame([[
            'type' => 'schedule_activity',
            'activity_type' => 'demo.after-search-attributes',
            'arguments' => $scheduled->commands[0]['arguments'],
            'payload_codec' => 'avro',
        ]], $scheduled->commands);
        $this->assertSame(['ready'], Serializer::unserializeWithCodec('avro', $scheduled->commands[0]['arguments']));
    }

    public function testRunnerSurfacesContinueAsNewCommand(): void
    {
        $scheduled = $this->runnerFor(WorkerProtocolRunnerContinueAsNewWorkflow::class)->step();

        $this->assertSame('continue_as_new', $scheduled->command['type']);
        $this->assertSame(['next', 2], Serializer::unserializeWithCodec('avro', $scheduled->command['arguments']));
    }

    public function testRunnerRejectsGeneratorStyleAuthoring(): void
    {
        $this->expectException(StraightLineWorkflowRequiredException::class);

        $this->runnerFor(WorkerProtocolRunnerGeneratorWorkflow::class)->step();
    }

    /**
     * @param class-string<Workflow> $workflowClass
     */
    private function runnerFor(string $workflowClass): WorkflowFiberRunner
    {
        return WorkflowFiberRunner::forClass($workflowClass, 'workflow-1', 'run-1', []);
    }
}

final class WorkerProtocolRunnerFixtureWorkflow extends Workflow
{
    public function handle(string $input): array
    {
        $result = Workflow::activity('demo.reverse', $input);

        return [
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
            'result' => $result,
        ];
    }
}

final class WorkerProtocolRunnerTimerWorkflow extends Workflow
{
    public function handle(): mixed
    {
        return Workflow::timer(30);
    }
}

final class WorkerProtocolRunnerConditionWaitWorkflow extends Workflow
{
    public function handle(): mixed
    {
        return Workflow::await(static fn (): bool => false, null, 'done');
    }
}

final class WorkerProtocolRunnerSignalWaitWorkflow extends Workflow
{
    public function handle(): mixed
    {
        return Workflow::awaitSignal('increment');
    }
}

final class WorkerProtocolRunnerCounterSignalWorkflow extends Workflow
{
    private static int $lastCount = 0;

    private int $count = 0;

    public static function reset(): void
    {
        self::$lastCount = 0;
    }

    public static function lastCount(): int
    {
        return self::$lastCount;
    }

    public function handle(): mixed
    {
        while (true) {
            $this->count += (int) Workflow::awaitSignal('increment');
            self::$lastCount = $this->count;
        }
    }
}

final class WorkerProtocolRunnerChildWorkflow extends Workflow
{
    public function handle(): mixed
    {
        return Workflow::child(
            'demo.child',
            new ChildWorkflowOptions(parentClosePolicy: ParentClosePolicy::RequestCancel, queue: 'children'),
            'payload',
        );
    }
}

final class WorkerProtocolRunnerNowWorkflow extends Workflow
{
    public function handle(): int
    {
        return Workflow::now()->getTimestampMs();
    }
}

final class WorkerProtocolRunnerActivityThenActivityWorkflow extends Workflow
{
    public function handle(): mixed
    {
        $result = Workflow::activity('demo.reverse', 'polyglot');

        return Workflow::activity('demo.consume-activity', $result, Workflow::now()->getTimestampMs());
    }
}

final class WorkerProtocolRunnerFourActivityWorkflow extends Workflow
{
    public function handle(): mixed
    {
        Workflow::activity('demo.first', new ActivityOptions(queue: 'sagas-php'));
        Workflow::activity('demo.second', new ActivityOptions(queue: 'sagas-php'));
        Workflow::activity('demo.third', new ActivityOptions(queue: 'sagas-php'));

        return Workflow::activity('demo.fourth', new ActivityOptions(queue: 'sagas-php'));
    }
}

final class WorkerProtocolRunnerTimerThenActivityWorkflow extends Workflow
{
    public function handle(): mixed
    {
        $fired = Workflow::timer(30);

        return Workflow::activity('demo.after-timer', $fired, Workflow::now()->getTimestampMs());
    }
}

final class WorkerProtocolRunnerChildThenActivityWorkflow extends Workflow
{
    public function handle(): mixed
    {
        $result = Workflow::child('demo.child');

        return Workflow::activity('demo.after-child', $result, Workflow::now()->getTimestampMs());
    }
}

final class WorkerProtocolRunnerHandledActivityFailureWorkflow extends Workflow
{
    public function handle(): mixed
    {
        try {
            Workflow::activity('demo.fail');
        } catch (RuntimeException $exception) {
            return Workflow::activity(
                'demo.after-failure',
                $exception->getMessage(),
                Workflow::now()->getTimestampMs(),
            );
        }

        return Workflow::activity(
            'demo.after-failure',
            'not caught',
            Workflow::now()->getTimestampMs(),
        );
    }
}

final class WorkerProtocolRunnerSequentialCompensationFailureWorkflow extends Workflow
{
    public function handle(): mixed
    {
        try {
            Workflow::activity('reserve_flight');
            $this->addCompensation(static fn () => Workflow::activity('cancel_flight'));

            Workflow::activity('reserve_hotel');
            $this->addCompensation(static fn () => Workflow::activity('cancel_hotel'));

            Workflow::activity('charge_card');
            $this->addCompensation(static fn () => Workflow::activity('refund_card'));

            Workflow::activity('saga_planned_failure');

            return ['status' => 'completed'];
        } catch (Throwable) {
            try {
                $this->compensate();
            } catch (Throwable $compensationFailure) {
                throw new RuntimeException(
                    'compensation failed for '.self::failedCompensationStep($compensationFailure->getMessage())
                        .': '.$compensationFailure->getMessage(),
                    previous: $compensationFailure,
                );
            }

            return ['status' => 'compensated'];
        }
    }

    private static function failedCompensationStep(string $message): string
    {
        foreach (['cancel_flight', 'cancel_hotel', 'refund_card'] as $step) {
            if (str_contains($message, $step)) {
                return $step;
            }
        }

        return 'unknown';
    }
}

final class WorkerProtocolRunnerHandledChildFailureWorkflow extends Workflow
{
    public function handle(): mixed
    {
        try {
            Workflow::child('demo.child');
        } catch (RuntimeException $exception) {
            return Workflow::activity(
                'demo.after-child-failure',
                $exception->getMessage(),
                Workflow::now()->getTimestampMs(),
            );
        }

        return Workflow::activity(
            'demo.after-child-failure',
            'not caught',
            Workflow::now()->getTimestampMs(),
        );
    }
}

final class WorkerProtocolRunnerSideEffectWorkflow extends Workflow
{
    private static int $sideEffectExecutions = 0;

    public static function reset(): void
    {
        self::$sideEffectExecutions = 0;
    }

    public static function sideEffectExecutions(): int
    {
        return self::$sideEffectExecutions;
    }

    public function handle(): mixed
    {
        return Workflow::sideEffect(static function (): array {
            self::$sideEffectExecutions++;

            return [
                'seed' => 123,
                'source' => 'php-worker',
            ];
        });
    }
}

final class WorkerProtocolRunnerSideEffectThenActivityWorkflow extends Workflow
{
    private static int $sideEffectExecutions = 0;

    public static function reset(): void
    {
        self::$sideEffectExecutions = 0;
    }

    public static function sideEffectExecutions(): int
    {
        return self::$sideEffectExecutions;
    }

    public function handle(): mixed
    {
        $value = Workflow::sideEffect(static function (): array {
            self::$sideEffectExecutions++;

            return [
                'seed' => 789,
                'source' => 'callback',
            ];
        });

        return Workflow::activity('demo.consume-side-effect', $value);
    }
}

final class WorkerProtocolRunnerVersionWorkflow extends Workflow
{
    public function handle(): mixed
    {
        return Workflow::getVersion('php-worker-version', 1, 3);
    }
}

final class WorkerProtocolRunnerVersionThenActivityWorkflow extends Workflow
{
    public function handle(): mixed
    {
        $version = Workflow::getVersion('php-worker-version', 1, 5);

        return Workflow::activity('demo.use-version', $version);
    }
}

final class WorkerProtocolRunnerLegacyVersionThenActivityWorkflow extends Workflow
{
    public function handle(): mixed
    {
        $version = Workflow::getVersion('php-worker-version', WorkflowStub::DEFAULT_VERSION, 1);

        return Workflow::activity(
            $version === WorkflowStub::DEFAULT_VERSION ? 'demo.legacy-version' : 'demo.new-version',
            $version,
        );
    }
}

final class WorkerProtocolRunnerSearchAttributesWorkflow extends Workflow
{
    public function handle(): void
    {
        Workflow::upsertSearchAttributes([
            'obsolete' => null,
            'phase' => 'worker-protocol',
            'priority' => 3,
        ]);
    }
}

final class WorkerProtocolRunnerSearchAttributesThenActivityWorkflow extends Workflow
{
    public function handle(): mixed
    {
        Workflow::upsertSearchAttributes([
            'phase' => 'before-activity',
        ]);

        return Workflow::activity('demo.after-search-attributes', 'ready');
    }
}

final class WorkerProtocolRunnerContinueAsNewWorkflow extends Workflow
{
    public function handle(): mixed
    {
        return Workflow::continueAsNew('next', 2);
    }
}

final class WorkerProtocolRunnerGeneratorWorkflow extends Workflow
{
    public function handle(): Generator
    {
        yield Workflow::activity('demo.generator');
    }
}
