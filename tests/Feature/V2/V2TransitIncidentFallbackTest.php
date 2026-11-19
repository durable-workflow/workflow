<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\IncidentStore;
use Tests\Support\TransitIncidentFixture;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * SHP-07/SHP-08: the carrier confirms loss or transport damage of the original parcel before recipient
 * handover. A separate native incident settles the trusted remedy, files the carrier claim and recovers
 * damaged goods. Synthetic recorded-task fixture, not installed-package, real-carrier or warehouse qualification.
 *
 * Refused or failed replacements and refunds, ending refunded or explicitly owed. The scenarios are split across V2TransitIncident*Test
 * files so each fits one feature shard.
 */
final class V2TransitIncidentFallbackTest extends TestCase
{
    use TransitIncidentFixture;

    public function testRefusedReplacementBookingReleasesTheReservationAndRefundsInstead(): void
    {
        [$origin] = $this->handedOver();
        $commands = $this->planIncident('lost', book: Status::Refused);
        $this->record('lost');
        $this->hint($this->shipment(), 'original-loss');
        $this->current();

        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        // The unused reservation is released before the refund; no replacement parcel exists.
        $this->assertSame([...self::DELIVERED, 'reserve', 'release', 'carrier-claim'], $this->effects());
        $this->assertSame([1499], $this->payouts());
        $outcome = $this->incidentOutcome();
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('refunded-replacement-failed', $outcome['resolution']);
        $this->assertSame('booking-refused', $outcome['replacementFailure']);
        $this->assertSame($commands['reserve']->identity->key(), $outcome['replacementReservation']);
        $this->assertSame($commands['graph']->booking->identity->key(), $outcome['replacementBooking']);
        $this->assertSame(
            'remedied',
            WorkflowRun::query()->findOrFail($origin->runId())->workflowOutput()['businessOutcome']
        );
        $this->assertIncidentReplayQuiet($origin->runId(), $this->shipment()->id);
    }

    public function testFailedReplacementDeliveryRefundsWithoutReleasingTheShippedReplacementStock(): void
    {
        [$origin] = $this->handedOver();
        $commands = $this->planIncident('lost');
        $this->record('lost');
        $this->hint($this->shipment(), 'original-loss');
        $this->current();
        $this->replacementCarrier($commands, 'handed-over');
        $this->replacementCarrier($commands, 'lost');
        $this->hintIncident('replacement-loss');

        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertSame([...self::DELIVERED, 'reserve', 'ship-book', 'carrier-claim'], $this->effects());
        $this->assertSame([1499], $this->payouts());
        $outcome = $this->incidentOutcome();
        $this->assertSame('refunded-replacement-failed', $outcome['resolution']);
        $this->assertSame('lost', $outcome['replacementFailure']);
        $this->assertIncidentReplayQuiet($origin->runId(), $this->shipment()->id);
    }

    public function testUnresolvedReplacementAndRefusedRefundEndAsExplicitlyOwedWithoutFailingTheOrder(): void
    {
        [$origin] = $this->handedOver();
        $commands = $this->planIncident('lost');
        $this->record('lost');
        $this->hint($this->shipment(), 'original-loss');
        $this->current();
        $this->replacementCarrier($commands, 'handed-over');
        // Carrier progress without delivery or a confirmed failure, past the grant's bounded recorded wait.
        for ($wake = 0; $wake < self::TRANSIT_WAKES; ++$wake) {
            $this->hintIncident('replacement-progress-' . $wake);
        }

        $outcome = $this->incidentOutcome();
        $this->assertSame('owed', $outcome['status']);
        $this->assertSame('replacement-unresolved', $outcome['resolution']);
        $this->assertSame([], $this->payouts(), 'A replacement that may still arrive is not refunded automatically.');
        $this->assertTrue($this->incidentRun()->status->isTerminal());
        $this->assertSame([...self::DELIVERED, 'reserve', 'ship-book', 'carrier-claim'], $this->effects());
        $this->assertOwedOrder($origin->runId(), 'cancel-owed-remedy');
        $this->assertIncidentReplayQuiet($origin->runId(), $this->shipment()->id);
    }

