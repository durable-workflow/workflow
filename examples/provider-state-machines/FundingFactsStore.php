<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use Illuminate\Support\Carbon;
use Workflow\V2\Models\WorkflowRun;

/** Called only inside recorded activities. Signals never supply financial facts. */
final readonly class FundingFactsStore
{
    public function __construct(
        private FundingAuthority $authority,
        private Journal $provider
    ) {
    }

    public function observe(WorkflowRun $actual, string $activityId): array
    {
        [, $plan, $terms, $rows] = $this->authority->rows($actual);
        $values = [
            'authorization_admissible' => false,
            'authorization_active' => false,
            'authorization_expired' => false,
            'capture_confirmed' => false,
            'incoming_full_amount_confirmed' => false,
            'merchant_receipt_confirmed' => false,
            'original_stock_reserved' => false,
            'original_recipient_delivered' => false,
            'cod_reference_ready' => false,
            'cod_payer_collection_confirmed' => false,
        ];
        $now = Carbon::now()->getTimestamp();
        $snapshot = null;
        if (CodFundingPolicy::selected($plan->recipe) || $plan->recipe === FundingCatalogue::BANK) {
            $observed = CodFundingPolicy::selected($plan->recipe)
                ? $this->cod($actual, $activityId, $plan, $terms, $rows)
                : $this->bank($actual, $activityId, $plan, $terms, $rows);
            $snapshot = $observed['snapshot'];
            $values = [...$values, ...$observed['values']];
            $now = $observed['observedAt'];
        } elseif (FundingCatalogue::requiresAuthorization($plan->recipe)) {
            $snapshot = app(FinancialInspectionStore::class)->inspect(
                $actual,
                $activityId,
                $plan->childScope('payment')
                    ->toArray(),
                $terms->financialDescriptor()
            );
            $values = [...$values, ...$snapshot['financial']['facts']];
            $values['authorization_active'] = $values['authorization_active']
                && $rows['payment.authorize']['forward_status'] === 'observed';
            $values['capture_confirmed'] = $values['capture_confirmed']
                && $rows['payment.capture']['forward_status'] === 'observed';
            // The selected synthetic direct-acquirer contract confirms merchant receipt with its capture effect.
            $values['merchant_receipt_confirmed'] = $values['capture_confirmed'];
            $instruction = $snapshot['business']['captureInstruction'];
            if ($instruction !== null) {
                $values[$plan->recipe === FundingCatalogue::AFTER_STOCK
                    ? 'original_stock_reserved' : 'original_recipient_delivered'] = true;
            }
            $now = $snapshot['financial']['observedAt'];
        } else {
            $request = Request::fromArray($terms->descriptor('payment')['forward']);
            $value = $this->provider->lookup($request);
            $values['capture_confirmed'] = $rows['effect']['forward_status'] === 'observed'
                && $value->status === Status::Confirmed && $value->effectId === 'effect-' . $request->identity->key();
            $values['merchant_receipt_confirmed'] = $values['capture_confirmed'];
            $snapshot = [
                'original' => $request->toArray(),
                'status' => $value->status->value,
                'effectId' => $value->effectId,
            ];
        }
        return [
            'values' => $values,
            'observations' => $snapshot,
            'observedAt' => $now,
            'waitSeconds' => max(0, FundingCatalogue::deadline($terms) - $now),
            'remittanceWaitSeconds' => $terms->codPayment === null ? null : max(
                0,
                $terms->codPayment->remittanceDeadline - $now
            ),
        ];
    }

    private function cod(
        WorkflowRun $actual,
        string $activityId,
        BindingPlan $plan,
        OrderTerms $terms,
        array $rows
    ): array {
        $snapshot = null;
        $values = [];
        $now = Carbon::now()->getTimestamp();
        if ($rows['payment.cod.payer']['forward_status'] === 'observed') {
            $snapshot = app(CodInspectionStore::class)->inspect(
                $actual,
                $activityId,
                $plan->childScope('payment')
                    ->toArray(),
                $terms->codDescriptor()
            );
            $values['cod_reference_ready'] = true;
            $onTime = 0;
            foreach ($snapshot['cod']['receipts'] as $receipt) {
                if ($receipt['kind'] === CodReceipt::COLLECTION && $receipt['occurredAt'] < $terms->codPayment->collectionDeadline) {
                    $onTime += $receipt['amountMinor'];
                }
            }
            $values['cod_payer_collection_confirmed'] = $onTime >= $terms->codPayment->amountMinor;
            $values['merchant_receipt_confirmed'] = $snapshot['cod']['facts']['merchant_receipt_confirmed'];
            $now = $snapshot['cod']['observedAt'];
        }
        return [
            'snapshot' => $snapshot,
            'values' => $values,
            'observedAt' => $now,
        ];
    }

    private function bank(
        WorkflowRun $actual,
        string $activityId,
        BindingPlan $plan,
        OrderTerms $terms,
        array $rows
    ): array {
        $snapshot = null;
        $values = [];
        $now = Carbon::now()->getTimestamp();
        // An undispatched reference has no provider facts to read or adopt. Registration precedes every receipt.
        if ($rows['payment.incoming']['forward_status'] === 'observed') {
            $snapshot = app(IncomingPaymentInspectionStore::class)->inspect(
                $actual,
                $activityId,
                $plan->childScope('payment')
                    ->toArray(),
                $terms->incomingPayment->toArray()
            );
            $values['incoming_full_amount_confirmed'] = $snapshot['incoming']['requiredAmountReceivedBeforeDeadline'];
            // This policy receives directly into the merchant's bank account; COD remittance is a separate contract.
            $values['merchant_receipt_confirmed'] = $values['incoming_full_amount_confirmed'];
            $now = $snapshot['incoming']['observedAt'];
        }
        return [
            'snapshot' => $snapshot,
            'values' => $values,
            'observedAt' => $now,
        ];
    }
}
