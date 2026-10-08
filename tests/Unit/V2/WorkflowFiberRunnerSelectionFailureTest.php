<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Exceptions\DurableOperationCancelledException;
use Workflow\V2\Exceptions\RestoredWorkflowException;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Workflow;

final class WorkflowFiberRunnerSelectionFailureTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function closedMembers(): iterable
    {
        foreach ([
            'timer',
            'activity',
            'child',
            'signal',
            'condition-true',
            'condition-false',
            'flat',
            'nested',
        ] as $kind) {
            foreach ([false, true] as $existing) {
                yield 'cancelled:' . $kind . ':' . (int) $existing => [$kind, true, $existing];
                if (in_array($kind, ['activity', 'child', 'flat', 'nested'], true)) {
                    yield 'failed:' . $kind . ':' . (int) $existing => [$kind, false, $existing];
                }
            }
        }
    }

    #[DataProvider('closedMembers')]
    public function testClosedLosingHandlesReplayStrictFailureIdentityAndTime(
        string $kind,
        bool $cancelled,
        bool $existing,
    ): void {
        $scheduled = $this->runner($kind)
            ->step();
        $this->assertFalse($scheduled->completed);
        $this->assertSame('start_timer', $scheduled->commands[0]['type']);
        $opening = $this->openingHistory($scheduled->commands);
        $group = in_array($kind, ['flat', 'nested'], true);
        $operationKind = $group ? 'group' : (str_starts_with($kind, 'condition') ? 'condition' : $kind);
        $identity = $group ? 'group:2:3' : $operationKind . '-2';
        $selectionId = 'select-calls:1:' . count($scheduled->commands);
        $history = [
            ...$opening, [
                'id' => 'deadline-fired',
                'sequence' => 100,
                'event_type' => 'TimerFired',
                'payload' => [
                    'sequence' => 1,
                    'timer_id' => 'timer-1',
                ],
                'recorded_at' => '2026-10-08T22:25:01+00:00',
            ], [
                'id' => 'deadline-selected',
                'sequence' => 101,
                'event_type' => 'SelectionResolved',
                'payload' => [
                    'selection_group_id' => $selectionId,
                    'selection_group_base_sequence' => 1,
                    'selection_group_size' => count($scheduled->commands),
                    'member_key' => 'deadline',
                    'member_index' => 0,
                    'member_base_sequence' => 1,
                    'member_size' => 1,
                    'operation_kind' => 'timer',
                    'operation_identity' => 'timer-1',
                    'outcome' => 'completed',
                    'resolution_event_id' => 'deadline-fired',
                    'resolution_event_type' => 'TimerFired',
                ],
                'recorded_at' => '2026-10-08T22:25:02+00:00',
            ]];
        if ($cancelled) {
            $history[] = [
                'id' => 'other-cancelled',
                'sequence' => 102,
                'event_type' => 'SelectionOperationCancelled',
                'payload' => [
                    'selection_group_id' => $selectionId,
                    'member_key' => 'other',
                    'member_index' => 1,
                    'member_base_sequence' => 2,
                    'member_size' => $group ? 3 : 1,
                    'operation_kind' => $operationKind,
                    'operation_identity' => $identity,
                    'reason' => 'customer cancelled',
                ],
                'recorded_at' => '2026-10-08T22:25:03+00:00',
            ];
            $failure = [
                'class' => DurableOperationCancelledException::class,
                'message' => 'Durable ' . $operationKind . ' operation [' . $identity . '] was cancelled.',
                'code' => 0,
                'selection' => [$selectionId, 'other', 1, $operationKind, $identity],
            ];
        } else {
            $failureIndex = $kind === 'flat' || $kind === 'nested' ? 2 : 1;
            $command = $scheduled->commands[$failureIndex];
            $sequence = $failureIndex + 1;
            $activity = $command['type'] === 'schedule_activity';
            $history[] = [
                'id' => 'other-failed',
                'sequence' => 102,
                'event_type' => $activity ? 'ActivityFailed' : 'ChildRunFailed',
                'payload' => [
                    'sequence' => $sequence,
                    ($activity ? 'activity_execution_id' : 'child_workflow_run_id') => ($activity ? 'activity-' : 'child-') . $sequence,
                    'exception_class' => 'RemotePaymentFailure',
                    'exception_type' => 'engine.payment-declined',
                    'message' => 'payment declined',
                    'code' => 31,
                    'exception' => [
                        'class' => 'RemotePaymentFailure',
                        'type' => 'engine.payment-declined',
                        'message' => 'payment declined',
                        'code' => 31,
                        'details' => [false, 0, '', null],
                        'details_payload_codec' => 'avro',
                        'non_retryable' => true,
                    ],
                    ...ParallelChildGroup::payloadForPath($command['parallel_group_path']),
                ],
                'recorded_at' => '2026-10-08T22:25:03+00:00',
            ];
            $failure = [
                'class' => RestoredWorkflowException::class,
                'message' => 'payment declined',
                'code' => 31,
                'original_class' => 'RemotePaymentFailure',
                'type' => 'engine.payment-declined',
                'details' => [false, 0, '', null],
                'details_payload_codec' => 'avro',
                'non_retryable' => true,
            ];
        }
        $failure['time'] = '2026-10-08T22:25:03+00:00';
        $expected = [
            'winner' => ['deadline', 0, 'timer', 'timer-1', true],
            'remaining' => ['other'],
            'failures' => [$failure, $failure],
        ];
        $originalOpening = $opening;
        $originalHistory = $history;
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            if ($existing) {
                $runner = $this->runner($kind, $opening);
                $waiting = $runner->step();
                $this->assertFalse($waiting->completed);
                $this->assertSame([], $waiting->commands);
                $this->assertSame($runner, $runner->withHistoryEvents($history));
            } else {
                $runner = $this->runner($kind, $history);
            }
            $completed = $runner->step();
            $this->assertTrue($completed->completed);
            $this->assertSame($expected, $completed->result);
            $this->assertSame([[
                'type' => 'complete_workflow',
                'result' => Serializer::serializeWithCodec('avro', $expected),
                'payload_codec' => 'avro',
            ]], $completed->commands);
            $this->assertNull($completed->yielded);
            $this->assertSame($originalOpening, $opening);
            $this->assertSame($originalHistory, $history);
        }
    }

    /**
     * @param list<array<string, mixed>> $history
     */
    private function runner(string $kind, array $history = []): WorkflowFiberRunner
    {
        return WorkflowFiberRunner::forClass(
            EngineSelectionFailureWorkflow::class,
            'engine-failure',
            'engine-failure-run',
            [$kind],
            'avro',
            $history,
            'engine-failure',
        );
    }

    /**
     * @param list<array<string, mixed>> $commands
     * @return list<array<string, mixed>>
     */
    private function openingHistory(array $commands): array
    {
        $history = [];
        foreach ($commands as $index => $command) {
            $sequence = $index + 1;
            [$type, $payload] = match ($command['type']) {
                'start_timer' => [
                    'TimerScheduled', [
                        'timer_id' => 'timer-' . $sequence,
                        'delay_seconds' => $command['delay_seconds'],
                    ]],
                'schedule_activity' => ['ActivityScheduled', [
                    'activity_execution_id' => 'activity-' . $sequence,
                    'activity_type' => 'engine.reserve',
                    'arguments' => $command['arguments'],
                    'payload_codec' => 'avro',
                ]],
                'start_child_workflow' => ['ChildWorkflowScheduled', [
                    'child_workflow_run_id' => 'child-' . $sequence,
                    'child_workflow_type' => 'engine.child',
                    'workflow_type' => 'engine.child',
                ]],
                'open_signal_wait' => ['SignalWaitOpened', [
                    'signal_wait_id' => 'signal-' . $sequence,
                    'signal_name' => 'approval',
                    'timeout_seconds' => 60,
                ]],
                default => ['ConditionWaitOpened', [
                    'condition_wait_id' => 'condition-' . $sequence,
                    'condition_wait_occurrence_id' => 'failure:condition:' . $sequence,
                    'condition_key' => 'engine.ready',
                    'condition_definition_fingerprint' => $command['condition_definition_fingerprint'],
                    'timeout_seconds' => 60,
                ]],
            };
            $history[] = [
                'id' => 'opening-' . $sequence,
                'sequence' => 10 + $sequence,
                'event_type' => $type,
                'payload' => [
                    'sequence' => $sequence,
                    ...$payload,
                    ...ParallelChildGroup::payloadForPath($command['parallel_group_path']),
                ],
                'recorded_at' => '2026-10-08T22:25:00+00:00',
            ];
        }
        return $history;
    }
}

