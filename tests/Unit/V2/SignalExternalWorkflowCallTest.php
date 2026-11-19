<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ExternalSignalOutcome;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Exceptions\InvalidExternalSignalException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ExternalSignalHistory;
use Workflow\V2\Support\SignalExternalWorkflowCall;

final class SignalExternalWorkflowCallTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function refusedNames(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'ideographic blank' => ["\u{3000}\u{2003}"],
            'newline' => ["bad\nname"],
            'nul' => ["bad\0name"],
            'escape' => ["bad\x1bname"],
            'delete' => ["bad\x7fname"],
            '192 characters' => [str_repeat('a', 192)],
            '256 bytes' => [str_repeat('é', 128)],
            'invalid utf-8' => ["bad\xC3name"],
        ];
    }

    #[DataProvider('refusedNames')]
    public function testServerSignalNameRulesRefuseInWorkflowCode(string $name): void
    {
        try {
            new SignalExternalWorkflowCall('order-42', $name);
            $this->fail('The signal name must be refused.');
        } catch (InvalidExternalSignalException $exception) {
            $this->assertSame('invalid_signal_name', $exception->reason);
            $this->assertSame('signal_name', $exception->field);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedNames(): array
    {
        return [
            'ordinary' => ['approve'],
            '191 characters' => [str_repeat('a', 191)],
            '255 bytes as 85 characters' => [str_repeat('€', 85)],
            'inner spaces' => ['approve order'],
        ];
    }

    #[DataProvider('acceptedNames')]
    public function testLongestServerAcceptedNamesAreAccepted(string $name): void
    {
        $this->assertSame($name, (new SignalExternalWorkflowCall('order-42', $name))->signalName);
    }

    public function testTargetIdentifiersFollowTheCommandFieldRules(): void
    {
        foreach ([['', null], [' ', null], [str_repeat('a', 192), null], ['order-42', ' ']] as [$id, $run]) {
            try {
                new SignalExternalWorkflowCall($id, 'approve', [], $run);
                $this->fail('The target must be refused.');
            } catch (InvalidExternalSignalException $exception) {
                $this->assertSame('invalid_signal_external_target', $exception->reason);
            }
        }

        $call = new SignalExternalWorkflowCall(str_repeat('a', 191), 'approve', [
            2 => 'x',
        ], 'run-1');
        $this->assertSame('run-1', $call->runId);
        $this->assertSame([
            2 => 'x',
        ], $call->arguments);
        $this->assertSame(['x'], (new SignalExternalWorkflowCall('order-42', 'approve', ['x']))->arguments);
    }

    public function testReservedRequestIdsAreRecognized(): void
    {
        $this->assertSame('workflow-signal:run-1:3', ExternalSignalHistory::requestId('run-1', 3));
        $this->assertTrue(ExternalSignalHistory::isReservedRequestId('workflow-signal:anything'));
        $this->assertFalse(ExternalSignalHistory::isReservedRequestId('workflow-signalling'));
        $this->assertFalse(ExternalSignalHistory::isReservedRequestId(null));
    }

    public function testRecordedOutcomeReplaysAsTheSameValue(): void
    {
        $call = new SignalExternalWorkflowCall('order-42', 'approve', ['Ada', 2]);
        $run = $this->runWithHistory([
            ['ExternalSignalRequested', $this->requested($call)],
            ['ExternalSignalFailed', [
                ...$this->identity(),
                'target_workflow_run_id' => 'target-run',
                'outcome' => 'target_closed',
                'reason' => 'run_not_active',
                'message' => 'The target run is closed.',
                'target_command_id' => 'cmd-1',
            ]],
        ]);

        $result = ExternalSignalHistory::recordedResult($run, 1, $call);

        $this->assertNotNull($result);
        $this->assertSame(ExternalSignalOutcome::TargetClosed, $result->outcome);
        $this->assertFalse($result->delivered());
        $this->assertSame('run_not_active', $result->reason);
        $this->assertSame('target-run', $result->targetRunId);
        $this->assertSame('cmd-1', $result->targetCommandId);
        $this->assertNull(ExternalSignalHistory::recordedResult($run, 2, $call));
    }

    /**
     * @return array<string, array{SignalExternalWorkflowCall}>
     */
    public static function changedCalls(): array
    {
        return [
            'target' => [new SignalExternalWorkflowCall('order-43', 'approve', ['Ada', 2])],
            'run' => [new SignalExternalWorkflowCall('order-42', 'approve', ['Ada', 2], 'run-9')],
            'name' => [new SignalExternalWorkflowCall('order-42', 'reject', ['Ada', 2])],
            'arguments' => [new SignalExternalWorkflowCall('order-42', 'approve', ['Ada', '2'])],
        ];
    }

    #[DataProvider('changedCalls')]
    public function testChangedSignalAtARecordedSequenceIsAShapeMismatch(SignalExternalWorkflowCall $changed): void
    {
        $recorded = new SignalExternalWorkflowCall('order-42', 'approve', ['Ada', 2]);
        $run = $this->runWithHistory([
            ['ExternalSignalRequested', $this->requested($recorded)],
            ['ExternalSignalDelivered', $this->delivered()],
        ]);

        $this->expectException(HistoryEventShapeMismatchException::class);
        $this->expectExceptionMessage('external_signal_mismatch');

        ExternalSignalHistory::recordedResult($run, 1, $changed);
    }

    public function testMalformedResolutionHistoryIsRefused(): void
    {
        $call = new SignalExternalWorkflowCall('order-42', 'approve', ['Ada', 2]);
        $histories = [
            'outcome without request' => [['ExternalSignalDelivered', $this->delivered()]],
            'request without outcome' => [['ExternalSignalRequested', $this->requested($call)]],
            'two outcomes' => [
                ['ExternalSignalRequested', $this->requested($call)],
                ['ExternalSignalDelivered', $this->delivered()],
                ['ExternalSignalDelivered', $this->delivered()],
            ],
            'unknown outcome' => [
                ['ExternalSignalRequested', $this->requested($call)],
                [
                    'ExternalSignalFailed', [
                        ...$this->delivered(),
                        'outcome' => 'lost',
                        'reason' => 'x',
                    ]],
            ],
            'renamed target' => [
                ['ExternalSignalRequested', $this->requested($call)],
                [
                    'ExternalSignalDelivered', [
                        ...$this->delivered(),
                        'target_workflow_instance_id' => 'other',
                    ]],
            ],
            'failure without reason' => [
                ['ExternalSignalRequested', $this->requested($call)],
                [
                    'ExternalSignalFailed', [
                        ...$this->identity(),
                        'outcome' => 'rejected',
                    ]],
            ],
        ];

        foreach ($histories as $label => $history) {
            try {
                ExternalSignalHistory::recordedResult($this->runWithHistory($history), 1, $call);
                $this->fail("{$label} must be refused.");
            } catch (HistoryEventShapeMismatchException $exception) {
                $this->assertStringContainsString('external_signal_history_invalid', $exception->getMessage(), $label);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(): array
    {
        return [
            'sequence' => 1,
            'target_workflow_instance_id' => 'order-42',
            'signal_name' => 'approve',
            'request_id' => 'workflow-signal:sender-run:1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requested(SignalExternalWorkflowCall $call): array
    {
        return [
            'sequence' => 1,
            'target_workflow_instance_id' => $call->workflowId,
            'target_workflow_run_id' => $call->runId,
            'signal_name' => $call->signalName,
            'arguments' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', $call->arguments),
            ],
            'payload_codec' => 'avro',
            'request_id' => 'workflow-signal:sender-run:1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function delivered(): array
    {
        return [
            ...$this->identity(),
            'target_workflow_run_id' => 'target-run',
            'outcome' => 'delivered',
            'target_command_id' => 'cmd-1',
            'target_signal_id' => 'sig-1',
            'retained' => false,
        ];
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $events
     */
    private function runWithHistory(array $events): WorkflowRun
    {
        $run = new WorkflowRun();
        $run->setRelation('historyEvents', collect($events)->values()->map(
            static fn (array $event, int $index): WorkflowHistoryEvent => new WorkflowHistoryEvent([
                'sequence' => $index + 1,
                'event_type' => $event[0],
                'payload' => $event[1],
            ]),
        ));

        return $run;
    }
}
