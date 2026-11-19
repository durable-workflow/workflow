<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use Tests\Support\ExchangeFixture;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * SHP-05: one delivered unit exchanged for another size at the same price. A separate native exchange
 * receives and inspects the original unit, reserves the replacement, then books and tracks it under its own
 * identities. Synthetic recorded-task fixture, not installed-package, real-carrier or complete-matrix qualification.
 *
 * Split with V2ExchangeCompensationTest so each fits one feature shard.
 */
final class V2ExchangeMachineTest extends TestCase
{
    use ExchangeFixture;

    private const RETURNED = [...self::DELIVERED, 'request-unit-return', 'restock-units'];

    public function testAcceptedOriginalIsReplacedWithoutMoneyMovementWhileTheOrderStaysFulfilled(): void
    {
        [$origin, $shipment] = $this->delivered();
        $history = $this->history($origin->runId());
        $childHistory = $this->history($shipment->id);
        $commands = $this->planExchange('swap-1', 2);
        $admitted = $this->exchange('swap-1', 2);
        $this->assertFalse($admitted['existing']);
        $this->drain();
        $run = $this->exchangeRun('swap-1');
        $this->assertSame([...self::DELIVERED, 'request-unit-return'], $this->effects(), $this->progress());
        $duplicate = $this->exchange('swap-1', 2);
        $this->assertTrue($duplicate['existing'], 'The same exchange key and terms are idempotent.');
        $this->assertSame($run->id, $duplicate['runId']);

        $this->warehouse($commands['request'], 'received', [2]);
        $this->hintExchange($run, 'swap-1-receipt');
        $this->assertFalse($run->fresh()->status->isTerminal(), 'Receipt alone is not acceptance.');
        $this->assertSame([...self::DELIVERED, 'request-unit-return'], $this->effects());
        $this->warehouse($commands['request'], 'inspected', [2], [2], [2]);
        $this->hintExchange($run, 'swap-1-inspection');
        $this->assertSame([...self::RETURNED, 'reserve', 'ship-book'], $this->effects(), $this->progress());
        $this->assertFalse($run->fresh()->status->isTerminal(), 'A booked replacement is not a delivered one.');
        $this->assertNull($this->exchangeRow('swap-1')->outcome);
        $this->carrier($commands, 'handed-over');
        $this->hintExchange($run, 'swap-1-handover');
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->carrier($commands, 'delivered');
        $this->hintExchange($run, 'swap-1-delivery');

        $this->assertTrue($run->fresh()->status->isTerminal(), $this->progress());
        $this->assertSame([...self::RETURNED, 'reserve', 'ship-book'], $this->effects());
        $this->assertSame([], $this->payouts(), 'A same-price exchange moves no money.');
        $outcome = $this->exchangeOutcome('swap-1');
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('replaced', $outcome['resolution']);
        $this->assertSame('fulfilled', $outcome['originalOrderState']);
        foreach (['receivedUnits', 'acceptedUnits', 'saleableUnits', 'restockedUnits'] as $field) {
            $this->assertSame([2], $outcome[$field], $field);
        }
        $this->assertSame([], $outcome['discrepancyUnits']);
        $this->assertSame([], $outcome['refundedUnits']);
        $this->assertSame(0, $outcome['moneyMovementMinor']);
        $this->assertSame(0, $outcome['refundOwedMinor']);
        $this->assertTrue($outcome['replacementHandedOver']);
        $this->assertTrue($outcome['replacementDelivered']);
        $this->assertSame(self::REPLACEMENT_SKU, $outcome['replacementSku']);
        // Original shipment, return and replacement keep separate identities.
        $identities = [
            $outcome['originalBooking'],
            $outcome['returnOperation'],
            $outcome['replacementReservation'],
            $outcome['replacementBooking'],
        ];
        $this->assertSame([
            $this->graph()
                ->booking->identity->key(),
            $commands['request']->identity->key(),
            $commands['reserve']->identity->key(),
            $commands['graph']->booking->identity->key(),
        ], $identities);
        $this->assertCount(4, array_unique($identities));
        $this->assertSame($outcome, WorkflowStub::loadRun($run->id, Catalogue::NAMESPACE)->output());

        // The original Order, its delivery and its full-parcel counters stay historical.
        $this->assertSame('fulfilled', $this->subjectState('order'));
        $this->assertSame('delivered', $this->subjectState('shipment'));
        $facts = $this->physicalFacts();
        $this->assertTrue($facts['original_recipient_delivered']);
        $this->assertSame(3, $facts['deducted_quantity']);
        $this->assertSame(0, $facts['restocked_quantity']);
        $this->assertFalse($facts['return_requested']);
        $this->assertTrue($this->deliveredPublication());
        $this->assertSame($history, $this->history($origin->runId()));
        $this->assertSame($childHistory, $this->history($shipment->id));

        $before = $this->requests();
        foreach ([$run->id, $origin->runId(), $shipment->id] as $id) {
            app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($id));
        }
        $this->assertSame($before, $this->requests());
    }

    public function testRejectedOriginalGetsNoReplacementRestockOrRefund(): void
    {
        $this->delivered();
        $commands = $this->planExchange('swap-rejected', 0);
        $this->exchange('swap-rejected', 0);
        $this->drain();
        $run = $this->exchangeRun('swap-rejected');
        $this->inspectOriginal($commands, $run, 0, accepted: false);

        $this->assertTrue($run->fresh()->status->isTerminal(), $this->progress());
        $this->assertSame([...self::DELIVERED, 'request-unit-return'], $this->effects());
        $this->assertSame([], $this->payouts());
        $outcome = $this->exchangeOutcome('swap-rejected');
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('original-rejected', $outcome['resolution']);
        $this->assertSame([0], $outcome['receivedUnits']);
        $this->assertSame([], $outcome['acceptedUnits']);
        $this->assertSame([0], $outcome['discrepancyUnits']);
        $this->assertSame([], $outcome['restockedUnits']);
        $this->assertNull($outcome['replacementReservation']);
        $this->assertNull($outcome['replacementBooking']);
        $this->assertSame(0, $outcome['moneyMovementMinor']);
        $this->assertSame(Status::Unknown, $this->provider->lookup($commands['reserve'])->status);
        $this->assertSame('fulfilled', $this->subjectState('order'));
    }

    public function testDefinitivelyUnavailableReplacementRefundsOnlyTheOriginalUnitAmount(): void
    {
        $this->delivered();
        $commands = $this->planExchange('swap-unavailable', 2, reserve: Status::Refused);
        $this->exchange('swap-unavailable', 2);
        $this->drain();
        $run = $this->exchangeRun('swap-unavailable');
        $this->inspectOriginal($commands, $run, 2);

        $this->assertTrue($run->fresh()->status->isTerminal(), $this->progress());
        $this->assertSame(self::RETURNED, $this->effects());
        $this->assertSame([499], $this->payouts());
        $outcome = $this->exchangeOutcome('swap-unavailable');
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('refunded-replacement-unavailable', $outcome['resolution']);
        $this->assertSame('refused', $outcome['replacementReservationStatus']);
        $this->assertNull($outcome['replacementBooking']);
        $this->assertSame([2], $outcome['restockedUnits']);
        $this->assertSame([2], $outcome['refundedUnits']);
        $this->assertSame(499, $outcome['refundedAmountMinor']);
        $this->assertSame(499, $outcome['moneyMovementMinor']);
        $this->assertSame(Status::Unknown, $this->provider->lookup($commands['graph']->booking)->status);
        $this->assertSame('fulfilled', $this->subjectState('order'));
    }

    public function testUnknownReservationOutcomeIsReconciledAsTheSameOperationNotARefusal(): void
    {
        $this->delivered();
        $commands = $this->planExchange('swap-unknown', 1, loseReservationAcknowledgement: true);
        $this->exchange('swap-unknown', 1);
        $this->drain();
        $run = $this->exchangeRun('swap-unknown');
        $this->inspectOriginal($commands, $run, 1);

        // The lost acknowledgement left one committed reservation; recovery looked it up instead of refunding.
        $this->assertSame([...self::RETURNED, 'reserve', 'ship-book'], $this->effects(), $this->progress());
        $this->assertSame([], $this->payouts());
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->carrier($commands, 'handed-over');
        $this->carrier($commands, 'delivered');
        $this->hintExchange($run, 'swap-unknown-delivery');
        $this->assertSame('replaced', $this->exchangeOutcome('swap-unknown')['resolution']);
        $this->assertSame([...self::RETURNED, 'reserve', 'ship-book'], $this->effects());
    }
}
