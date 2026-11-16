<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\FinancialDescriptor;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FinancialCatalogue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\PhysicalMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

final class V2DeliveryCaptureMachineTest extends TestCase
{
    use PhysicalMachineFixture;

    public function testOriginalRecipientPublicationTriggersOneCaptureBeforeShipmentTechnicalCompletion(): void
    {
        $origin = $this->startPhysical();
        $shipment = $this->shipment();
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $ids = array_column((new ScopeRepository())->inspect($plan->childScope('payment'))['obligations'], 'id');
        $this->assertSame('authorized', $this->subjectState('payment'));
        $this->assertSame(['authorize', 'reserve', 'ship-book'], $this->effects(), $this->progress());
        $this->hint($shipment, 'unproven-recipient-hint');
        $this->current();
        $this->assertSame(['authorize', 'reserve', 'ship-book'], $this->effects());
        $this->record('handed-over');
        $this->hint($shipment, 'original-carrier-handover');
        $this->current();
        $this->assertSame('in-transit', $this->subjectState('shipment'), $this->progress());
        $this->assertSame('authorized', $this->subjectState('payment'));
        $this->record('delivered');
        $this->hint($shipment, 'original-recipient-delivery');
        $this->drain(fn (): bool => $this->deliveredPublication());
        $this->assertFalse($shipment->fresh()->status->isTerminal());
        $origin->withRequestId('recover-original-recipient-notification')
            ->signal('physical-milestone');
        $this->drain(fn (): bool => $this->subjectState('order') === 'fulfilled', $shipment->workflow_instance_id);
        $this->assertSame('fulfilled', $this->subjectState('order'), $this->progress());
        $this->assertSame('captured', $this->subjectState('payment'));
        $this->assertFalse($shipment->fresh()->status->isTerminal());
        $this->assertSame(
            ['authorize', 'reserve', 'ship-book', 'deduct-stock', 'capture-authorized'],
            $this->effects()
        );
        $this->assertSame(
            $ids,
            array_column((new ScopeRepository())->inspect($plan->childScope('payment'))['obligations'], 'id')
        );
        $this->current();
        $this->assertTrue($shipment->fresh()->status->isTerminal());
    }

    public function testExpiryInTransitFencesCaptureEvenWhenTheOriginalDeliveryArrivesLate(): void
    {
        $this->startPhysical();
        $shipment = $this->shipment();
        $this->record('handed-over');
        $this->hint($shipment, 'original-carrier-handover');
        $this->current();
        $this->assertSame('in-transit', $this->subjectState('shipment'), $this->progress());
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->terms->authorization->expiresAt));
        $this->current();
        $this->assertTrue(
            (bool) $this->store->authority->subject($this->terms->subjectKey(), 'order')
->cancellation_requested,
            $this->progress()
        );
        $this->record('delivered');
        $late = WorkflowStub::loadRun($shipment->id, Catalogue::NAMESPACE)->withRequestId(
            'late-original-recipient-delivery'
        )
            ->attemptSignal('physical-milestone');
        $this->assertFalse($late->accepted());
        $this->current();
        $this->assertNotContains('capture-authorized', $this->effects());
        $this->assertNotContains('restock', $this->effects());
        $this->assertNotContains('release', $this->effects());
        $this->assertNotContains('refund-authorized', $this->effects());
        $this->assertNotSame('fulfilled', $this->subjectState('order'));
        $this->assertNotSame('cancelled', $this->subjectState('order'));
        $facts = $this->provider->inspectFinancial(
            FinancialDescriptor::fromArray($this->terms->financialDescriptor())
        )->facts();
        $this->assertTrue($facts['authorization_expired']);
        $this->assertFalse($facts['authorization_active']);
        $this->assertTrue($this->physicalFacts()['original_recipient_delivered']);
        $this->assertFalse($this->deliveredPublication());
    }

    public function testAnotherDeliveryExecutionCannotAuthorizeTheOriginalMoneyObligation(): void
    {
        $origin = $this->startPhysical();
        $shipment = $this->shipment();
        $this->record('handed-over');
        $this->hint($shipment, 'original-carrier-handover');
        $this->current();
        $this->record('delivered');
        $this->hint($shipment, 'original-recipient-delivery');
        $this->drain(fn (): bool => $this->deliveredPublication());
        $row = DB::table('example_machine_publications')->where(
            'subject_id',
            Authority::subjectId($this->terms->subjectKey(), 'shipment')
        )
            ->where('payload', 'like', '%"state":"delivered"%')
            ->sole();
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $payload['execution']['executionId'] = 'foreign-child.deliver.3';
        DB::table('example_machine_publications')->where('id', $row->id)->update([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $origin->withRequestId('substituted-recipient-hint')
            ->signal('physical-milestone');
        $this->current();
        $this->assertNotContains('capture-authorized', $this->effects());
        $this->assertNotSame('fulfilled', $this->subjectState('order'));
        $scope = $this->store->authority->plan($this->terms->subjectKey())
            ->childScope('payment');
        $this->assertCount(3, (new ScopeRepository())->inspect($scope)['obligations']);
    }

    protected function recipe(): string
    {
        return FinancialCatalogue::AFTER_DELIVERY;
    }
}
