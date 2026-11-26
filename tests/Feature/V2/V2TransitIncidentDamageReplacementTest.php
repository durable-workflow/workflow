<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ProviderStateMachines\IncidentStore;
use DurableWorkflow\Examples\ProviderStateMachines\TransitPolicy;
use Tests\Support\TransitIncidentFixture;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * SHP-08: the carrier confirms transport damage of the original parcel before recipient handover, and the
 * trusted remedy policy dispatches a replacement. The replacement uses the same path as a carrier-confirmed
 * loss (SHP-07); the carrier claim and the damaged-goods recovery stay separate from the customer remedy.
 * Synthetic recorded-task fixture, not installed-package, real-carrier or warehouse qualification.
 *
 * The scenarios are split across V2TransitIncident*Test files so each fits one feature shard.
 */
final class V2TransitIncidentDamageReplacementTest extends TestCase
{
    use TransitIncidentFixture;

    public function testDamagedParcelReplacementIsDeliveredWhileTheClaimAndRecoveryStaySeparate(): void
    {
        [$origin, $shipment] = $this->handedOver();
        $key = $this->terms->subjectKey();
        $this->assertSame(TransitPolicy::REPLACE, $this->transitPolicy());
        $commands = $this->planIncident('damaged', saleable: [0]);
        $this->record('damaged');
        $this->hint($shipment, 'original-damage');
        $this->current();

        // The replacement is reserved and booked; neither the claim nor the recovery runs before the remedy.
        $booked = [...self::DELIVERED, 'reserve', 'ship-book'];
        $this->assertSame($booked, $this->effects(), $this->progress());
        $this->assertSame('ready', $this->subjectState('order'), 'A booked replacement is not a settled remedy.');
        $this->assertSame('in-transit', $this->subjectState('shipment'));
        $this->assertSame('damaged', $shipment->fresh()->workflowOutput()['businessOutcome']);
        $this->assertNull($this->incidentRow()->remedy);
        $this->assertSame([], $this->payouts());
        $shipmentHistory = $this->history($shipment->id);

        // The booked replacement is the pending remedy: cancellation is refused without intent, fence or effect.
        $this->assertCancellationRefused('cancel-pending-damage-replacement', 'pending');
        $this->drain();
        $this->assertSame($booked, $this->effects());
        $this->assertSame('ready', $this->subjectState('order'), $this->progress());
        $this->assertFalse(WorkflowRun::query()->findOrFail($origin->runId())->status->isTerminal());

        $this->replacementCarrier($commands, 'handed-over');
        $this->hintIncident('replacement-handover');
        $this->assertSame('ready', $this->subjectState('order'));
        $this->assertSame($booked, $this->effects());
        $this->replacementCarrier($commands, 'delivered');
        $this->hintIncident('replacement-delivery');

        // The delivered replacement settles the remedy; then the claim is filed and the damaged goods recalled.
        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $recovered = [...$booked, 'carrier-claim', 'recover-damaged-units'];
        $this->assertSame($recovered, $this->effects(), $this->progress());
        $this->assertSame([], $this->payouts(), 'A delivered replacement moves no money.');
        $this->assertNull($this->incidentRow()->outcome, 'The recovery is still waiting for the warehouse.');
        $this->assertCancellationRefused('cancel-during-damage-recovery', 'settled');
        $this->warehouse($commands['recover'], 'received', [0, 1, 2]);
        $this->hintIncident('recovery-receipt');
        $this->assertSame($recovered, $this->effects(), 'Receipt alone does not make goods saleable.');
        $this->warehouse($commands['recover'], 'inspected', [0, 1, 2], [0, 1], [0]);
        $this->hintIncident('recovery-inspection');

        $this->assertSame([...$recovered, 'restock-units'], $this->effects(), $this->progress());
        $this->assertTrue($this->incidentRun()->status->isTerminal());
        $outcome = $this->incidentOutcome();
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('damaged', $outcome['incident']);
        $this->assertSame(TransitPolicy::REPLACE, $outcome['policy']);
        $this->assertSame('replaced', $outcome['resolution']);
        $this->assertNull($outcome['replacementFailure']);
        $this->assertSame([], $outcome['refundedUnits']);
        $this->assertSame(0, $outcome['refundedAmountMinor']);
        $this->assertSame('confirmed', $outcome['claimStatus']);
        $this->assertSame([0, 1, 2], $outcome['receivedUnits']);
        $this->assertSame([0, 1], $outcome['acceptedUnits']);
        $this->assertSame([0], $outcome['saleableUnits']);
        $this->assertSame([0], $outcome['restockedUnits'], 'Only accepted saleable recovered units are restocked.');
        $identities = [
            $outcome['originalBooking'],
            $outcome['replacementReservation'],
            $outcome['replacementBooking'],
            $outcome['claim'],
            $outcome['recovery'],
        ];
        $this->assertSame([
            $this->graph()
                ->booking->identity->key(),
            $commands['reserve']->identity->key(),
            $commands['graph']->booking->identity->key(),
            $commands['claim']->identity->key(),
            $commands['recover']->identity->key(),
        ], $identities);
        $this->assertCount(5, array_unique($identities));
        // The damage replacement has its own identities, distinct from a loss replacement of the same Order.
        $lost = app(IncidentStore::class)->commands($key, 'lost');
        $this->assertNotSame($lost['reserve']->identity->key(), $outcome['replacementReservation']);
        $this->assertNotSame($lost['graph']->booking->identity->key(), $outcome['replacementBooking']);
        // The original handover and deduction stay historical; nothing on the original Shipment is restocked.
        $this->assertTrue($this->physicalFacts()['transport_damage_confirmed']);
        $this->assertFalse($this->physicalFacts()['original_recipient_delivered']);
        $this->assertSame(0, $this->physicalFacts()['restocked_quantity']);
        $this->assertSame($shipmentHistory, $this->history($shipment->id));
        $this->assertSame(
            'remedied',
            WorkflowRun::query()->findOrFail($origin->runId())->workflowOutput()['businessOutcome']
        );

        // The carrier rejects the claim later; the settled customer outcome does not change.
        $this->provider->shipments()
            ->recordClaimOutcome($commands['claim'], 'rejected', 0);
        $claim = app(IncidentStore::class)->claim($key);
        $this->assertSame($commands['claim']->identity->key(), $claim['claim']);
        $this->assertSame('rejected', $claim['carrierDecision']['outcome']);
        $this->assertSame($outcome, $this->incidentOutcome());

        // A later cancellation is refused: no refund, full-parcel return or second restock of recovered units.
        $this->assertCancellationRefused('late-cancellation', 'settled');
        $this->drain();
        $this->assertSame([...$recovered, 'restock-units'], $this->effects());
        $this->assertSame([], $this->payouts());
        $this->assertSame('remedied', $this->subjectState('order'));
        $this->assertIncidentReplayQuiet($origin->runId(), $shipment->id);
    }
}
