<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use Workflow\V2\WorkflowStub;

/**
 * Carrier-collected balance steps shared by the COD and advance-balance process-loss controls, used with
 * PaymentProcessLoss and a fixture that provides pump(), state(), recordPhysical(), collect() and remit().
 */
trait CarrierBalanceProcessLoss
{
    /**
     * Carrier-side registration and collection closure of one original COD reference.
     */
    private function carrierFacts(CodDescriptor $original): array
    {
        $count = function (string $table, string $column) use ($original): int {
            $statement = $this->providerDb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = ?');
            $statement->execute([$original->identity->key()]);
            return (int) $statement->fetchColumn();
        };
        return [
            'registered' => $count('cod_payment_references', 'id'),
            'closed' => $count('cod_collection_closures', 'payment_id'),
        ];
    }

    /**
     * Counts the cut's carrier effects: the registration of a forward cut, or the closure of a cancellation
     * cut, plus a collected payer's refund.
     *
     * @return Closure(): array<string, int>
     */
    private function carrierCut(CodDescriptor $original, bool $cancel, bool $refund): Closure
    {
        return fn (): array => [
            ...array_intersect_key($this->carrierFacts($original), [
                $cancel ? 'closed' : 'registered' => true,
            ]),
            ...$refund ? $this->providerActions('refund-cod')() : [],
        ];
    }

    /**
     * One registration, a closure only after cancellation, the original terms and deadlines, and silent replay.
     */
    private function assertCarrierRecovered(
        CodPaymentProvider $carrier,
        CodDescriptor $original,
        bool $cancel,
        Closure $committed
    ): void {
        $this->assertCommittedOnce($committed);
        $this->assertSame([
            'registered' => 1,
            'closed' => (int) $cancel,
        ], $this->carrierFacts($original));
        $this->assertSame($original->toArray(), $carrier->inspect($original)['original']);
        $this->assertDeadlineKept('payment', $original->remittanceDeadline);
        $this->assertReplayRequestsNothing('order', 'payment');
    }

    /**
     * Fulfils the original Order; the carrier hands it over and collects the whole balance from the payer.
     *
     * @param Closure(string): void $wake Wakes the original payment with an unauthoritative hint.
     */
    private function collectCarrierBalance(WorkflowStub $origin, int $amount, Closure $wake): CodReceipt
    {
        $origin->withRequestId('fulfill-original-order')
            ->signal('fulfill');
        $this->pump();
        $this->assertSame('booked', $this->state('shipment'), $this->progress());
        $this->recordPhysical('handed-over');
        $receipt = $this->collect('original-payer-collection', $amount);
        $wake('original-payer-collected');
        $this->assertSame('collected', $this->state('payment'), $this->progress());
        return $receipt;
    }

    /**
     * @param Closure(string): void $wake
     */
    private function deliverAndRemitCarrierBalance(CodReceipt $collection, Closure $wake): void
    {
        $this->recordPhysical('delivered');
        $this->remit($collection, 'original-merchant-remittance', $collection->amountMinor);
        $wake('original-merchant-paid');
        $this->assertSame('paid', $this->state('payment'), $this->progress());
        $this->assertSame('fulfilled', $this->state('order'));
    }

    /**
     * Cancellation after payer collection: the original shipment returns and is inspected before money moves.
     */
    private function cancelCollectedOrder(WorkflowStub $origin, string $request): void
    {
        $cancellation = app(CancellationStore::class);
        $key = $this->terms->subjectKey();
        $root = $origin->runId();
        $cancellation->request($key, $root, $request);
        $this->pump();
        $graph = ShipmentDescriptor::fromArray($this->terms->physicalDescriptor());
        $quantity = $this->terms->quantity;
        $this->provider->shipments()
            ->record($graph, 'original-return-received', 'received', $quantity);
        $this->provider->shipments()
            ->record($graph, 'original-return-inspected', 'inspected', $quantity, $quantity, $quantity);
        $cancellation->request($key, $root, 'original-return-inspected');
    }

    /**
     * Recovery verified the payer refund but the carrier still owes the merchant the collected balance, so the
     * unsettled Order stays uncancelled. After the remittance, an authorized recovery request and the
     * proof-only finalizer settle the original cancellation.
     */
    private function remitThenFinishCancellation(CodReceipt $collection, string $request): void
    {
        $this->assertOrderClosedUnsettled('requested');
        $this->assertNotSame('cancelled', $this->subjectStateOf('order'));
        $this->assertNotContains('release', $this->effects());
        $this->remit($collection, 'original-merchant-debt-paid', $collection->amountMinor);
        $cancellation = app(CancellationStore::class);
        $key = $this->terms->subjectKey();
        $run = $this->roleRun('order');
        $root = $run->id;
        $cancellation->request($key, $root, $request, 'recover-after-merchant-remittance');
        $this->drainLikeAWorker();
        $this->assertSame('started', $cancellation->finish($key, $root, $request)['status'], $this->progress());
        $this->drainLikeAWorker();
        $this->assertSame('cancelled', $this->subjectStateOf('order'), $this->progress());
        $this->assertContains('release', $this->effects());
    }
}
