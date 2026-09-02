<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\ConditionWaitDefinitionMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\AwaitCall;
use Workflow\V2\Support\AwaitWithTimeoutCall;
use Workflow\V2\Support\ConditionWaits;
use Workflow\V2\Support\SignalWaits;

final class DurableWaitProjectionTest extends TestCase
{
    public function testConditionWaitProjectionReconstructsTimeoutResolutionAndCancellation(): void
    {
        $deadline = Carbon::parse('2026-08-27T12:00:20Z');
        $run = $this->runWithHistoryEvents([
            new \stdClass(),
            $this->historyEvent(HistoryEventType::TimerScheduled, 1, [
                'timer_kind' => 'workflow_timer',
                'sequence' => 10,
            ]),
            $this->historyEvent(HistoryEventType::ConditionWaitOpened, 2, [
                'condition_key' => 'missing-identity',
            ]),
            $this->historyEvent(HistoryEventType::TimerScheduled, 3, [
                'timer_kind' => 'condition_timeout',
                'condition_key' => 'missing-identity',
            ]),
            $this->historyEvent(HistoryEventType::ConditionWaitOpened, 4, [
                'condition_wait_id' => 'wait-timeout',
                'condition_wait_occurrence_id' => 'occurrence-timeout',
                'condition_key' => 'invoice-paid',
                'condition_definition_fingerprint' => 'fingerprint-a',
                'sequence' => '10',
                'timeout_seconds' => '15',
            ], '2026-08-27T12:00:00Z'),
            $this->historyEvent(HistoryEventType::TimerScheduled, 5, [
                'timer_kind' => 'condition_timeout',
                'condition_wait_id' => 'wait-timeout',
                'condition_wait_occurrence_id' => 'ignored-occurrence',
                'condition_key' => 'ignored-key',
                'condition_definition_fingerprint' => 'ignored-fingerprint',
                'sequence' => 999,
                'delay_seconds' => 20,
                'timer_id' => 'timer-timeout',
                'fire_at' => $deadline,
            ]),
            $this->historyEvent(HistoryEventType::TimerFired, 6, [
                'timer_kind' => 'condition_timeout',
                'condition_wait_id' => 'wait-timeout',
                'delay_seconds' => '20',
                'timer_id' => 'timer-timeout',
                'fire_at' => '2026-08-27T12:00:20Z',
                'fired_at' => '2026-08-27T12:00:21Z',
            ], '2026-08-27T12:00:22Z'),
            $this->historyEvent(HistoryEventType::ConditionWaitTimedOut, 7, [
                'condition_wait_id' => 'wait-timeout',
                'sequence' => 10,
                'timeout_seconds' => 20,
                'timer_id' => 'timer-timeout',
            ], '2026-08-27T12:00:23Z'),
            $this->historyEvent(HistoryEventType::TimerScheduled, 8, [
                'timer_kind' => 'condition_timeout',
                'condition_wait_occurrence_id' => 'occurrence-satisfied',
                'condition_key' => 'shipment-ready',
                'condition_definition_fingerprint' => 'fingerprint-b',
                'sequence' => '11',
                'delay_seconds' => '30',
                'timer_id' => 'timer-satisfied',
                'fire_at' => '2026-08-27T12:01:00Z',
            ]),
            $this->historyEvent(HistoryEventType::ConditionWaitSatisfied, 9, [
                'condition_key' => 'ignored-after-open',
                'sequence' => 11,
                'timeout_seconds' => '25',
            ], '2026-08-27T12:00:30Z'),
            $this->historyEvent(HistoryEventType::ConditionWaitSatisfied, 10, [
                'condition_wait_id' => 'resolved-without-open',
                'condition_key' => 'manual-approval',
            ], '2026-08-27T12:00:31Z'),
            $this->historyEvent(HistoryEventType::ConditionWaitOpened, 11, [
                'condition_wait_id' => 'selection-a',
                'condition_key' => 'selection-a',
                'sequence' => 20,
            ]),
            $this->historyEvent(HistoryEventType::ConditionWaitOpened, 12, [
                'condition_wait_id' => 'selection-b',
                'condition_key' => 'selection-b',
                'sequence' => '21',
            ]),
            $this->historyEvent(HistoryEventType::ConditionWaitOpened, 13, [
                'condition_wait_id' => 'outside-selection',
                'condition_key' => 'outside-selection',
                'sequence' => 30,
            ]),
            $this->historyEvent(HistoryEventType::ConditionWaitOpened, 14, [
                'condition_wait_id' => 'without-sequence',
                'condition_key' => 'without-sequence',
            ]),
            $this->historyEvent(HistoryEventType::SelectionOperationCancelled, 15, [
                'member_base_sequence' => 20,
                'member_size' => 0,
            ]),
            $this->historyEvent(HistoryEventType::SelectionOperationCancelled, 16, [
                'member_base_sequence' => '20',
                'member_size' => '2',
                'selection_group_id' => 'selection-group',
            ], '2026-08-27T12:00:40Z'),
            $this->historyEvent(HistoryEventType::WorkflowTerminated, 17, [], '2026-08-27T12:00:45Z'),
        ]);

        $waits = $this->indexBy(ConditionWaits::forRun($run), 'condition_wait_id');

        $this->assertCount(7, $waits);
        $this->assertSame('resolved', $waits['wait-timeout']['status']);
        $this->assertSame('timed_out', $waits['wait-timeout']['source_status']);
        $this->assertSame('invoice-paid', $waits['wait-timeout']['condition_key']);
        $this->assertSame('fingerprint-a', $waits['wait-timeout']['condition_definition_fingerprint']);
        $this->assertSame('occurrence-timeout', $waits['wait-timeout']['condition_wait_occurrence_id']);
        $this->assertSame(10, $waits['wait-timeout']['sequence']);
        $this->assertSame(20, $waits['wait-timeout']['timeout_seconds']);
        $this->assertSame('timer-timeout', $waits['wait-timeout']['timer_id']);
        $this->assertSame('timer', $waits['wait-timeout']['resume_source_kind']);
        $this->assertSame('timer-timeout', $waits['wait-timeout']['resume_source_id']);
        $this->assertSame('2026-08-27T12:00:00+00:00', $this->timestamp($waits['wait-timeout']['opened_at']));
        $this->assertSame('2026-08-27T12:00:20+00:00', $this->timestamp($waits['wait-timeout']['deadline_at']));
        $this->assertSame('2026-08-27T12:00:21+00:00', $this->timestamp($waits['wait-timeout']['timeout_fired_at']));
        $this->assertSame('2026-08-27T12:00:23+00:00', $this->timestamp($waits['wait-timeout']['resolved_at']));

        $this->assertSame('resolved', $waits['condition:11']['status']);
        $this->assertSame('satisfied', $waits['condition:11']['source_status']);
        $this->assertSame('shipment-ready', $waits['condition:11']['condition_key']);
        $this->assertSame(25, $waits['condition:11']['timeout_seconds']);
        $this->assertSame('external_input', $waits['condition:11']['resume_source_kind']);
        $this->assertNull($waits['condition:11']['resume_source_id']);

        $this->assertSame('resolved', $waits['resolved-without-open']['status']);
        $this->assertNull($waits['resolved-without-open']['sequence']);

        foreach (['selection-a', 'selection-b'] as $waitId) {
            $this->assertSame('cancelled', $waits[$waitId]['status']);
            $this->assertSame('selection_cancelled', $waits[$waitId]['source_status']);
            $this->assertSame('selection_cancellation', $waits[$waitId]['resume_source_kind']);
            $this->assertSame('selection-group', $waits[$waitId]['resume_source_id']);
        }

        foreach (['outside-selection', 'without-sequence'] as $waitId) {
            $this->assertSame('cancelled', $waits[$waitId]['status']);
            $this->assertSame('terminated', $waits[$waitId]['source_status']);
            $this->assertSame('2026-08-27T12:00:45+00:00', $this->timestamp($waits[$waitId]['resolved_at']));
        }

        $this->assertSame('condition:11', ConditionWaits::waitIdForSequence($run, 11));
        $this->assertNull(ConditionWaits::waitIdForSequence($run, 999));
    }

