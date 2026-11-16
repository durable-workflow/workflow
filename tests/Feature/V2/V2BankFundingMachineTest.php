<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use Illuminate\Support\Carbon;
use Tests\Support\CommonFundingMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Recorded native source integration; no installed, cold-process or complete customer-matrix qualification. */
final class V2BankFundingMachineTest extends TestCase
{
    use CommonFundingMachineFixture;

    public function testPartialAndSpoofedPaymentCannotReserveOrFulfillAndDuplicateReceiptDoesNotRepeatWork(): void
    {
        $this->startFunding(FundingCatalogue::BANK);
        $partial = $this->receive('first-portion', 500);
        $this->wakePayment('untrusted-full-payment-hint');
        $this->assertSame('received', $this->state('order'), $this->progress());
        $this->assertSame('received', $this->state('payment'));
        $this->assertSame([], $this->effects());
        $this->incoming->receipts->record($this->terms->incomingPayment, $partial);
        $this->receive('original-balance', 999);
        $this->wakePayment('balance-confirmed');
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertSame('paid', $this->state('payment'));
        $this->assertSame(['reserve'], $this->effects());
        $this->assertSame(
            2,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM incoming_payment_receipts')
                ->fetchColumn()
        );
        $this->assertFalse($this->wakePayment('duplicate-committed-payment'));
        $this->assertSame(['reserve'], $this->effects());
    }

    public function testCancellationWaitsForOriginalBankRefundBeforeReleasingReservedStock(): void
    {
        $origin = $this->startFunding(FundingCatalogue::BANK);
        $receipt = $this->receive('original-money', 1499);
        $this->wakePayment('incoming-confirmed');
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $refund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->incomingPayment,
            $receipt
        );
        $this->provider->plan($refund, Status::Processing);
        $store = app(CancellationStore::class);
        $store->request($this->terms->subjectKey(), $origin->runId(), 'original-bank-cancellation');
        $this->pump();
        $this->assertSame(
            'requested',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame(['reserve'], $this->effects());
        $this->assertSame(
            'pending',
            $store->finish($this->terms->subjectKey(), $origin->runId(), 'original-bank-cancellation')['status']
        );
        $this->provider->resolve($refund, Status::Confirmed);
        $store->request($this->terms->subjectKey(), $origin->runId(), 'original-refund-confirmed');
        $this->pump();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame(['reserve', 'refund-incoming', 'release'], $this->effects());
        $store->finish($this->terms->subjectKey(), $origin->runId(), 'original-bank-cancellation');
        $this->pump();
        $this->assertSame('cancelled', $this->state('order'), $this->progress());
        $this->assertSame(
            1499,
            $this->incoming->receipts->inspect($this->terms->incomingPayment)['receivedAmountMinor']
        );
    }

    public function testUnknownReservationDoesNotRefundMoneyUntilOriginalStockRefusalIsConfirmed(): void
    {
        $origin = $this->startFunding(FundingCatalogue::BANK, Status::Unknown);
        $receipt = $this->receive('paid-before-stock', 1499);
        $payment = WorkflowStub::loadRun($this->child('payment')->id, Catalogue::NAMESPACE);
        $payment->withRequestId('bank-paid-stock-unknown')
            ->signal('incoming-observed');
        $stock = Request::fromArray($this->terms->descriptor('order')['forward']);
        // Stop at the original uncertain provider attempt, before the finite native retry policy is exhausted.
        $this->drain(function () use ($stock): bool {
            $query = $this->providerDb->prepare('SELECT COUNT(*) FROM provider_operations WHERE id = ? AND status = ?');
            $query->execute([$stock->identity->key(), Status::Unknown->value]);
            return (int) $query->fetchColumn() === 1;
        });
        $this->assertSame('received', $this->state('order'), $this->progress());
        $this->assertSame('paid', $this->state('payment'));
        $this->assertSame([], $this->effects());
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $refund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->incomingPayment,
            $receipt
        );
        $this->provider->plan($refund, Status::Confirmed);
        $this->provider->resolve($stock, Status::Refused);
        $this->pump();
        $this->assertSame(['refund-incoming'], $this->effects(), $this->progress());
        $this->assertSame('verified', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertNotSame('ready', $this->state('order'));
        app(CancellationStore::class)->finish($this->terms->subjectKey(), $origin->runId(), 'stock-refused');
        $this->pump();
        $this->assertSame('cancelled', $this->state('order'), $this->progress());
    }

    public function testLateOriginalPaymentIsRefundedInsteadOfPromotedToFulfillment(): void
    {
        $origin = $this->startFunding(FundingCatalogue::BANK);
        $deadline = $this->terms->incomingPayment->deadline;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($deadline + 1));
        $receipt = $this->receive('late-after-original-deadline', 1499);
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $refund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->incomingPayment,
            $receipt
        );
        $this->provider->plan($refund, Status::Confirmed);
        $this->wakePayment('late-paid-hint');
        $this->assertSame(['refund-incoming'], $this->effects(), $this->progress());
        $this->assertNotSame('ready', $this->state('order'));
        $this->assertNotSame('paid', $this->state('payment'));
        $this->assertSame($deadline, $this->terms->incomingPayment->deadline);
        $this->assertSame('verified', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertSame(
            1499,
            $this->incoming->receipts->inspect($this->terms->incomingPayment)['receivedAmountMinor']
        );
    }

    public function testOpenBankAdmissionRetainsAndRefundsNewMoneyAfterBusinessCancellation(): void
    {
        $origin = $this->startFunding(FundingCatalogue::BANK, Status::Confirmed, IncomingPaymentJournal::OPEN);
        $first = $this->receive('partial-before-cancellation', 500);
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $refund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->incomingPayment,
            $first
        );
        $this->provider->plan($refund, Status::Confirmed);
        app(CancellationStore::class)->request(
            $this->terms->subjectKey(),
            $origin->runId(),
            'cancel-open-bank-reference'
        );
        $this->pump();
        $this->assertSame('requested', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $late = $this->receive('late-after-cancellation', 999);
        $lateRefund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->incomingPayment,
            $late
        );
        $this->provider->plan($lateRefund, Status::Confirmed);
        app(CancellationStore::class)->request(
            $this->terms->subjectKey(),
            $origin->runId(),
            'reconcile-late-open-bank-money'
        );
        $this->pump();
        $this->assertSame(['refund-incoming', 'refund-incoming'], $this->effects(), $this->progress());
        $this->assertSame('requested', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertSame('received', $this->state('order'));
        $this->assertSame('received', $this->state('payment'));
        $this->assertSame(
            1499,
            $this->incoming->receipts->inspect($this->terms->incomingPayment)['receivedAmountMinor']
        );
    }
}
