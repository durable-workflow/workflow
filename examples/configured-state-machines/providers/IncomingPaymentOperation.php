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

/** A retained receivable duty, not a card authorization or a successful-payment milestone. */
final readonly class IncomingPaymentOperation implements ScopedOperation
{
    /**
     * @param Closure(OperationContext, string): void $authorize
     * Host must jointly authorize the original business assignment/run/scope/registered input before provider SQL.
     */
    public function __construct(
        private IncomingPaymentProvider $provider,
        private Closure $authorize
    ) {
    }

    public function assertInput(array $input): void
    {
        $this->original($input);
    }

    public function execute(OperationContext $context): ForwardResult
    {
        [$original, $contract] = $this->original($context->obligation->input);
        ($this->authorize)($context, 'forward');
        // Repeating the same originally pinned reference is provider-idempotent; it never reopens a fence.
        $this->provider->receipts
            ->register($original, $contract);
        return ForwardResult::observed([
            'original' => $original->toArray(),
            'admissionContract' => $contract,
        ], ['incoming-reference:' . hash('sha256', PortableDescriptor::encode([
            $original->identity->key(), $original->fingerprint(),
        ]))]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        [$original, $contract] = $this->original($context->obligation->input);
        ($this->authorize)($context, 'compensate');
        $bank = $this->provider->receipts;
        $closed = $contract === IncomingPaymentJournal::CLOSABLE ? $bank->closeAdmission($original, $contract) : null;
        $receipts = $closed === null ? $bank->inspect($original)['receipts'] : $closed['receipts'];
        $evidence = hash_init('sha256');
        foreach ($receipts as $value) {
            $receipt = IncomingPaymentReceipt::fromArray($value);
            $request = self::refundRequest($context->scope, $context->obligation->key, $original, $receipt);
            // Same original refund key across recovery. Processing/unknown/refusal never means reimbursement.
            $refund = $this->provider->commands->attempt($request);
            if ($refund->status !== Status::Confirmed || $refund->effectId === null) {
                return Resolution::unknown('incoming-refund-' . $refund->status->value);
            }
            hash_update($evidence, hash('sha256', PortableDescriptor::encode([
                $receipt->toArray(), $request->toArray(), $refund->effectId,
            ])));
        }
        if ($closed === null) {
            return Resolution::unknown('incoming-admission-open');
        }
        // The independently retained provider journal resolves each original transfer/command/effect in this digest.
        return Resolution::verified([$closed['evidence'], 'incoming-refunds:' . hash_final($evidence)]);
    }

    public static function refundRequest(
        ScopeIdentity $scope,
        string $obligation,
        IncomingPaymentDescriptor $original,
        IncomingPaymentReceipt $receipt,
    ): Request {
        PortableDescriptor::identifier($obligation, 'original incoming obligation');
        if ($receipt->receivable !== $original->identity->key() || $receipt->currency !== $original->currency) {
            throw new InvalidArgumentException('The retained refund must target its original incoming transfer.');
        }
        $operation = 'incoming-refund-' . hash('sha256', PortableDescriptor::encode([
            $scope->toArray(), $obligation, $original->identity->toArray(), $receipt->transfer,
        ]));
        $identity = new Identity(
            $original->identity->client,
            $original->identity->subject,
            $original->identity->generation,
            $operation
        );
        return new Request(
            $identity,
            'refund-incoming',
            (new IncomingRefundTerms($original, $receipt->transfer, $receipt->amountMinor))->toArray(),
            $original->identity
        );
    }

    /**
     * @return array{IncomingPaymentDescriptor, string}
     */
    private function original(array $input): array
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['admissionContract', 'original'] || ! is_array($input['original'])
            || ! in_array(
                $input['admissionContract'],
                [IncomingPaymentJournal::OPEN, IncomingPaymentJournal::CLOSABLE],
                true
            )) {
            throw new InvalidArgumentException(
                'The retained incoming reference or pinned provider contract is malformed.'
            );
        }
        return [IncomingPaymentDescriptor::fromArray($input['original']), $input['admissionContract']];
    }
}
