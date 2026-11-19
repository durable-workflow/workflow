<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitReturnTerms;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\ReturnStore;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\PartialReturnFixture;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * SHP-04: three delivered units, one returned and accepted. A separate native return refunds exactly that
 * unit's original amount and restocks only it; the fulfilled Order and its delivery evidence stay unchanged.
 * Synthetic recorded-task fixture, not installed-package, real-carrier or complete-matrix qualification.
 *
 * Split with V2PartialReturnCancellationTest so each fits one feature shard.
 */
final class V2PartialReturnMachineTest extends TestCase
{
    use PartialReturnFixture;

    public function testOneAcceptedUnitIsRestockedAndRefundedWhileTheOrderStaysFulfilled(): void
    {
        [$origin, $shipment] = $this->delivered();
        $history = $this->history($origin->runId());
        $childHistory = $this->history($shipment->id);
        $return = $this->open('rma-1', [2]);
        $run = $this->returnRun('rma-1');
        $this->assertSame([...self::DELIVERED, 'request-unit-return'], $this->effects(), $this->progress());
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->assertSame(
            $run->id,
            app(ReturnStore::class)->request($this->terms->subjectKey(), 'rma-1', [2])['runId'],
            'The same return key and units are idempotent.'
        );

        $this->warehouse($return, 'received', [2]);
        $this->hintReturn($run, 'rma-1-receipt');
        $this->assertFalse($run->fresh()->status->isTerminal(), 'Receipt alone is not acceptance.');
        $this->assertSame([], $this->payouts());
        $this->warehouse($return, 'inspected', [2], [2], [2]);
        $this->hintReturn($run, 'rma-1-inspection');

        $this->assertTrue($run->fresh()->status->isTerminal(), $this->progress());
        $this->assertSame([...self::DELIVERED, 'request-unit-return', 'restock-units'], $this->effects());
        $this->assertSame([499], $this->payouts());
        $outcome = $this->outcome('rma-1');
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('fulfilled', $outcome['originalOrderState']);
        foreach ([
            'requestedUnits',
            'receivedUnits',
            'acceptedUnits',
            'saleableUnits',
            'restockedUnits',
            'refundedUnits',
        ] as $field) {
            $this->assertSame([2], $outcome[$field], $field);
        }
        $this->assertSame([], $outcome['discrepancyUnits']);
        $this->assertSame(499, $outcome['refundedAmountMinor']);
        $this->assertSame(499, $outcome['refundPayoutMinor']);
        $this->assertSame($outcome, WorkflowStub::loadRun($run->id, Catalogue::NAMESPACE)->output());

        // The two retained units remain fulfilled; delivery and the full-parcel counters are historical.
        $this->assertSame('fulfilled', $this->subjectState('order'));
        $this->assertSame('delivered', $this->subjectState('shipment'));
        $facts = $this->physicalFacts();
        $this->assertTrue($facts['original_recipient_delivered']);
        $this->assertSame(3, $facts['deducted_quantity']);
        $this->assertSame(0, $facts['restocked_quantity']);
        $this->assertFalse($facts['return_requested']);
        $this->assertTrue($this->deliveredPublication());
        $unit = $this->provider->shipments()
            ->inspectUnitReturn($return, UnitReturnTerms::restock($return, [2]))['facts'];
        $this->assertSame([2], $unit['restocked_units']);
        $this->assertSame($history, $this->history($origin->runId()));
        $this->assertSame($childHistory, $this->history($shipment->id));

        $before = $this->requests();
        foreach ([$run->id, $origin->runId(), $shipment->id] as $id) {
            app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($id));
        }
        $this->assertSame($before, $this->requests());
    }

    public function testUnacceptedOrUnsuitableUnitsAreNeitherRestockedNorRefunded(): void
    {
        $this->delivered();
        $return = $this->open('rma-damaged', [0, 1], []);
        $run = $this->returnRun('rma-damaged');
        $this->warehouse($return, 'received', [0, 1]);
        // Unit 0 is accepted but unsuitable for sale; unit 1 is rejected.
        $this->warehouse($return, 'inspected', [0, 1], [0], []);
        $this->hintReturn($run, 'rma-damaged-inspection');

        $this->assertTrue($run->fresh()->status->isTerminal(), $this->progress());
        $this->assertSame([...self::DELIVERED, 'request-unit-return'], $this->effects());
        $this->assertSame([], $this->payouts());
        $outcome = $this->outcome('rma-damaged');
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame([0, 1], $outcome['receivedUnits']);
        $this->assertSame([0], $outcome['acceptedUnits']);
        $this->assertSame([], $outcome['saleableUnits']);
        $this->assertSame([0, 1], $outcome['discrepancyUnits']);
        $this->assertSame([], $outcome['restockedUnits']);
        $this->assertSame([], $outcome['refundedUnits']);
        $this->assertSame(0, $outcome['refundedAmountMinor']);
        $this->assertSame('fulfilled', $this->subjectState('order'));
    }

    public function testReturnBeforeDeliveryAndChangedUnitsAreRefusedBeforeProviderAccess(): void
    {
        $this->startPhysical();
        $this->assertSame('booked', $this->subjectState('shipment'), $this->progress());
        $before = $this->requests();
        try {
            app(ReturnStore::class)->request($this->terms->subjectKey(), 'rma-early', [2]);
            $this->fail('A return was admitted before original delivery.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('fulfilled and delivered', $error->getMessage());
        }
        $this->assertSame(0, DB::table('provider_machine_returns')->count());
        $this->assertSame($before, $this->requests());
        // The provider independently refuses a unit return of an undelivered booking.
        $booking = Request::fromArray($this->terms->physicalDescriptor()['booking']);
        $early = UnitReturnTerms::request($booking, 'rma-early', [2]);
        $this->provider->plan($early, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($early)->status);
    }
}
