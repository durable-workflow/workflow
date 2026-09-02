<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Support\HistoryEventPayloadContract;

final class HistoryEventPayloadContractTest extends TestCase
{
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