    #[DataProvider('terminalEventProvider')]
    public function testConditionWaitProjectionClosesOpenWaitsWithTerminalSourceStatus(
        HistoryEventType $eventType,
        string $sourceStatus,
    ): void {
        $run = $this->runWithHistoryEvents([
            $this->historyEvent(HistoryEventType::ConditionWaitOpened, 1, [
                'condition_wait_id' => 'open-wait',
                'condition_key' => 'terminal-test',
                'sequence' => 1,
            ]),
            $this->historyEvent($eventType, 2),
        ]);

        $wait = ConditionWaits::forRun($run)[0];

        $this->assertSame('cancelled', $wait['status']);
        $this->assertSame($sourceStatus, $wait['source_status']);
    }

    public function testConditionWaitReplayCompatibilityUsesDurableDefinitionIdentity(): void
    {
        $unrecorded = $this->runWithHistoryEvents([$this->historyEvent(HistoryEventType::WorkflowStarted, 1)]);
        ConditionWaits::assertReplayCompatible($unrecorded, 7, new AwaitCall(static fn (): bool => true));

        $recorded = $this->runWithHistoryEvents([
            $this->historyEvent(HistoryEventType::TimerCancelled, 1, [
                'timer_kind' => 'condition_timeout',
                'sequence' => '7',
                'condition_key' => 'inventory-ready',
                'condition_definition_fingerprint' => 'predicate-v1',
            ]),
            $this->historyEvent(HistoryEventType::TimerCancelled, 2, [
                'timer_kind' => 'signal_timeout',
                'sequence' => 8,
                'condition_key' => 'ignored-signal-timer',
            ]),
        ]);

        ConditionWaits::assertReplayCompatible(
            $recorded,
            7,
            new AwaitWithTimeoutCall(30, static fn (): bool => true, 'inventory-ready', 'predicate-v1'),
        );
        ConditionWaits::assertReplayCompatible(
            $recorded,
            7,
            new AwaitCall(static fn (): bool => true, 'inventory-ready'),
        );
        $this->assertSame('inventory-ready', ConditionWaits::conditionKeyForSequence($recorded, 7));
        $this->assertNull(ConditionWaits::conditionKeyForSequence($recorded, 8));

        try {
            ConditionWaits::assertReplayCompatible(
                $recorded,
                7,
                new AwaitCall(static fn (): bool => true, 'inventory-changed', 'predicate-v1'),
            );
            $this->fail('A changed condition key must fail replay compatibility.');
        } catch (ConditionWaitDefinitionMismatchException $exception) {
            $this->assertSame(7, $exception->workflowSequence);
            $this->assertSame('inventory-ready', $exception->recordedConditionKey);
            $this->assertSame('inventory-changed', $exception->currentConditionKey);
        }

        try {
            ConditionWaits::assertReplayCompatible(
                $recorded,
                7,
                new AwaitCall(static fn (): bool => true, 'inventory-ready', 'predicate-v2'),
            );
            $this->fail('A changed condition predicate must fail replay compatibility.');
        } catch (ConditionWaitDefinitionMismatchException $exception) {
            $this->assertSame('predicate-v1', $exception->recordedConditionDefinitionFingerprint);
            $this->assertSame('predicate-v2', $exception->currentConditionDefinitionFingerprint);
        }
    }

