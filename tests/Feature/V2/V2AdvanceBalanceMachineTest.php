<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ProviderStateMachines\AdvanceBalancePayment;
use DurableWorkflow\Examples\ProviderStateMachines\AdvanceBalancePolicy;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdvanceBalanceMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Support\WorkflowReplayer;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Recorded native original advance and carrier-balance routes. */
final class V2AdvanceBalanceMachineTest extends TestCase
{
    use AdvanceBalanceMachineFixture;

    public static function policies(): array
    {
        return array_map(static fn (string $recipe): array => [$recipe], AdvanceBalancePolicy::recipes());
    }

    #[DataProvider('policies')]
    public function testAdvancePermitsPreparationButOnlyBothOriginalPortionsAndMerchantReceiptSettleThePurchase(
        string $recipe
    ): void {
        $origin = $this->startAdvance($recipe);
        $this->assertSame(AdvanceBalancePayment::class, $this->child('payment')->workflow_class);
        $this->assertSame('received', $this->state('order'), $this->progress());
        $this->assertSame([], $this->effects());
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $ledger = (new ScopeRepository())->inspect($plan->childScope('payment'));
        $this->assertSame(['owner', 'payment.cod.merchant', 'payment.incoming', 'payment.cod.payer'], array_map(
            static fn (array $row): string => Obligation::fromArray($row['descriptor'])->key,
            $ledger['obligations']
        ));
        $this->wake('incoming-observed', 'spoofed-bank-paid');
        $this->wake('cod-observed', 'spoofed-carrier-paid');
        $this->assertSame('received', $this->state('payment'));
        $this->assertSame([], $this->effects());
        $this->receive('first-advance-portion', 200);
        $this->wake('incoming-observed', 'partial-advance');
        $this->assertSame('received', $this->state('order'));
        $this->receive('remaining-advance-portion', 300);
        $this->wake('incoming-observed', 'original-advance-confirmed');
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertSame('ready', $this->state('payment'));
        $this->assertSame(['reserve'], $this->effects());
        $advanceDeadline = $this->terms->advanceBalance->advance->deadline;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($advanceDeadline + 1));
        $this->pump();
        $this->assertSame('ready', $this->state('payment'), $this->progress());
        $this->assertSame($advanceDeadline, $this->terms->advanceBalance->advance->deadline);
        $origin->withRequestId('fulfill-after-original-advance')
            ->signal('fulfill');
        $this->pump();
        $this->assertSame('booked', $this->state('shipment'), $this->progress());
        $this->recordPhysical('handed-over');
        if ($recipe === AdvanceBalancePolicy::AFTER) {
            $this->recordPhysical('delivered');
        }
        $receipt = $this->collect('original-carrier-balance', 999);
        $this->wake('cod-observed', 'original-balance-collected');
        $this->assertSame('collected', $this->state('payment'), $this->progress());
        if ($recipe === AdvanceBalancePolicy::BEFORE) {
            $this->recordPhysical('delivered');
        }
        $this->assertSame('ready', $this->state('order'));
        $this->remit($receipt, 'partial-merchant-balance', 400);
        $this->wake('cod-observed', 'partial-balance-remittance');
        $this->assertSame('collected', $this->state('payment'));
        $deadline = $this->terms->advanceBalance->balance->remittanceDeadline;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($deadline + 1));
        $this->pump();
        $this->wake('cod-observed', 'original-merchant-deadline-passed');
        $this->assertSame('collected', $this->state('payment'), $this->progress());
        $this->assertSame('ready', $this->state('order'));
        $this->assertSame(
            599,
            $this->mixed->cod->inspect($this->terms->advanceBalance->balance)['facts']['outstanding_remittance_minor']
        );
        $this->remit($receipt, 'remaining-merchant-balance', 599);
        $this->wake('cod-observed', 'original-full-merchant-receipt');
        $this->assertSame('paid', $this->state('payment'), $this->progress());
        $this->assertSame('fulfilled', $this->state('order'));
        $this->assertSame(
            500,
            $this->mixed->incoming->receipts->inspect($this->terms->advanceBalance->advance)['receivedAmountMinor']
        );
        $this->assertSame(['reserve', 'ship-book', 'deduct-stock'], $this->effects());
        $effects = $this->effects();
        app(WorkflowReplayer::class)->replay($this->child('payment'));
        $this->assertSame($effects, $this->effects());
    }
}
