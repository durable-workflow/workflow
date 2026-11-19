<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use InvalidArgumentException;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

/** One original capture, with cancellation settled by receipts for every originally charged unit. */
final readonly class UnitPaymentOperation implements ScopedOperation
{
    /**
     * @param Closure(OperationContext, string): void $authorize
     */
    public function __construct(
        private Journal $provider,
        private UnitRefundGateway $refunds,
        private Closure $authorize,
    ) {
        $refunds->assertProvider($provider);
    }

    public function assertInput(array $input): void
    {
        $this->command($input);
    }

    public function execute(OperationContext $context): ForwardResult
    {
        $command = $this->command($context->obligation->input);
        ($this->authorize)($context, 'forward');
        // This independent provider transaction precedes original capture admission.
        $this->refunds->register($command->original);
        $capture = $command->original->capture;
        $result = $context->reconcileBeforeForward ? $this->provider->lookup($capture) : $this->provider->attempt(
            $capture
        );
        if ($result->status === Status::Unknown || (($result->status === Status::Confirmed) !== ($result->effectId !== null))) {
            return ForwardResult::unknown('unit-payment-forward-unknown');
        }
        return ForwardResult::observed([
            'operation' => $capture->identity->key(),
            'status' => $result->status->value,
            'effectId' => $result->effectId,
            'admissionClosed' => $result->admissionClosed,
            'allocationFingerprint' => $command->original->fingerprint(),
        ], [$result->effectId ?? 'unit-payment-status:' . $capture->identity->key() . ':' . $result->status->value]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        $command = $this->command($context->obligation->input);
        ($this->authorize)($context, 'compensate');
        $capture = $command->original->capture;
        $original = $this->provider->closeAdmission($capture);
        if (in_array($original->status, [Status::NotExecuted, Status::Refused], true) && $original->effectId === null) {
            return Resolution::verified(['unit-payment-fenced:' . $capture->identity->key()]);
        }
        if ($original->status !== Status::Confirmed || $original->effectId === null) {
            return Resolution::unknown('unit-payment-forward-' . $original->status->value);
        }
        $result = $this->refunds->attempt($command);
        // A terminal refused command stays refused. Independently verified repayments by another
        // authorized remedy can nevertheless satisfy the same original financial obligation.
        if ($result['confirmedUnits'] !== $command->units
            || $result['confirmedAmountMinor'] !== $capture->terms['amountMinor']) {
            return Resolution::unknown('unit-payment-refund-' . $result['status']);
        }
        return Resolution::verified([$original->effectId, 'unit-refund-receipts:' . $result['receiptsSha256']]);
    }

    private function command(array $input): UnitRefundCommand
    {
        $command = UnitRefundCommand::fromObligation($input);
        if ($command->units !== array_keys($command->original->amounts)) {
            throw new InvalidArgumentException('Payment compensation must retain every original charged unit.');
        }
        return $command;
    }
}
