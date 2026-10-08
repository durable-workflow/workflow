<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workflow\Serializers\Serializer;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\signal;
use Workflow\V2\Support\SignalCall;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Support\WorkflowStep;
use Workflow\V2\Workflow;

final class WorkflowFiberRunnerUpdateProtocolTest extends TestCase
{
    #[DataProvider('acceptedUpdates')]
    public function testAcceptedUpdateReturnsTypedCommandAndMutatesTheWaitingWorkflow(
        array $payload,
        ?string $fallbackName,
        string $label,
        int $amount,
    ): void {
        $history = [...self::waitingHistory(), self::event(3, 'UpdateAccepted', $payload)];
        $original = $history;
        $runner = self::runner($history);
        self::assertWaiting($runner->step());

        $updated = $runner->applyUpdate('update-1', $fallbackName);
        $expected = [
            'label' => $label,
            'total' => $amount,
            'enabled' => false,
            'optional' => null,
        ];
        self::assertFalse($updated->completed);
        self::assertNull($updated->result);
        self::assertNull($updated->yielded);
        self::assertNull($updated->activity);
        self::assertSame([$updated->command], $updated->commands);
        self::assertSame('complete_update', $updated->command['type']);
        self::assertSame('update-1', $updated->command['update_id']);
        self::assertSame('avro', $updated->command['payload_codec']);
        self::assertSame('avro', $updated->command['result']['codec']);
        self::assertSame($expected, Serializer::unserializeWithCodec('avro', $updated->command['result']['blob']));
        self::assertWaiting($runner->step());
        self::assertCompletedState(
            $runner->withHistoryEvents(self::finishedHistory($history))->step(),
            [$label],
            $amount
        );
        self::assertSame($original, $history);
    }

    public static function acceptedUpdates(): iterable
    {
        $base = [
            'update_id' => 'update-1',
            'update_name' => 'record',
        ];
        yield 'payload sequence and typed positional arguments' => [
            [
                ...$base,
                'sequence' => 1,
                'arguments' => self::encoded(['first', 4]),
            ], null, 'first', 4,
        ];
        yield 'legacy workflow sequence and named argument map' => [
            [
                ...$base,
                'workflow_sequence' => 1,
                'arguments' => self::encoded([
                    'label' => 'named',
                    'amount' => 0,
                ]),
            ],
            null, 'named', 0,
        ];
        yield 'accepted event uses current wait when command sequence absent' => [
            [
                ...$base,
                'arguments' => self::encoded(['current', 2]),
            ], null, 'current', 2,
        ];
        yield 'missing arguments invoke declared defaults' => [$base, null, 'default', 1];
        yield 'legacy scalar arguments become one parameter' => [
            [
                ...$base,
                'arguments' => self::encoded('scalar'),
            ], null, 'scalar', 1,
        ];
        yield 'task method supplies missing history method' => [
            [
                'update_id' => 'update-1',
                'arguments' => self::encoded(['fallback', 3]),
            ], 'record', 'fallback', 3,
        ];
        yield 'legacy missing update id matches task method' => [
            [
                'update_name' => 'record',
                'arguments' => self::encoded(['legacy', 5]),
            ], 'record', 'legacy', 5,
        ];
        yield 'recorded method overrides contradictory task method' => [
            [
                ...$base,
                'arguments' => self::encoded(['authoritative', 6]),
                'payload_codec' => 'avro',
            ],
            'explode', 'authoritative', 6,
        ];
    }

    public function testLatestMatchingAcceptanceWinsOverOtherUpdatesAndOtherEventKinds(): void
    {
        $history = [
            ...self::waitingHistory(),
            self::event(3, 'UpdateAccepted', [
                'update_id' => 'update-1',
                'update_name' => 'record',
                'arguments' => self::encoded(['old', 1]),
            ]),
            self::event(4, 'UpdateAccepted', [
                'update_id' => 'update-1',
                'update_name' => 'record',
                'arguments' => self::encoded(['latest', 7]),
            ]),
            self::event(5, 'UpdateAccepted', [
                'update_id' => 'other',
                'update_name' => 'record',
                'arguments' => self::encoded(['other', 99]),
            ]),
            self::event(6, 'UpdateCompleted', [
                'update_id' => 'update-1',
                'update_name' => 'record',
                'arguments' => self::encoded(['terminal', 99]),
            ]),
        ];
        $runner = self::runner($history);
        $command = $runner->applyUpdate('update-1')
->command;
        self::assertSame(
            [
                'label' => 'latest',
                'total' => 7,
                'enabled' => false,
                'optional' => null,
            ],
            Serializer::unserializeWithCodec('avro', $command['result']['blob'])
        );
        self::assertCompletedState($runner->withHistoryEvents(self::finishedHistory($history))->step(), ['latest'], 7);
    }

