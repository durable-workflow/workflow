<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\CooperativeCancellationDelivery;
use Workflow\V2\Support\ParallelChildGroup;

final class CooperativeCancellationDeliveryTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository());
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testPersistedRequestRequiresItsMatchingCanonicalHistory(): void
    {
        $run = $this->runWithHistory([]);
        $this->assertSame('cancellation_request_history_missing', $this->validate($run));
    }

    public function testASequenceHoleCannotBeUsedToMoveDeliveryEarlier(): void
    {
        $run = $this->runWithHistory([
            $this->event(HistoryEventType::TimerScheduled, [
                'sequence' => 2,
            ]),
            $this->request(),
        ]);
        $this->assertSame('cancellation_delivery_shape_mismatch', $this->validate($run, 1, 'timer'));
    }

    public function testControlPlaneHistoryPositionsDoNotChooseTheCallSequence(): void
    {
        $request = $this->request();
        $request->sequence = 500;
        $run = $this->runWithHistory([$request]);
        $this->assertNull($this->validate($run));
        $this->assertSame('cancellation_delivery_sequence_mismatch', $this->validate($run, 2));
    }

    #[DataProvider('scalarCallKinds')]
    public function testUnscheduledAuthoredCallsCanReceiveThePendingRequest(string $kind): void
    {
        $this->assertNull($this->validate($this->runWithHistory([$this->request()]), 1, $kind));
    }

    public static function scalarCallKinds(): iterable
    {
        foreach (['activity', 'local_activity', 'timer', 'condition', 'signal', 'child'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function testRecordedDeliveryCannotBeMovedOrRelabelled(): void
    {
        $run = $this->runWithHistory([
            $this->request(),
            $this->event(HistoryEventType::CooperativeCancellationDelivered, [
                'workflow_command_id' => 'request-1',
                'sequence' => 1,
                'call_kind' => 'parallel',
                'sequence_span' => 2,
            ]),
        ]);
        $run->cancellation_delivery_sequence = 1;
        $this->assertNull(CooperativeCancellationDelivery::validate($run, 'request-1', 1, 'parallel', 2));
        $this->assertSame(
            'cancellation_delivery_mismatch',
            CooperativeCancellationDelivery::validate($run, 'request-1', 1, 'parallel', 3)
        );
        $this->assertSame('cancellation_delivery_mismatch', $this->validate($run, 1, 'activity'));
        $this->assertSame(
            'cancellation_delivery_mismatch',
            CooperativeCancellationDelivery::validate($run, 'request-1', 2, 'parallel', 2)
        );
        $run->cancellation_delivery_sequence = 2;
        $this->assertSame(
            'cancellation_delivery_mismatch',
            CooperativeCancellationDelivery::validate($run, 'request-1', 1, 'parallel', 2)
        );
    }

    public function testDeliverySnapshotWithoutHistoryIsRefused(): void
    {
        $run = $this->runWithHistory([$this->request()]);
        $run->cancellation_delivery_sequence = 1;
        $this->assertSame('cancellation_delivery_history_missing', $this->validate($run));
    }

    public function testRecordedScalarShapesRemainDistinct(): void
    {
        $run = $this->runWithHistory([
            $this->event(HistoryEventType::ActivityScheduled, [
                'sequence' => 1,
                'local_activity' => true,
            ]),
            $this->request(),
        ]);
        $this->assertNull($this->validate($run, 1, 'local_activity'));
        $this->assertSame('cancellation_delivery_shape_mismatch', $this->validate($run));
        $this->assertSame('local_activity', CooperativeCancellationDelivery::callKindAt($run, 1));
        $this->assertNull(CooperativeCancellationDelivery::callKindAt($run, 2));
    }

    #[DataProvider('parallelResultOrdering')]
    public function testParallelBarrierPreservesItsRecordedResolution(
        ?HistoryEventType $firstResult,
        bool $secondCompleted,
        ?string $expected,
    ): void {
        $events = $this->parallelEvents();
        if ($firstResult !== null) {
            $events[] = $this->event($firstResult, [
                'sequence' => 1,
            ]);
        }
        if ($secondCompleted) {
            $events[] = $this->event(HistoryEventType::ActivityCompleted, [
                'sequence' => 2,
            ]);
        }
        $events[] = $this->request();
        $run = $this->runWithHistory($events);
        $this->assertSame($expected, CooperativeCancellationDelivery::validate($run, 'request-1', 1, 'parallel', 2));
    }

    public static function parallelResultOrdering(): iterable
    {
        yield 'both pending' => [null, false, null];
        yield 'first success and second pending' => [HistoryEventType::ActivityCompleted, false, null];
        yield 'both succeeded before request' => [
            HistoryEventType::ActivityCompleted, true, 'cancellation_delivery_not_eligible',
        ];
        yield 'prior failure wins' => [HistoryEventType::ActivityFailed, false, 'cancellation_delivery_not_eligible'];
        yield 'prior timeout wins' => [HistoryEventType::ActivityTimedOut, false, 'cancellation_delivery_not_eligible'];
    }

    public function testParallelDeliveryCannotClaimDifferentRecordedMembership(): void
    {
        $events = $this->parallelEvents();
        $events[] = $this->request();
        $run = $this->runWithHistory($events);
        $this->assertSame(
            'cancellation_delivery_shape_mismatch',
            CooperativeCancellationDelivery::validate($run, 'request-1', 1, 'parallel', 3)
        );
        $events[1]->payload = [
            'sequence' => 2,
            ...ParallelChildGroup::itemMetadata(2, 2, 0, 'activity'),
        ];
        $this->assertSame(
            'cancellation_delivery_shape_mismatch',
            CooperativeCancellationDelivery::validate($run, 'request-1', 1, 'parallel', 2)
        );
    }

    public function testASelectionHandleCanInterruptItsNestedParallelMemberAfterAnotherMemberWins(): void
    {
        $events = [];
        for ($index = 0; $index < 2; ++$index) {
            $events[] = $this->event(HistoryEventType::ActivityScheduled, [
                'sequence' => $index + 1,
                ...ParallelChildGroup::payloadForPath([
                    ParallelChildGroup::groupEntry(1, 3, $index, 'activity', 'select', 'batch', 0, 1, 2, 'group'),
                    ParallelChildGroup::groupEntry(1, 2, $index, 'activity'),
                ]),
            ]);
        }
        $events[] = $this->event(HistoryEventType::TimerScheduled, [
            'sequence' => 3,
            ...ParallelChildGroup::payloadForPath([
                ParallelChildGroup::groupEntry(1, 3, 2, 'activity', 'select', 'timeout', 1, 3, 1, 'timer'),
            ]),
        ]);
        $events[] = $this->event(HistoryEventType::SelectionResolved, [
            'selection_group_id' => 'select-calls:1:3',
        ]);
        $events[] = $this->request();
        $this->assertNull(CooperativeCancellationDelivery::validate(
            $this->runWithHistory($events),
            'request-1',
            4,
            'selection_handle',
            1,
            1,
            2,
        ));
    }

    public function testASelectionOperationCancelledBeforeTheRequestIsAlreadyResolved(): void
    {
        $events = $this->parallelEvents();
        $events[] = $this->event(HistoryEventType::SelectionOperationCancelled, [
            'member_base_sequence' => 1,
            'member_size' => 2,
        ]);
        $events[] = $this->request();
        $this->assertSame('cancellation_delivery_not_eligible', CooperativeCancellationDelivery::validate(
            $this->runWithHistory($events),
            'request-1',
            3,
            'selection_handle',
            1,
            1,
            2,
        ));
    }

    public function testSelectedWinnerBeforeRequestReplaysWithoutNewDelivery(): void
    {
        $events = [];
        for ($index = 0; $index < 2; ++$index) {
            $path = [
                ParallelChildGroup::groupEntry(
                    1,
                    2,
                    $index,
                    'mixed',
                    'select',
                    $index,
                    $index,
                    $index + 1,
                    1,
                    'activity'
                ),
            ];
            $events[] = $this->event(HistoryEventType::ActivityScheduled, [
                'sequence' => $index + 1,
                ...ParallelChildGroup::payloadForPath($path),
            ]);
        }
        $events[] = $this->event(HistoryEventType::SelectionResolved, [
            'selection_group_id' => 'select-calls:1:2',
        ]);
        $events[] = $this->request();
        $this->assertSame(
            'cancellation_delivery_not_eligible',
            CooperativeCancellationDelivery::validate($this->runWithHistory($events), 'request-1', 1, 'parallel', 2)
        );
    }

    public function testSelectionHandleBindsItsExistingOperationRange(): void
    {
        $run = $this->runWithHistory([...$this->parallelEvents(), $this->request()]);
        $this->assertNull(CooperativeCancellationDelivery::validate($run, 'request-1', 3, 'selection_handle', 1, 1, 2));
        $this->assertSame(
            'cancellation_delivery_sequence_mismatch',
            CooperativeCancellationDelivery::validate($run, 'request-1', 3, 'selection_handle', 1, 2, 2)
        );
        $this->assertSame(
            'invalid_cancellation_delivery',
            CooperativeCancellationDelivery::validate($run, 'request-1', 3, 'selection_handle', 1, 1, 0)
        );
        $this->assertSame(
            'invalid_cancellation_delivery',
            CooperativeCancellationDelivery::validate($run, 'request-1', 3, 'timer', 1, null, 2)
        );
    }

    /**
     * @return list<WorkflowHistoryEvent>
     */
    private function parallelEvents(): array
    {
        return [
            $this->event(HistoryEventType::ActivityScheduled, [
                'sequence' => 1,
                ...ParallelChildGroup::itemMetadata(1, 2, 0, 'activity'),
            ]),
            $this->event(HistoryEventType::ActivityScheduled, [
                'sequence' => 2,
                ...ParallelChildGroup::itemMetadata(1, 2, 1, 'activity'),
            ]),
        ];
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private function runWithHistory(array $events): WorkflowRun
    {
        foreach ($events as $index => $event) {
            if ($event->sequence === null) {
                $event->sequence = $index + 1;
            }
        }
        $run = new WorkflowRun([
            'cancellation_request_command_id' => 'request-1',
        ]);
        $run->setRelation('historyEvents', new Collection($events));

        return $run;
    }

    private function request(): WorkflowHistoryEvent
    {
        return $this->event(HistoryEventType::CooperativeCancellationRequested, [
            'workflow_command_id' => 'request-1',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(HistoryEventType $type, array $payload): WorkflowHistoryEvent
    {
        return new WorkflowHistoryEvent([
            'event_type' => $type,
            'payload' => $payload,
            'workflow_command_id' => $type === HistoryEventType::CooperativeCancellationRequested
                || $type === HistoryEventType::CooperativeCancellationDelivered ? 'request-1' : null,
        ]);
    }

    private function validate(WorkflowRun $run, int $sequence = 1, string $kind = 'activity'): ?string
    {
        return CooperativeCancellationDelivery::validate($run, 'request-1', $sequence, $kind);
    }
}
