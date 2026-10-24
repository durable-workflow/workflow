<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Support\HistoryEventPayloadContract;

final class HistoryEventPayloadContractTest extends TestCase
{
    public function testRejectedOutcomeReaderRecognizesOnlyBoundDiagnosticFields(): void
    {
        $type = HistoryEventType::from('ActivityOutcomeRejected');
        HistoryEventPayloadContract::assertKnownPayloadKeys($type, [
            'activity_execution_id' => 'activity-one',
            'activity_attempt_id' => 'attempt-one',
            'attempt_number' => 1,
            'lease_owner' => 'original-worker',
            'outcome' => 'completed',
            'reason' => 'lease_expired',
            'observed_at' => '2026-10-24T01:00:00.000000Z',
        ]);

        $this->assertSame('ActivityOutcomeRejected', $type->value);
        $this->assertNull(HistoryEventType::tryFrom('UnrecognizedOutcome'));
    }

    #[DataProvider('rejectedOutcomePrivateFields')]
    public function testRejectedOutcomeContractDoesNotAllowBusinessPayloadsOrTaskSnapshots(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('undocumented key(s): ' . $field);

        HistoryEventPayloadContract::assertKnownPayloadKeys(
            HistoryEventType::from('ActivityOutcomeRejected'),
            [
                $field => 'must-not-be-retained',
            ],
        );
    }

    public static function rejectedOutcomePrivateFields(): array
    {
        return [
            ['result'], ['message'], ['stack_trace'], ['external_reference'],
            ['task'], ['command'], ['sequence'],
        ];
    }

    public function testEveryHistoryEventTypeHasAPayloadContractEntry(): void
    {
        $registered = array_keys(HistoryEventPayloadContract::payloadKeys());
        $eventTypes = array_map(
            static fn (HistoryEventType $eventType): string => $eventType->value,
            HistoryEventType::cases(),
        );

        sort($registered);
        sort($eventTypes);

        $this->assertSame($eventTypes, $registered);
    }

    public function testPayloadContractRejectsUnknownProducerKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WorkflowCompleted history payload contains undocumented key(s): surprise');

        HistoryEventPayloadContract::assertKnownPayloadKeys(HistoryEventType::WorkflowCompleted, [
            'output' => 'ok',
            'surprise' => true,
        ]);
    }

    public function testSelectionResolutionAcceptsDurableWinnerMetadata(): void
    {
        HistoryEventPayloadContract::assertKnownPayloadKeys(HistoryEventType::SelectionResolved, [
            'selection_group_id' => 'select-calls:3:2',
            'selection_group_base_sequence' => 3,
            'selection_group_size' => 2,
            'member_key' => 'deadline',
            'member_index' => 1,
            'member_base_sequence' => 4,
            'member_size' => 1,
            'operation_kind' => 'timer',
            'operation_identity' => 'timer-42',
            'outcome' => 'completed',
            'resolution_event_id' => 'event-42',
            'resolution_event_type' => HistoryEventType::TimerFired->value,
        ]);

        $this->addToAssertionCount(1);
    }
}
