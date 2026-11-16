<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\CodFundingPolicy;
use Tests\Support\CodFundingMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Recorded native COD source cases; installed and full customer-matrix acceptance remain separate. */
final class V2CodMerchantSettlementTest extends TestCase
{
    use CodFundingMachineFixture;

    public function testRefundProcessingRetainsPayerAndMerchantResponsibilitiesWithoutReleasingStock(): void
    {
        $origin = $this->startCod(CodFundingPolicy::BEFORE);
        $origin->withRequestId('fulfill-cod-to-cancel')
            ->signal('fulfill');
        $this->pump();
        $this->recordPhysical('handed-over');
        $receipt = $this->collect('payer-to-refund', 1499);
        $this->wakePayment('payer-collected-before-cancel');
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $refund = CodPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.cod.payer',
            $this->terms->codPayment,
            $receipt
        );
        $this->provider->plan($refund, Status::Processing);
        app(CancellationStore::class)->request($this->terms->subjectKey(), $origin->runId(), 'cancel-original-cod');
        $this->pump();
        $graph = ShipmentDescriptor::fromArray($this->terms->physicalDescriptor());
        $this->provider->shipments()
            ->record($graph, 'original-warehouse-receipt', 'received', $this->terms->quantity);
        $this->provider->shipments()
            ->record(
                $graph,
                'original-warehouse-inspection',
                'inspected',
                $this->terms->quantity,
                $this->terms->quantity,
                $this->terms->quantity
            );
        app(CancellationStore::class)->request(
            $this->terms->subjectKey(),
            $origin->runId(),
            'original-return-accepted'
        );
        $this->pump();
        $this->assertSame(Status::Processing, $this->provider->lookup($refund)->status, $this->progress());
        $this->assertSame(
            'requested',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame('ready', $this->state('order'));
        $this->assertNotContains('release', $this->effects());
        $this->assertSame(1499, $this->cod->inspect($this->terms->codPayment)['facts']['outstanding_remittance_minor']);
        $this->assertNotSame('verified', (new ScopeRepository())->metadata($plan->childScope('payment'))['status']);
        $this->provider->resolve($refund, Status::Confirmed);
        app(CancellationStore::class)->request($this->terms->subjectKey(), $origin->runId(), 'original-payer-refunded');
        $this->pump();
        $this->assertSame(
            1,
            count(array_filter($this->effects(), static fn (string $action): bool => $action === 'refund-cod'))
        );
        $this->assertNotContains('release', $this->effects());
        $this->assertSame('requested', (new ScopeRepository())->metadata($plan->childScope('payment'))['status']);
        $this->assertSame(1499, $this->cod->inspect($this->terms->codPayment)['facts']['outstanding_remittance_minor']);
        $this->remit($receipt, 'original-carrier-merchant-debt-paid', 1499);
        app(CancellationStore::class)->request(
            $this->terms->subjectKey(),
            $origin->runId(),
            'original-merchant-remittance-confirmed'
        );
        $this->pump();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        app(CancellationStore::class)->finish($this->terms->subjectKey(), $origin->runId(), 'cancel-original-cod');
        $this->pump();
        $this->assertSame('cancelled', $this->state('order'));
        $this->assertContains('release', $this->effects());
        $this->assertSame(
            1,
            count(array_filter($this->effects(), static fn (string $action): bool => $action === 'refund-cod'))
        );
    }
}
