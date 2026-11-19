<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\FinancialCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PaymentProcessLoss;
use Tests\Support\PhysicalMachineFixture;
use Tests\TestCase;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * PAY-02 under TECH-01: a real worker process dies after the provider commits an authorization, a capture
 * under either capture policy, or a void during cancellation, and before DW records it. A fresh process
 * recovers the same original obligation through the production watchdog; no money effect repeats.
 */
final class V2AuthorizedPaymentProcessLossTest extends TestCase
{
    use PhysicalMachineFixture;
    use PaymentProcessLoss;

    public static function cuts(): array
    {
        return [
            'authorization committed' => [FinancialCatalogue::AFTER_STOCK, 'authorize', 'forward'],
            'stock-triggered capture committed' => [FinancialCatalogue::AFTER_STOCK, 'capture', 'forward'],
            'delivery-triggered capture committed' => [FinancialCatalogue::AFTER_DELIVERY, 'capture', 'forward'],
            'void committed during cancellation before capture' => [
                FinancialCatalogue::AFTER_STOCK,
                'authorize',
                'compensate',
            ],
        ];
    }

    #[DataProvider('cuts')]
    public function testFreshWorkerRecoversTheOriginalAuthorizedPaymentEffectOnce(
        string $recipe,
        string $stage,
        string $mode
    ): void {
        $this->requireWorkerLossSupport();
        $key = $this->terms->subjectKey();
        $delivery = $recipe === FinancialCatalogue::AFTER_DELIVERY;
        $origin = $delivery ? $this->deliverOriginalShipment() : $this->beginPhysical();
        if ($mode === 'compensate') {
            $this->drain(fn (): bool => $this->published('payment', 'authorized'));
            app(CancellationStore::class)->request($key, $origin->runId(), 'cancel-authorized-before-capture');
        }
        $requests = array_map(Request::fromArray(...), $this->terms->financialDescriptor());
        $lost = $mode === 'compensate' ? $requests['void'] : $requests[$stage];
        $this->loseThenRecoverPayment(
            'authorized',
            'provider.financial.' . $stage,
            $mode,
            $this->providerActions($lost->action)
        );
        $this->assertRepeatedRequestOneEffect($lost);
        if ($mode === 'compensate') {
            $this->assertCancelledByAutomaticRecovery();
            $this->assertSame(['authorize', 'void-authorization'], $this->effects());
        } else {
            $this->assertSame($delivery ? 'fulfilled' : 'ready', $this->subjectState('order'), $this->progress());
            $this->assertSame('captured', $this->subjectState('payment'));
            $this->assertSame($delivery ? [
                'authorize',
                'reserve',
                'ship-book',
                'deduct-stock',
                'capture-authorized',
            ] : ['authorize', 'reserve', 'capture-authorized'], $this->effects());
            $this->assertDeadlineKept('payment', $this->terms->authorization->expiresAt);
        }
        $this->assertReplayRequestsNothing('order', 'payment');
    }

    protected function recipe(): string
    {
        return $this->providedData()[0] ?? FinancialCatalogue::AFTER_STOCK;
    }

    private function deliverOriginalShipment(): WorkflowStub
    {
        $origin = $this->startPhysical();
        $shipment = $this->shipment();
        $this->record('handed-over');
        $this->hint($shipment, 'original-carrier-handover');
        $this->current();
        $this->record('delivered');
        $this->hint($shipment, 'original-recipient-delivery');
        $this->drain(fn (): bool => $this->deliveredPublication());
        $this->assertSame('authorized', $this->subjectState('payment'), $this->progress());
        return $origin;
    }
}
