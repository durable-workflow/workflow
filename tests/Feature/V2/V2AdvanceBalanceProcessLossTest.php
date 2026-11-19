<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Closure;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\AdvanceBalancePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdvanceBalanceMachineFixture;
use Tests\Support\CarrierBalanceProcessLoss;
use Tests\Support\PaymentProcessLoss;
use Tests\TestCase;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * PAY-05 under TECH-01, carrier-collected balance side: a real worker process dies after the carrier
 * registers the original balance reference, or closes balance collection and refunds the payer during
 * cancellation, and before DW records it. A fresh process recovers the same original obligation; the bank
 * advance keeps its own evidence (its refund cut is V2AdvanceBalanceColdRecoveryTest).
 */
final class V2AdvanceBalanceProcessLossTest extends TestCase
{
    use AdvanceBalanceMachineFixture;
    use PaymentProcessLoss;
    use CarrierBalanceProcessLoss;

    public static function cuts(): array
    {
        return [
            'balance reference registered' => ['forward'],
            'balance closed and payer refunded during cancellation' => ['compensate'],
        ];
    }

    #[DataProvider('cuts')]
    public function testFreshWorkerRecoversTheOriginalBalanceEffectOnce(string $mode): void
    {
        $this->requireWorkerLossSupport();
        $cancel = $mode === 'compensate';
        $origin = $this->beginAdvance(AdvanceBalancePolicy::BEFORE);
        $parts = $this->terms->advanceBalance;
        $wake = fn (string $id) => $this->wake('cod-observed', $id);
        [$balance, $refund] = $cancel ? $this->cancelAfterBothPortions($origin, $wake) : [null, null];
        $committed = $this->carrierCut($parts->balance, $cancel, $cancel);
        $this->loseThenRecoverPayment('advance-balance', 'provider.cod.payer', $mode, $committed);
        if ($balance !== null) {
            $this->assertCancelledByAutomaticRecovery();
            $this->assertSame([
                'release' => 1,
            ], $this->providerActions('release')());
            $this->assertRepeatedRequestOneEffect($refund);
            $this->assertSame(1, count(array_keys($this->effects(), 'refund-incoming', true)));
        } else {
            $this->assertSame('received', $this->state('order'), $this->progress());
            $this->payAdvance();
            $this->collectAndRemitCarrierBalance($origin, $parts->balance->amountMinor, $wake, true);
            $this->assertSame(['reserve', 'ship-book', 'deduct-stock'], $this->effects());
        }
        $this->assertCarrierRecovered($this->mixed->cod, $parts->balance, $cancel, $committed);
    }

    private function payAdvance(): IncomingPaymentReceipt
    {
        $receipt = $this->receive('original-bank-advance', $this->terms->advanceBalance->advance->amountMinor);
        $this->wake('incoming-observed', 'original-advance-paid');
        $this->assertSame('ready', $this->state('order'), $this->progress());
        return $receipt;
    }

    /**
     * Both portions are paid and the original shipment returned; both original refunds are planned.
     *
     * @return array{CodReceipt, Request} The balance collection and its payer refund.
     */
    private function cancelAfterBothPortions(WorkflowStub $origin, Closure $wake): array
    {
        $parts = $this->terms->advanceBalance;
        $this->pump();
        $advance = $this->payAdvance();
        $balance = $this->collectAndRemitCarrierBalance($origin, $parts->balance->amountMinor, $wake, false);
        $scope = $this->store->authority->plan($this->terms->subjectKey())
            ->childScope('payment');
        $refund = CodPaymentOperation::refundRequest($scope, 'payment.cod.payer', $parts->balance, $balance);
        $this->provider->plan($refund, Status::Confirmed);
        $this->provider->plan(
            IncomingPaymentOperation::refundRequest($scope, 'payment.incoming', $parts->advance, $advance),
            Status::Confirmed
        );
        $this->cancelCollectedOrder($origin, 'cancel-original-portions');
        return [$balance, $refund];
    }
}