    #[DataProvider('failedUpdates')]
    public function testFailedUpdateReturnsItsDiagnosticAndLeavesWorkflowAbleToComplete(
        array $payload,
        ?string $fallbackName,
        string $exceptionClass,
        string $message,
    ): void {
        $history = [...self::waitingHistory(), self::event(3, 'UpdateAccepted', $payload)];
        $original = $history;
        $runner = self::runner($history);
        $failed = $runner->applyUpdate('update-1', $fallbackName);
        self::assertFalse($failed->completed);
        self::assertNull($failed->result);
        self::assertNull($failed->activity);
        self::assertNull($failed->yielded);
        self::assertSame([
            'type' => 'fail_update',
            'update_id' => 'update-1',
            'message' => $message,
            'exception_class' => $exceptionClass,
            'exception_type' => $exceptionClass,
        ], $failed->command);
        self::assertSame([$failed->command], $failed->commands);
        self::assertWaiting($runner->step());
        self::assertCompletedState($runner->withHistoryEvents(self::finishedHistory($history))->step(), [], 0);
        self::assertSame($original, $history);
    }

    public static function failedUpdates(): iterable
    {
        yield 'different id does not match by method' => [
            [
                'update_id' => 'other',
                'update_name' => 'record',
            ], 'record', LogicException::class,
            'Workflow update [update-1] was not found in task history.',
        ];
        yield 'legacy method does not match another task method' => [
            [
                'update_name' => 'record',
            ], 'explode', LogicException::class,
            'Workflow update [update-1] was not found in task history.',
        ];
        yield 'matched id requires a method name' => [
            [
                'update_id' => 'update-1',
            ], null, LogicException::class,
            'Workflow update history event is missing an update method name.',
        ];
        yield 'undeclared handler is rejected' => [
            [
                'update_id' => 'update-1',
                'update_name' => 'missing',
            ], null, LogicException::class,
            sprintf('Workflow update [missing] is not declared on workflow [%s].', FiberUpdateProtocolWorkflow::class),
        ];
        yield 'handler exception retains identity and message' => [
            [
                'update_id' => 'update-1',
                'update_name' => 'explode',
                'arguments' => self::encoded(['handler failed']),
            ],
            null, RuntimeException::class, 'handler failed',
        ];
        yield 'empty exception message has a usable fallback' => [
            [
                'update_id' => 'update-1',
                'update_name' => 'explode',
                'arguments' => self::encoded(['']),
            ],
            null, RuntimeException::class, 'Workflow update execution failed.',
        ];
    }

    public function testEmptyTaskUpdateIdIsRejectedBeforeWorkflowExecution(): void
    {
        $runner = self::runner(self::waitingHistory());
        try {
            $runner->applyUpdate('');
            self::fail('An empty task update id must be rejected.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Workflow update task field [workflow_update_id] must be non-empty.',
                $exception->getMessage()
            );
        }
        self::assertWaiting($runner->step());
        self::assertCompletedState(
            $runner->withHistoryEvents(self::finishedHistory(self::waitingHistory()))->step(),
            [],
            0
        );
    }

