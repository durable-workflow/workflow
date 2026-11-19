<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\CodFundingPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CarrierBalanceProcessLoss;
use Tests\Support\CodFundingMachineFixture;
use Tests\Support\PaymentProcessLoss;
use Tests\TestCase;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * PAY-04 under TECH-01: a real worker process dies after the carrier registers the original collection
 * reference, closes collection admission during cancellation, or refunds the collected payer, and before DW
 * records it. A fresh process recovers the same original obligation; no carrier effect repeats.
 */
final class V2CodProcessLossTest extends TestCase
{
    use CodFundingMachineFixture;
    use PaymentProcessLoss;
    use CarrierBalanceProcessLoss;

    public static function cuts(): array
    {
        return [
            'collection reference registered' => ['forward', false],
            'collection closed during cancellation before payer collection' => ['compensate', false],
            'payer refund committed after collection' => ['compensate', true],
        ];
    }

    #[DataProvider('cuts')]
    public function testFreshWorkerRecoversTheOriginalCarrierEffectOnce(string $mode, bool $collected): void
    {
        $this->requireWorkerLossSupport();
        $cancel = $mode === 'compensate';
        $origin = $cancel ? $this->startCod(CodFundingPolicy::BEFORE) : $this->beginCod(CodFundingPolicy::BEFORE);
        $original = $this->terms->codPayment;
        $wake = $this->wakePayment(...);
        $receipt = $collected ? $this->collectCarrierBalance($origin, $original->amountMinor, $wake) : null;
        $refund = $receipt === null ? null : CodPaymentOperation::refundRequest(
            $this->store->authority->plan($this->terms->subjectKey())
                ->childScope('payment'),
            'payment.cod.payer',
            $original,
            $receipt
        );
        if ($refund !== null) {
            $this->provider->plan($refund, Status::Confirmed);
            $this->cancelCollectedOrder($origin, 'cancel-collected-cod');
        } elseif ($cancel) {
            app(CancellationStore::class)->request($this->terms->subjectKey(), $origin->runId(), 'cancel-open-cod');
        }
        $committed = $this->carrierCut($original, $cancel, $refund !== null);
        $this->loseThenRecoverPayment('cod', 'provider.cod.payer', $mode, $committed);
        if (! $cancel) {
            $this->assertSame('ready', $this->state('order'), $this->progress());
            $this->assertSame('ready', $this->state('payment'));
            $this->deliverAndRemitCarrierBalance(
                $this->collectCarrierBalance($origin, $original->amountMinor, $wake),
                $wake
            );
            $this->assertSame(['reserve', 'ship-book', 'deduct-stock'], $this->effects());
        } elseif ($refund === null) {
            $this->assertCancelledByAutomaticRecovery();
            $this->assertSame(['reserve', 'release'], $this->effects());
        } else {
            $this->remitThenFinishCancellation($receipt, 'cancel-collected-cod');
            $this->assertRepeatedRequestOneEffect($refund);
        }
        $this->assertCarrierRecovered($this->cod, $original, $cancel, $committed);
    }
}
