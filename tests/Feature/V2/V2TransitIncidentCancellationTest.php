<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use Tests\Support\TransitIncidentFixture;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * SHP-07/SHP-08: the carrier confirms loss or transport damage of the original parcel before recipient
 * handover. A separate native incident settles the trusted remedy, files the carrier claim and recovers
 * damaged goods. Synthetic recorded-task fixture, not installed-package, real-carrier or warehouse qualification.
 *
 * Damaged-parcel recovery, and cancellation refused during a pending replacement or a processing refund. The scenarios are split across V2TransitIncident*Test
 * files so each fits one feature shard.
 */
final class V2TransitIncidentCancellationTest extends TestCase
{
    use TransitIncidentFixture;

    public function testDamagedParcelIsRefundedBeforeItsRecoveredGoodsAreInspectedAndOnlySaleableUnitsRestocked(): void
    {
        [$origin, $shipment] = $this->handedOver();
        $commands = $this->planIncident('damaged', saleable: [0]);
        $this->record('damaged');
        $this->hint($shipment, 'original-damage');
        $this->current();

        // The remedy settled while the damaged goods are still with the carrier.
        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertSame([1499], $this->payouts());
        $recovered = [...self::DELIVERED, 'carrier-claim', 'recover-damaged-units'];
        $this->assertSame($recovered, $this->effects());
        $this->assertNull($this->incidentRow()->outcome);
        $this->warehouse($commands['recover'], 'received', [0, 1, 2]);
        $this->hintIncident('recovery-receipt');
        $this->assertSame($recovered, $this->effects(), 'Receipt alone does not make goods saleable.');
        $this->warehouse($commands['recover'], 'inspected', [0, 1, 2], [0, 1], [0]);
        $this->hintIncident('recovery-inspection');

        $this->assertSame([...$recovered, 'restock-units'], $this->effects(), $this->progress());
        $outcome = $this->incidentOutcome();
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('damaged', $outcome['incident']);
        $this->assertSame('refunded', $outcome['resolution']);
        $this->assertSame([0, 1, 2], $outcome['receivedUnits']);
        $this->assertSame([0, 1], $outcome['acceptedUnits']);
        $this->assertSame([0], $outcome['saleableUnits']);
        $this->assertSame([0], $outcome['restockedUnits']);
        $this->assertSame($commands['recover']->identity->key(), $outcome['recovery']);
        $this->assertTrue($this->physicalFacts()['transport_damage_confirmed']);
        $this->assertSame(0, $this->physicalFacts()['restocked_quantity']);
        $this->assertTrue($this->incidentRun()->status->isTerminal());
        // A later cancellation is refused, so the full-parcel return cannot restock the recovered units again.
        $this->assertCancellationRefused('late-cancellation', 'settled');
        $this->drain();
        $this->assertSame([...$recovered, 'restock-units'], $this->effects());
        $this->assertSame([1499], $this->payouts());
    }

    public function testCancellationDuringAPendingReplacementIsRefusedAndTheReplacementStillSettles(): void
    {
        [$origin, $shipment] = $this->handedOver();
        $commands = $this->planIncident('lost');
        $this->record('lost');
        $this->hint($shipment, 'original-loss');
        $this->current();
        $this->assertSame([...self::DELIVERED, 'reserve', 'ship-book'], $this->effects(), $this->progress());
        $shipmentHistory = $this->history($shipment->id);

        // The booked replacement is the pending remedy: the Order's cancellation route stays closed.
        $this->assertCancellationRefused('cancel-pending-replacement', 'pending');
        $this->drain();
        $this->assertSame('ready', $this->subjectState('order'), $this->progress());
        $this->assertFalse(WorkflowRun::query()->findOrFail($origin->runId())->status->isTerminal());
        $this->replacementCarrier($commands, 'handed-over');
        $this->replacementCarrier($commands, 'delivered');
        $this->hintIncident('replacement-delivery');

        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertSame(
            'remedied',
            WorkflowRun::query()->findOrFail($origin->runId())->workflowOutput()['businessOutcome']
        );
        $this->assertSame('replaced', $this->incidentOutcome()['resolution']);
        $this->assertSame([], $this->payouts(), 'The delivered replacement is the only remedy.');
        $this->assertSame([...self::DELIVERED, 'reserve', 'ship-book', 'carrier-claim'], $this->effects());
        $this->assertSame(0, $this->physicalFacts()['restocked_quantity']);
        $this->assertSame($shipmentHistory, $this->history($shipment->id));
        $this->assertCancellationRefused('cancel-after-replacement', 'settled');
        $this->assertIncidentReplayQuiet($origin->runId(), $shipment->id);
    }

    public function testCancellationDuringAProcessingRefundIsRefusedAndTheRefundPaysOnce(): void
    {
        [$origin, $shipment] = $this->handedOver();
        $commands = $this->planIncident('lost', refund: Status::Processing);
        $this->record('lost');
        $this->hint($shipment, 'original-loss');
        $this->current();
        // The refund is processing: the incident keeps waiting for its outcome and files no claim yet.
        $this->assertSame(self::DELIVERED, $this->effects(), $this->progress());
        $this->assertSame([], $this->payouts());
        $this->assertNull($this->incidentRow()->remedy);
        $this->assertSame('ready', $this->subjectState('order'));
        $shipmentHistory = $this->history($shipment->id);

        $this->assertCancellationRefused('cancel-processing-refund', 'pending');
        $this->drain();
        $this->assertSame([], $this->payouts());
        $this->unitRefunds->resolve($commands['refund'], Status::Confirmed);
        $this->hintIncident('refund-confirmed');

        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertSame([1499], $this->payouts());
        $outcome = $this->incidentOutcome();
        $this->assertSame('refunded', $outcome['resolution']);
        $this->assertSame(1499, $outcome['refundPayoutMinor']);
        $this->assertSame([...self::DELIVERED, 'carrier-claim'], $this->effects());
        $this->assertSame(0, $this->physicalFacts()['restocked_quantity']);
        $this->assertSame($shipmentHistory, $this->history($shipment->id));
        $this->assertCancellationRefused('cancel-after-refund', 'settled');
        $this->assertSame([1499], $this->payouts());
        $this->assertIncidentReplayQuiet($origin->runId(), $shipment->id);
    }
}