    public function testRefusedRefundEndsAsExplicitlyOwed(): void
    {
        [$origin] = $this->handedOver();
        $this->planIncident('lost', refund: Status::Refused);
        $this->record('lost');
        $this->hint($this->shipment(), 'original-loss');
        $this->current();

        $outcome = $this->incidentOutcome();
        $this->assertSame('owed', $outcome['status']);
        $this->assertSame('refund-refused', $outcome['resolution']);
        $this->assertSame([], $this->payouts());
        $order = WorkflowRun::query()->findOrFail($origin->runId());
        $this->assertSame('completed', $order->status->value, $this->progress());
        $this->assertSame('unsettled', $order->workflowOutput()['businessOutcome']);
        $this->assertSame('ready', $this->subjectState('order'));
    }

    public function testSlowRefundEndsExplicitlyOwedAndItsLaterProviderConfirmationPaysOnce(): void
    {
        [$origin] = $this->handedOver();
        $commands = $this->slowRefund(self::TRANSIT_WAKES);

        // The provider still processes the refund when the bounded wait ends: explicitly owed, never a failure.
        $outcome = $this->incidentOutcome();
        $this->assertSame('owed', $outcome['status']);
        $this->assertSame('refund-unresolved', $outcome['resolution']);
        $this->assertSame([], $outcome['refundedUnits']);
        $this->assertSame([], $this->payouts());
        $this->assertSame('completed', $this->incidentRun()->status->value, $this->progress());
        $this->assertSame([...self::DELIVERED, 'carrier-claim'], $this->effects());
        $this->assertOwedOrder($origin->runId(), 'cancel-unresolved-refund');

        // The provider confirms later; a retry of the same refund identity is the same single payout.
        $this->unitRefunds->resolve($commands['refund'], Status::Confirmed);
        $this->unitRefunds->attempt($commands['refund']);
        $this->assertSame([
            'refund' => 'transit-lost',
            'commandId' => $commands['refund']->id(),
            'status' => Status::Confirmed->value,
            'confirmedUnits' => [0, 1, 2],
            'payoutMinor' => 1499,
        ], app(IncidentStore::class)->refund($this->terms->subjectKey()));
        $this->assertSame([1499], $this->payouts());
        $this->assertSame($outcome, $this->incidentOutcome(), 'The decided customer outcome never changes.');
        $this->assertSame('ready', $this->subjectState('order'));
        $this->assertIncidentReplayQuiet($origin->runId(), $this->shipment()->id);
    }

    public function testRefundConfirmedWhenTheBoundedWaitEndsSettlesTheSameRefund(): void
    {
        [$origin] = $this->handedOver();
        $commands = $this->slowRefund(self::TRANSIT_WAKES - 1);
        $this->unitRefunds->resolve($commands['refund'], Status::Confirmed);
        $this->hintIncident('refund-progress-last');

        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $outcome = $this->incidentOutcome();
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('refunded', $outcome['resolution']);
        $this->assertSame(1499, $outcome['refundPayoutMinor']);
        $this->assertSame([1499], $this->payouts());
        $this->assertIncidentReplayQuiet($origin->runId(), $this->shipment()->id);
    }

    /**
     * An owed remedy completes the Order unsettled, with its remedy transition pending, and closes cancellation.
     */
    private function assertOwedOrder(string $run, string $cancellation): void
    {
        $order = WorkflowRun::query()->findOrFail($run);
        $this->assertSame('completed', $order->status->value, $this->progress());
        $this->assertSame('unsettled', $order->workflowOutput()['businessOutcome']);
        $this->assertSame('pending', $order->workflowOutput()['transition']['status']);
        $this->assertSame('ready', $this->subjectState('order'));
        $this->assertCancellationRefused($cancellation, 'owed');
    }

    /**
     * A lost parcel whose refund the provider keeps processing across the given recorded incident wakes.
     */
    private function slowRefund(int $wakes): array
    {
        $commands = $this->planIncident('lost', refund: Status::Processing);
        $this->record('lost');
        $this->hint($this->shipment(), 'original-loss');
        $this->current();
        for ($wake = 0; $wake < $wakes; ++$wake) {
            $this->assertNull($this->incidentRow()->remedy, $this->progress());
            $this->hintIncident('refund-progress-' . $wake);
        }
        return $commands;
    }
}
