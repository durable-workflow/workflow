<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use Tests\Support\PhysicalMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Actual recorded full Shipment; no installed, physical-carrier or complete-matrix qualification. */
final class V2PhysicalShipmentMachineTest extends TestCase
{
    use PhysicalMachineFixture;

    public function testActualHandoverDeductionAndRecipientPublicationPermitFulfillmentWhileChildIsStillOpen(): void
    {
        $origin = $this->startPhysical();
        $shipment = $this->shipment();
        $this->assertSame('booked', $this->subjectState('shipment'), $this->progress());
        $this->assertSame(0, $this->physicalFacts()['deducted_quantity']);
        WorkflowStub::loadRun($shipment->id, Catalogue::NAMESPACE)->withRequestId('unproven-delivery-hint')
            ->signal('physical-milestone', [
                'delivered' => true,
            ]);
        $this->current();
        $this->assertSame('booked', $this->subjectState('shipment'));
        $this->assertSame('ready', $this->subjectState('order'));
        $this->assertFalse($this->deliveredPublication());
        $this->record('handed-over');
        $this->hint($shipment, 'original-handover');
        $this->current();
        $this->assertSame('in-transit', $this->subjectState('shipment'), $this->progress());
        $this->assertSame($this->terms->quantity, $this->physicalFacts()['deducted_quantity']);
        $this->record('delivered');
        $this->hint($shipment, 'original-delivery');
        $this->drain(fn (): bool => $this->deliveredPublication());
        $this->assertFalse($shipment->fresh()->status->isTerminal());
        $origin->withRequestId('recover-original-delivery-notification')
            ->signal('physical-milestone');
        $this->drain(fn (): bool => $this->subjectState('order') === 'fulfilled', $shipment->workflow_instance_id);
        $this->assertSame('fulfilled', $this->subjectState('order'), $this->progress());
        $this->assertFalse($shipment->fresh()->status->isTerminal());
        $this->current();
        $this->assertTrue($shipment->fresh()->status->isTerminal(), $this->progress());
        $this->assertTrue($this->physicalFacts()['original_recipient_delivered']);
        $this->assertSame(0, $this->physicalFacts()['restocked_quantity']);
        $this->assertSame(
            ['authorize', 'reserve', 'capture-authorized', 'ship-book', 'deduct-stock'],
            $this->effects()
        );
        $before = (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        foreach ([$origin->runId(), $shipment->id] as $id) {
            app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($id));
        }
        $this->assertSame(
            $before,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
                ->fetchColumn()
        );
    }

    public function testClosedDeliveredChildMustReceiveInspectAndRestockBeforeOriginalRefundAndReservationRelease(): void
    {
        $origin = $this->startPhysical();
        $shipment = $this->shipment();
        $this->record('handed-over');
        $this->hint($shipment, 'original-handover');
        $this->current();
        $this->record('delivered');
        $this->hint($shipment, 'original-delivery');
        $this->current();
        $this->assertSame('fulfilled', $this->subjectState('order'), $this->progress());
        $this->assertTrue($shipment->fresh()->status->isTerminal());
        $history = $this->history($origin->runId());
        $childHistory = $this->history($shipment->id);
        $cancellation = app(CancellationStore::class);
        $key = $this->terms->subjectKey();
        $cancellation->request($key, $origin->runId(), 'physical-return-required');
        $this->drain();
        $this->assertSame(
            ['authorize', 'reserve', 'capture-authorized', 'ship-book', 'deduct-stock', 'request-return'],
            $this->effects()
        );
        $this->assertFalse($this->physicalFacts()['full_return_accepted']);
        $this->assertNotSame('cancelled', $this->subjectState('order'));
        $this->record('received');
        $this->assertSame(0, $this->physicalFacts()['restocked_quantity']);
        $this->provider->shipments()
            ->record(
                $this->graph(),
                'original-accepted-inspection',
                'inspected',
                $this->terms->quantity,
                $this->terms->quantity,
                $this->terms->quantity
            );
        $cancellation->request($key, $origin->runId(), 'original-physical-return-accepted');
        $this->drain();
        $plan = $this->store->authority->plan($key);
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame(['authorize', 'reserve', 'capture-authorized', 'ship-book', 'deduct-stock',
            'request-return', 'restock', 'refund-authorized', 'release'], $this->effects());
        $this->assertSame($this->terms->quantity, $this->physicalFacts()['restocked_quantity']);
        $this->assertTrue($this->physicalFacts()['original_recipient_delivered']);
        $this->assertTrue($this->deliveredPublication());
        $this->assertSame($history, $this->history($origin->runId()));
        $this->assertSame($childHistory, $this->history($shipment->id));
        $this->assertSame(
            'started',
            $cancellation->finish($key, $origin->runId(), 'original-physical-return-accepted')['status']
        );
        $this->drain();
        $this->assertSame('cancelled', $this->subjectState('order'), $this->progress());
    }

    public function testMissingReservationReceiptCannotAdmitOriginalDeduction(): void
    {
        $this->startPhysical();
        $shipment = $this->shipment();
        $request = Request::fromArray($this->terms->descriptor('order')['forward']);
        $delete = $this->providerDb->prepare('DELETE FROM provider_effects WHERE operation_id = ?');
        $delete->execute([$request->identity->key()]);
        $this->record('handed-over');
        $this->hint($shipment, 'handover-after-original-reservation-receipt-loss');
        $this->current();
        $this->assertNotContains('deduct-stock', $this->effects());
        $this->assertNotSame('in-transit', $this->subjectState('shipment'));
        $this->assertNotSame('fulfilled', $this->subjectState('order'));
    }
}
