<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\AdvanceBalancePolicy;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdvanceBalanceMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Original portion refunds and carrier debt survive native cancellation and recovery. */
final class V2AdvanceBalanceRefundTest extends TestCase
{
    use AdvanceBalanceMachineFixture;

    public static function receivedAdvances(): array
    {
        return [
            'partial-advance' => [200],
            'full-advance' => [500],
        ];
    }

    #[DataProvider('receivedAdvances')]
    public function testCancellationRefundsOnlyTheOriginalBankAdvanceWhenCarrierBalanceNeverArrived(int $amount): void
    {
        $origin = $this->startAdvance(AdvanceBalancePolicy::BEFORE);
        $receipt = $this->receive('original-bank-advance', $amount);
        $this->wake('incoming-observed', 'original-advance-paid');
        $this->assertSame($amount === 500 ? 'ready' : 'received', $this->state('order'), $this->progress());
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $refund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->advanceBalance->advance,
            $receipt
        );
        $this->provider->plan($refund, Status::Confirmed);
        $store = app(CancellationStore::class);
        $store->request($this->terms->subjectKey(), $origin->runId(), 'cancel-original-advance-order');
        $this->pump();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $store->finish($this->terms->subjectKey(), $origin->runId(), 'cancel-original-advance-order');
        $this->pump();
        $this->assertSame('cancelled', $this->state('order'), $this->progress());
        $this->assertSame(
            $amount === 500 ? ['reserve', 'refund-incoming', 'release'] : ['refund-incoming'],
            $this->effects()
        );
        $this->assertSame(
            [],
            $this->mixed->cod->collections->inspect($this->terms->advanceBalance->balance)['receipts']
        );
        try {
            $this->receive('late-after-original-cancellation', 1);
            $this->fail('The original closable advance cannot accept new money after verified cancellation.');
        } catch (LogicException) {
            $this->assertSame(
                $amount,
                $this->mixed->incoming->receipts->inspect($this->terms->advanceBalance->advance)['receivedAmountMinor']
            );
        }
    }

    public function testProcessingBalanceRefundBlocksTheSeparateAdvanceAndPreservesMerchantDebt(): void
    {
        $origin = $this->startAdvance(AdvanceBalancePolicy::BEFORE);
        $advance = $this->receive('original-advance-to-refund', 500);
        $this->wake('incoming-observed', 'original-advance-received');
        $origin->withRequestId('fulfill-original-portions')
            ->signal('fulfill');
        $this->pump();
        $this->recordPhysical('handed-over');
        $balance = $this->collect('original-balance-to-refund', 999);
        $this->wake('cod-observed', 'original-balance-received');
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $payerRefund = CodPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.cod.payer',
            $this->terms->advanceBalance->balance,
            $balance
        );
        $advanceRefund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->advanceBalance->advance,
            $advance
        );
        $this->assertNotSame($payerRefund->identity->key(), $advanceRefund->identity->key());
        $this->provider->plan($payerRefund, Status::Processing);
        $this->provider->plan($advanceRefund, Status::Confirmed);
        $store = app(CancellationStore::class);
        $store->request($this->terms->subjectKey(), $origin->runId(), 'cancel-both-original-portions');
        $this->pump();
        $graph = ShipmentDescriptor::fromArray($this->terms->physicalDescriptor());
        $this->provider->shipments()
            ->record($graph, 'original-return-received', 'received', $this->terms->quantity);
        $this->provider->shipments()
            ->record(
                $graph,
                'original-return-inspected',
                'inspected',
                $this->terms->quantity,
                $this->terms->quantity,
                $this->terms->quantity
            );
        $store->request($this->terms->subjectKey(), $origin->runId(), 'original-return-inspection-confirmed');
        $this->pump();
        $this->assertSame(Status::Processing, $this->provider->lookup($payerRefund)->status, $this->progress());
        $this->assertNotContains('refund-incoming', $this->effects());
        $this->assertNotContains('release', $this->effects());
        $this->provider->resolve($payerRefund, Status::Confirmed);
        $store->request($this->terms->subjectKey(), $origin->runId(), 'original-payer-refund-confirmed');
        $this->pump();
        $this->assertSame(1, count(array_filter($this->effects(), static fn (string $v): bool => $v === 'refund-cod')));
        $this->assertSame(
            1,
            count(array_filter($this->effects(), static fn (string $v): bool => $v === 'refund-incoming'))
        );
        $this->assertSame(Status::Confirmed, $this->provider->lookup($advanceRefund)->status);
        $this->assertNotContains('release', $this->effects());
        $this->assertSame('requested', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertSame(
            999,
            $this->mixed->cod->inspect($this->terms->advanceBalance->balance)['facts']['outstanding_remittance_minor']
        );
        $this->remit($balance, 'original-carrier-debt-settled', 999);
        $store->request($this->terms->subjectKey(), $origin->runId(), 'original-merchant-debt-confirmed');
        $this->pump();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $store->finish($this->terms->subjectKey(), $origin->runId(), 'cancel-both-original-portions');
        $this->pump();
        $this->assertSame('cancelled', $this->state('order'));
        $this->assertContains('release', $this->effects());
        $this->assertSame(1, count(array_filter($this->effects(), static fn (string $v): bool => $v === 'refund-cod')));
        $this->assertSame(
            1,
            count(array_filter($this->effects(), static fn (string $v): bool => $v === 'refund-incoming'))
        );
    }
}
