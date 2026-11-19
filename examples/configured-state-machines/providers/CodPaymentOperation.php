<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

/** Payer reimbursement and carrier/merchant settlement are separately retained responsibilities. */
final readonly class CodPaymentOperation implements ScopedOperation
{
    /**
     * @param Closure(OperationContext, string, string): void $authorize
     */
    public function __construct(
        private CodPaymentProvider $provider,
        private Closure $authorize,
        private string $stage
    ) {
        if (! in_array($stage, ['payer', 'merchant'], true)) {
            throw new InvalidArgumentException('The original COD responsibility is unavailable.');
        }
    }

    public function assertInput(array $input): void
    {
        self::originalInput($input);
    }

    public function execute(OperationContext $context): ForwardResult
    {
        [$original, $contract] = self::originalInput($context->obligation->input);
        ($this->authorize)($context, 'forward', $this->stage);
        if ($this->stage === 'payer') {
            $this->provider->collections->register($original, $contract);
        }
        // Merchant responsibility is declared before opening collection; this never confirms merchant payment.
        return ForwardResult::observed($context->obligation->input, [
            'original-cod-' . $this->stage . ':' . $original->fingerprint(),
        ]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        [$original, $contract] = self::originalInput($context->obligation->input);
        ($this->authorize)($context, 'compensate', $this->stage);
        $closed = $contract === CodJournal::CLOSABLE
            ? $this->provider->collections->closeAdmission($original, $contract) : null;
        $receipts = $closed['collections'] ?? array_values(array_filter(
            $this->provider->collections->inspect($original)['receipts'],
            static fn (array $receipt): bool => $receipt['kind'] === CodReceipt::COLLECTION
        ));
        if ($this->stage === 'merchant') {
            return $this->settleMerchant($original, $closed, $receipts);
        }
        return $this->refundPayer($context, $original, $closed, $receipts);
    }

    public static function refundRequest(
        ScopeIdentity $scope,
        string $obligation,
        CodDescriptor $original,
        CodReceipt $receipt
    ): Request {
        PortableDescriptor::identifier($obligation, 'original COD obligation');
        if ($receipt->kind !== CodReceipt::COLLECTION || $receipt->payment !== $original->identity->key()
            || $receipt->currency !== $original->currency || $receipt->target !== $original->collectionPoint) {
            throw new InvalidArgumentException('COD reimbursement must retain its original payer collection.');
        }
        return new Request(
            new Identity(
                $original->identity->client,
                $original->identity->subject,
                $original->identity->generation,
                'cod-refund-' . hash('sha256', PortableDescriptor::encode([
                    $scope->toArray(), $obligation, $original->identity->toArray(), $receipt->receipt,
                ]))
            ),
            'refund-cod',
            (new CodRefundTerms($original, $receipt->receipt, $receipt->amountMinor))->toArray(),
            $original->identity
        );
    }

    /**
     * @return array{CodDescriptor, string}
     */
    public static function originalInput(array $input): array
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['admissionContract', 'original'] || ! is_array($input['original'])
            || ! in_array($input['admissionContract'], [CodJournal::OPEN, CodJournal::CLOSABLE], true)) {
            throw new InvalidArgumentException(
                'The original COD responsibility or pinned carrier contract is malformed.'
            );
        }
        return [CodDescriptor::fromArray($input['original']), $input['admissionContract']];
    }

    private function settleMerchant(CodDescriptor $original, ?array $closed, array $receipts): Resolution
    {
        // Closing collection alone cannot settle an existing carrier/merchant balance.
        if ($closed === null) {
            return Resolution::unknown('cod-original-merchant-settlement-open');
        }
        if ($receipts === []) {
            return Resolution::verified([$closed['evidence']]);
        }
        $snapshot = $this->provider->collections->inspect($original);
        return $snapshot['facts']['outstanding_remittance_minor'] === 0 ? Resolution::verified([
            $closed['evidence'],
            'cod-merchant-remittance:' . hash('sha256', PortableDescriptor::encode($snapshot['receipts'])),
        ]) : Resolution::unknown('cod-original-merchant-settlement-open');
    }

    private function refundPayer(
        OperationContext $context,
        CodDescriptor $original,
        ?array $closed,
        array $receipts
    ): Resolution {
        $evidence = hash_init('sha256');
        foreach ($receipts as $value) {
            $receipt = CodReceipt::fromArray($value);
            $request = self::refundRequest($context->scope, $context->obligation->key, $original, $receipt);
            $refund = $this->provider->commands->attempt($request);
            if ($refund->status !== Status::Confirmed || $refund->effectId === null) {
                return Resolution::unknown('cod-customer-refund-' . $refund->status->value);
            }
            hash_update($evidence, hash('sha256', PortableDescriptor::encode([
                $receipt->toArray(), $request->toArray(), $refund->effectId,
            ])));
        }
        return $closed === null ? Resolution::unknown('cod-original-collection-admission-open')
            : Resolution::verified([$closed['evidence'], 'cod-customer-refunds:' . hash_final($evidence)]);
    }
}