#[Signal('approval')]
final class EngineSelectionFailureWorkflow extends Workflow
{
    public function handle(string $kind): array
    {
        $activity = static fn (): mixed => Workflow::activity('engine.reserve', false, 0, '');
        $child = static fn (): mixed => Workflow::child('engine.child');
        $timer = static fn (): mixed => Workflow::timer(99);
        $other = static fn (): mixed => match ($kind) {
            'timer' => $timer(),
            'activity' => $activity(),
            'child' => $child(),
            'signal' => Workflow::await('approval', 60),
            'condition-true', 'condition-false' => Workflow::awaitWithTimeout(
                60,
                static fn (): bool => $kind === 'condition-true',
                'engine.ready',
            ),
            'flat' => Workflow::all([$timer, $activity, $child]),
            default => Workflow::all([
                static fn (): mixed => Workflow::all([$timer, $activity]),
                $child,
            ]),
        };
        $selection = Workflow::select([
            'deadline' => static fn (): mixed => Workflow::timer(30),
            'other' => $other,
        ]);
        $failures = [];
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            try {
                $selection->handles['other']->await();
                $failures[] = [
                    'unexpected_success' => true,
                ];
            } catch (Throwable $exception) {
                $failure = [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                ];
                if ($exception instanceof DurableOperationCancelledException) {
                    $failure['selection'] = [
                        $exception->selectionGroupId, $exception->memberKey, $exception->memberIndex,
                        $exception->operationKind, $exception->operationIdentity,
                    ];
                }
                if ($exception instanceof RestoredWorkflowException) {
                    $payload = $exception->failurePayload();
                    $failure += [
                        'original_class' => $exception->originalExceptionClass(),
                        'type' => $payload['type'],
                        'details' => $payload['details'],
                        'details_payload_codec' => $payload['details_payload_codec'],
                        'non_retryable' => $payload['non_retryable'],
                    ];
                }
                $failure['time'] = Workflow::now()->toIso8601String();
                $failures[] = $failure;
            }
        }
        return [
            'winner' => [
                $selection->key,
                $selection->index,
                $selection->kind,
                $selection->identity,
                $selection->result(),
            ],
            'remaining' => array_keys($selection->remaining()),
            'failures' => $failures,
        ];
    }
}