    #[DataProvider('replayedUpdates')]
    public function testAppliedUpdatesReplayOnceAtTheirWaitAndColdReconstructionPreservesOrder(
        bool $explicitId,
        bool $legacySequence,
    ): void {
        $sequence = $legacySequence ? 'workflow_sequence' : 'sequence';
        $first = self::event(3, 'UpdateApplied', [
            $sequence => 1,
            'update_id' => 'first',
            'update_name' => 'record',
            'arguments' => self::encoded(['first', 2]),
        ]);
        if ($explicitId) {
            $first['id'] = 'applied-first';
        }
        $history = [
            ...self::waitingHistory(), $first, $first,
            self::event(4, 'UpdateApplied', [
                $sequence => 1,
                'update_id' => 'second',
                'update_name' => 'record',
                'arguments' => self::encoded(['second', 3]),
            ]),
            self::event(5, 'UpdateApplied', [
                $sequence => 2,
                'update_id' => 'later',
                'update_name' => 'record',
                'arguments' => self::encoded(['wrong-position', 99]),
            ]),
            self::event(6, 'UpdateAccepted', [
                'update_id' => 'probe',
                'update_name' => 'record',
                'arguments' => self::encoded(['probe', 1]),
            ]),
        ];
        $original = $history;
        $runner = self::runner($history);
        self::assertWaiting($runner->step());
        self::assertWaiting($runner->step());
        self::assertWaiting($runner->step());
        $probe = $runner->applyUpdate('probe');
        self::assertSame([
            'label' => 'probe',
            'total' => 6,
            'enabled' => false,
            'optional' => null,
        ], Serializer::unserializeWithCodec('avro', $probe->command['result']['blob']));
        $finished = self::finishedHistory($history);
        self::assertCompletedState(self::runner($finished)->step(), ['first', 'second'], 5);
        self::assertCompletedState(self::runner($finished)->step(), ['first', 'second'], 5);
        self::assertSame($original, $history);
    }

    public static function replayedUpdates(): iterable
    {
        yield 'event id and current sequence' => [true, false];
        yield 'event id and legacy sequence' => [true, true];
        yield 'legacy identity and current sequence' => [false, false];
        yield 'legacy identity and legacy sequence' => [false, true];
    }

    private static function runner(array $history): WorkflowFiberRunner
    {
        return WorkflowFiberRunner::forClass(
            FiberUpdateProtocolWorkflow::class,
            'workflow-1',
            'run-1',
            [],
            'avro',
            $history
        );
    }

    private static function waitingHistory(): array
    {
        return [self::event(1, 'WorkflowStarted', []), self::event(2, 'SignalWaitOpened', [
            'sequence' => 1,
            'signal_name' => 'finish',
            'signal_wait_id' => 'wait-1',
        ])];
    }

    private static function finishedHistory(array $history): array
    {
        return [...$history, self::event(100, 'SignalReceived', [
            'signal_name' => 'finish',
            'signal_wait_id' => 'wait-1',
            'arguments' => self::encoded([]),
        ])];
    }

    private static function event(int $sequence, string $type, array $payload): array
    {
        return [
            'sequence' => $sequence,
            'event_type' => $type,
            'payload' => $payload,
            'recorded_at' => '2026-10-08T00:00:00Z',
        ];
    }

    private static function encoded(mixed $value): array
    {
        return [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', $value),
        ];
    }

    private static function assertWaiting(WorkflowStep $step): void
    {
        self::assertFalse($step->completed);
        self::assertNull($step->result);
        self::assertNull($step->command);
        self::assertSame([], $step->commands);
        self::assertInstanceOf(SignalCall::class, $step->yielded);
        self::assertSame('finish', $step->yielded->name);
    }

    private static function assertCompletedState(WorkflowStep $step, array $labels, int $total): void
    {
        self::assertTrue($step->completed);
        self::assertSame([
            'labels' => $labels,
            'total' => $total,
            'workflow_id' => 'workflow-1',
            'run_id' => 'run-1',
        ], $step->result);
        self::assertSame('complete_workflow', $step->command['type']);
        self::assertSame($step->result, Serializer::unserializeWithCodec('avro', $step->command['result']));
        self::assertSame([$step->command], $step->commands);
    }
}

#[Signal('finish')]
final class FiberUpdateProtocolWorkflow extends Workflow
{
    private array $labels = [];

    private int $total = 0;

    public function handle(): array
    {
        signal('finish');

        return [
            'labels' => $this->labels,
            'total' => $this->total,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[UpdateMethod]
    public function record(string $label = 'default', int $amount = 1): array
    {
        $this->labels[] = $label;
        $this->total += $amount;

        return [
            'label' => $label,
            'total' => $this->total,
            'enabled' => false,
            'optional' => null,
        ];
    }

    #[UpdateMethod]
    public function explode(string $message): never
    {
        throw new RuntimeException($message);
    }
}