    public function testSignalWaitProjectionReconstructsSignalsTimeoutsAndCancellations(): void
    {
        $run = $this->runWithHistoryEvents([
            new \stdClass(),
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 1, [
                'signal_wait_id' => 'missing-name',
            ]),
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 2, [
                'signal_name' => 'missing-identity',
            ]),
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 3, [
                'signal_wait_id' => 'approval-first',
                'signal_name' => 'approval',
                'sequence' => '1',
                'timeout_seconds' => '60',
            ], '2026-08-27T13:00:00Z'),
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 4, [
                'signal_name' => 'approval',
                'sequence' => 2,
            ], '2026-08-27T13:00:01Z'),
            $this->historyEvent(HistoryEventType::TimerScheduled, 5, [
                'timer_kind' => 'signal_timeout',
                'signal_name' => 'approval',
                'signal_wait_id' => 'approval-first',
                'timer_id' => 'approval-timer',
                'delay_seconds' => '45',
                'fire_at' => Carbon::parse('2026-08-27T13:00:45Z'),
            ]),
            $this->historyEvent(HistoryEventType::SignalReceived, 6, [
                'signal_name' => 'approval',
                'signal_wait_id' => 'approval-first',
                'signal_id' => 'signal-received',
                'command' => [
                    'sequence' => '7',
                    'status' => 'pending',
                    'outcome' => 'queued',
                ],
            ], '2026-08-27T13:00:10Z', 'command-received'),
            $this->historyEvent(HistoryEventType::SignalApplied, 7, [
                'signal_name' => 'approval',
                'signal_wait_id' => 'approval-first',
                'signal_id' => 'signal-applied',
                'workflow_command_id' => 'command-applied',
                'outcome' => 'signal_applied',
                'command' => [
                    'sequence' => 8,
                ],
            ], '2026-08-27T13:00:11Z'),
            $this->historyEvent(HistoryEventType::SignalApplied, 8, [
                'signal_name' => 'approval',
                'signal_id' => 'signal-second',
                'command' => [
                    'id' => 'command-snapshot',
                    'sequence' => 9,
                ],
            ], '2026-08-27T13:00:12Z'),
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 9, [
                'signal_wait_id' => 'timeout-wait',
                'signal_name' => 'timeout-signal',
                'sequence' => 3,
            ]),
            $this->historyEvent(HistoryEventType::TimerScheduled, 10, [
                'timer_kind' => 'signal_timeout',
                'signal_name' => 'timeout-signal',
                'signal_wait_id' => 'timeout-wait',
                'timer_id' => 'timeout-timer',
                'timeout_seconds' => 20,
                'fire_at' => '2026-08-27T13:01:00Z',
            ]),
            $this->historyEvent(HistoryEventType::TimerFired, 11, [
                'timer_kind' => 'signal_timeout',
                'signal_name' => 'timeout-signal',
                'timer_id' => 'timeout-timer',
            ], '2026-08-27T13:01:01Z'),
            $this->historyEvent(HistoryEventType::SignalApplied, 12, [
                'signal_name' => 'timeout-signal',
                'signal_wait_id' => 'timeout-wait',
            ]),
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 13, [
                'signal_wait_id' => 'cancelled-timeout',
                'signal_name' => 'cancel-signal',
                'sequence' => 4,
            ]),
            $this->historyEvent(HistoryEventType::TimerScheduled, 14, [
                'timer_kind' => 'signal_timeout',
                'signal_name' => 'cancel-signal',
                'signal_wait_id' => 'cancelled-timeout',
                'timer_id' => 'cancel-timer',
            ]),
            $this->historyEvent(HistoryEventType::TimerCancelled, 15, [
                'timer_kind' => 'signal_timeout',
                'signal_name' => 'cancel-signal',
                'timer_id' => 'cancel-timer',
            ]),
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 16, [
                'signal_wait_id' => 'selection-wait',
                'signal_name' => 'selection-signal',
                'sequence' => 10,
            ]),
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 17, [
                'signal_wait_id' => 'terminal-wait',
                'signal_name' => 'terminal-signal',
                'sequence' => 20,
            ]),
            $this->historyEvent(HistoryEventType::SelectionOperationCancelled, 18, [
                'member_base_sequence' => 10,
                'member_size' => 0,
            ]),
            $this->historyEvent(HistoryEventType::SelectionOperationCancelled, 19, [
                'member_base_sequence' => '10',
                'member_size' => '1',
            ], '2026-08-27T13:02:00Z'),
            $this->historyEvent(HistoryEventType::TimerScheduled, 20, [
                'timer_kind' => 'workflow_timer',
                'signal_name' => 'terminal-signal',
            ]),
            $this->historyEvent(HistoryEventType::TimerFired, 21, [
                'timer_kind' => 'signal_timeout',
            ]),
            $this->historyEvent(HistoryEventType::TimerScheduled, 22, [
                'timer_kind' => 'signal_timeout',
                'signal_name' => 'terminal-signal',
            ]),
            $this->historyEvent(HistoryEventType::SignalReceived, 23, [
                'signal_name' => 'missing-wait',
                'signal_wait_id' => 'does-not-exist',
            ]),
            $this->historyEvent(HistoryEventType::WorkflowCancelled, 24, [], '2026-08-27T13:03:00Z'),
        ]);

        $waits = $this->indexBy(SignalWaits::forRun($run), 'signal_wait_id');

        $this->assertCount(6, $waits);
        $this->assertSame('signal-command:command-1', SignalWaits::bufferedWaitIdForCommandId('command-1'));

        $first = $waits['approval-first'];
        $this->assertSame('resolved', $first['status']);
        $this->assertSame('applied', $first['source_status']);
        $this->assertSame('signal-applied', $first['signal_id']);
        $this->assertSame(1, $first['sequence']);
        $this->assertSame(45, $first['timeout_seconds']);
        $this->assertSame('approval-timer', $first['timer_id']);
        $this->assertSame('command-applied', $first['command_id']);
        $this->assertSame(8, $first['command_sequence']);
        $this->assertSame('accepted', $first['command_status']);
        $this->assertSame('signal_applied', $first['command_outcome']);
        $this->assertSame('2026-08-27T13:00:45+00:00', $this->timestamp($first['deadline_at']));
        $this->assertSame('2026-08-27T13:00:11+00:00', $this->timestamp($first['resolved_at']));

        $second = $waits['signal:2:approval'];
        $this->assertSame('resolved', $second['status']);
        $this->assertSame('applied', $second['source_status']);
        $this->assertSame('command-snapshot', $second['command_id']);
        $this->assertSame('signal_received', $second['command_outcome']);

        $timeout = $waits['timeout-wait'];
        $this->assertSame('resolved', $timeout['status']);
        $this->assertSame('timed_out', $timeout['source_status']);
        $this->assertSame('timeout-timer', $timeout['timer_id']);
        $this->assertSame('2026-08-27T13:01:01+00:00', $this->timestamp($timeout['timeout_fired_at']));

        $cancelledTimeout = $waits['cancelled-timeout'];
        $this->assertSame('cancelled', $cancelledTimeout['status']);
        $this->assertSame('timeout_cancelled', $cancelledTimeout['source_status']);
        $this->assertNull($cancelledTimeout['timeout_fired_at']);

        $this->assertSame('cancelled', $waits['selection-wait']['status']);
        $this->assertSame('selection_cancelled', $waits['selection-wait']['source_status']);
        $this->assertSame('cancelled', $waits['terminal-wait']['status']);
        $this->assertSame('cancelled', $waits['terminal-wait']['source_status']);
    }

    public function testOpenSignalWaitLookupUsesSequenceTimeAndIdentityOrdering(): void
    {
        $run = $this->runWithHistoryEvents([
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 1, [
                'signal_wait_id' => 'alpha',
                'signal_name' => 'approval',
            ], '2026-08-27T14:00:00Z'),
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 2, [
                'signal_wait_id' => 'beta',
                'signal_name' => 'approval',
            ], '2026-08-27T14:00:01Z'),
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 3, [
                'signal_wait_id' => 'sequence-two-early',
                'signal_name' => 'approval',
                'sequence' => 2,
            ], '2026-08-27T14:00:00Z'),
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 4, [
                'signal_wait_id' => 'sequence-two-late-a',
                'signal_name' => 'approval',
                'sequence' => 2,
            ], '2026-08-27T14:00:02Z'),
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 5, [
                'signal_wait_id' => 'sequence-two-late-z',
                'signal_name' => 'approval',
                'sequence' => 2,
            ], '2026-08-27T14:00:02Z'),
        ]);

        $this->assertSame('sequence-two-late-z', SignalWaits::openWaitIdForName($run, 'approval'));
        $this->assertNull(SignalWaits::openWaitIdForName($run, 'missing'));
    }

    #[DataProvider('terminalEventProvider')]
    public function testSignalWaitProjectionClosesOpenWaitsWithTerminalSourceStatus(
        HistoryEventType $eventType,
        string $sourceStatus,
    ): void {
        $run = $this->runWithHistoryEvents([
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 1, [
                'signal_wait_id' => 'open-wait',
                'signal_name' => 'terminal-test',
                'sequence' => 1,
            ]),
            $this->historyEvent($eventType, 2),
        ]);

        $wait = SignalWaits::forRun($run)[0];

        $this->assertSame('cancelled', $wait['status']);
        $this->assertSame($sourceStatus, $wait['source_status']);
    }

    /**
     * @return iterable<string, array{HistoryEventType, string}>
     */
    public static function terminalEventProvider(): iterable
    {
        yield 'completed' => [HistoryEventType::WorkflowCompleted, 'closed'];
        yield 'failed' => [HistoryEventType::WorkflowFailed, 'closed'];
        yield 'cancelled' => [HistoryEventType::WorkflowCancelled, 'cancelled'];
        yield 'terminated' => [HistoryEventType::WorkflowTerminated, 'terminated'];
        yield 'continued as new' => [HistoryEventType::WorkflowContinuedAsNew, 'continued'];
    }

    /**
     * @param list<mixed> $events
     */
    private function runWithHistoryEvents(array $events): WorkflowRun
    {
        $run = new WorkflowRun();
        $run->setRelation('historyEvents', new EloquentCollection($events));

        return $run;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function historyEvent(
        HistoryEventType $type,
        int $order,
        array $payload = [],
        ?string $recordedAt = null,
        ?string $commandId = null,
    ): WorkflowHistoryEvent {
        $event = new WorkflowHistoryEvent();
        $event->forceFill([
            'sequence' => $order,
            'event_type' => $type->value,
            'payload' => $payload,
            'workflow_command_id' => $commandId,
            'recorded_at' => $recordedAt,
        ]);

        return $event;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexBy(array $rows, string $key): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[$row[$key]] = $row;
        }

        return $indexed;
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof \Carbon\CarbonInterface
            ? $value->toIso8601String()
            : null;
    }
}
