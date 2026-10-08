<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Workflow;

final class WorkflowFiberRunnerSelectionHandleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, mixed, bool}>
     */
    public static function resolvedMembers(): iterable
    {
        $values = [
            'timer' => true,
            'activity' => [false, 0, '', null],
            'child' => false,
            'signal' => [false, 0, ''],
            'condition-true' => true,
            'condition-false' => false,
            'flat' => [true, false, [false, 0, '', null]],
            'nested' => [[true, [false, 0, '', null]], false],
        ];
        foreach ($values as $kind => $value) {
            yield 'cold:' . $kind => [$kind, $value, false];
            yield 'existing:' . $kind => [$kind, $value, true];
        }
    }

    #[DataProvider('resolvedMembers')]
    public function testResolvedLosingHandlesKeepStrictValuesWithoutAuthoringCancellation(
        string $kind,
        mixed $value,
        bool $existing,
    ): void {
        $author = $this->runner($kind);
        $scheduled = $author->step();
        $this->assertFalse($scheduled->completed);
        $this->assertSame('start_timer', $scheduled->commands[0]['type']);
        $this->assertSame(30, $scheduled->commands[0]['delay_seconds']);
        $opening = $this->openingHistory($scheduled->commands);
        $originalOpening = $opening;
        $history = $opening;
        foreach ($scheduled->commands as $index => $command) {
            $sequence = $index + 1;
            $history[] = $this->terminalEvent($command, $sequence, $kind);
        }
        $history[] = [
            'id' => 'selection-resolved',
            'sequence' => 102,
            'event_type' => 'SelectionResolved',
            'payload' => [
                'selection_group_id' => 'select-calls:1:' . count($scheduled->commands),
                'selection_group_base_sequence' => 1,
                'selection_group_size' => count($scheduled->commands),
                'member_key' => 'deadline',
                'member_index' => 0,
                'member_base_sequence' => 1,
                'member_size' => 1,
                'operation_kind' => 'timer',
                'operation_identity' => 'timer-1',
                'outcome' => 'completed',
                'resolution_event_id' => 'resolution-1',
                'resolution_event_type' => 'TimerFired',
            ],
            'recorded_at' => '2026-10-08T21:55:02+00:00',
        ];
        $originalHistory = $history;
        $expected = [
            'winner' => [
                'key' => 'deadline',
                'index' => 0,
                'kind' => 'timer',
                'identity' => 'timer-1',
                'value' => true,
            ],
            'remaining' => ['other'],
            'values' => [$value, $value],
        ];
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            if ($existing) {
                $runner = $this->runner($kind, $opening);
                $waiting = $runner->step();
                $this->assertFalse($waiting->completed);
                $this->assertSame([], $waiting->commands);
                $this->assertSame($runner, $runner->withHistoryEvents($history));
                $completed = $runner->step();
            } else {
                $completed = $this->runner($kind, $history)
                    ->step();
            }
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
            EngineSelectionHandlesWorkflow::class,
            'engine-selection',
            'engine-selection-run',
            [$kind],
            'avro',
            $history,
            'engine-selection',
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
            $metadata = ParallelChildGroup::payloadForPath($command['parallel_group_path']);
            [$type, $payload] = match ($command['type']) {
                'start_timer' => ['TimerScheduled', [
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
                    ...$metadata,
                ],
                'recorded_at' => '2026-10-08T21:55:00+00:00',
            ];
        }
        return $history;
    }

    /**
     * @param array<string, mixed> $command @return array<string, mixed>
     */
    private function terminalEvent(array $command, int $sequence, string $kind): array
    {
        $metadata = ParallelChildGroup::payloadForPath($command['parallel_group_path']);
        [$type, $payload] = match ($command['type']) {
            'start_timer' => ['TimerFired', [
                'timer_id' => 'timer-' . $sequence,
                'delay_seconds' => $command['delay_seconds'],
            ]],
            'schedule_activity' => ['ActivityCompleted', [
                'activity_execution_id' => 'activity-' . $sequence,
                'activity_type' => 'engine.reserve',
                'result' => Serializer::serializeWithCodec('avro', [false, 0, '', null]),
                'payload_codec' => 'avro',
            ]],
            'start_child_workflow' => ['ChildRunCompleted', [
                'child_workflow_run_id' => 'child-' . $sequence,
                'output' => Serializer::serializeWithCodec('avro', false),
                'payload_codec' => 'avro',
            ]],
            'open_signal_wait' => ['SignalApplied', [
                'signal_wait_id' => 'signal-' . $sequence,
                'signal_name' => 'approval',
                'value' => Serializer::serializeWithCodec('avro', [false, 0, '']),
                'payload_codec' => 'avro',
            ]],
            default => [$kind === 'condition-true' ? 'ConditionWaitSatisfied' : 'ConditionWaitTimedOut', [
                'condition_wait_id' => 'condition-' . $sequence,
                'condition_key' => 'engine.ready',
                'condition_definition_fingerprint' => $command['condition_definition_fingerprint'],
            ]],
        };
        return [
            'id' => 'resolution-' . $sequence,
            'sequence' => $sequence === 1 ? 100 : 101 + $sequence,
            'event_type' => $type,
            'payload' => [
                'sequence' => $sequence,
                ...$payload,
                ...$metadata,
            ],
            'recorded_at' => $sequence === 1
                ? '2026-10-08T21:55:01+00:00' : '2026-10-08T21:55:03+00:00',
        ];
    }
}

#[Signal('approval')]
final class EngineSelectionHandlesWorkflow extends Workflow
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
                'engine.ready'
            ),
            'flat' => Workflow::all([$timer, $child, $activity]),
            default => Workflow::all([
                static fn (): mixed => Workflow::all([$timer, $activity]),
                $child,
            ]),
        };
        $selection = Workflow::select([
            'deadline' => static fn (): mixed => Workflow::timer(30),
            'other' => $other,
        ]);
        $values = [];
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            $selection->handles['other']->cancel();
            $values[] = $selection->handles['other']->await();
        }
        return [
            'winner' => [
                'key' => $selection->key,
                'index' => $selection->index,
                'kind' => $selection->kind,
                'identity' => $selection->identity,
                'value' => $selection->result(),
            ],
            'remaining' => array_keys($selection->remaining()),
            'values' => $values,
        ];
    }
}
