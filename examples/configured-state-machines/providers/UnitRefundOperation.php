<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

/** A customer repayment is monotonic: compensation fences or verifies it and never reverses the money. */
final readonly class UnitRefundOperation implements ScopedOperation
{
    /**
     * @param Closure(OperationContext, string): void $authorize
     */
    public function __construct(
        Journal $provider,
        private UnitRefundGateway $refunds,
        private Closure $authorize,
    ) {
        $refunds->assertProvider($provider);
    }

    public function assertInput(array $input): void
    {
        UnitRefundCommand::fromObligation($input);
    }

    public function execute(OperationContext $context): ForwardResult
    {
        $command = UnitRefundCommand::fromObligation($context->obligation->input);
        ($this->authorize)($context, 'forward');
        $result = $context->reconcileBeforeForward ? $this->refunds->lookup($command) : $this->refunds->attempt(
            $command
        );
        if ($result['status'] === Status::Unknown->value && $result['confirmedUnits'] !== $command->units) {
            return ForwardResult::unknown('original-unit-refund-unknown');
        }
        // Two bounded references: each evidence identifier is limited to 128 bytes.
        return ForwardResult::observed($result, [
            'original-unit-refund:' . $command->id(),
            'original-unit-refund-receipts:' . $result['receiptsSha256'],
        ]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        $command = UnitRefundCommand::fromObligation($context->obligation->input);
        ($this->authorize)($context, 'compensate');
        $result = $this->refunds->closeAdmission($command);
        if ($result['admissionClosed'] && $result['ownPayoutId'] === null
            && in_array($result['status'], [Status::NotExecuted->value, Status::Refused->value], true)) {
            return Resolution::verified(['original-unit-refund-fenced:' . $command->id()]);
        }
        if ($result['confirmedUnits'] !== $command->units) {
            return Resolution::unknown('original-unit-refund-' . $result['status']);
        }
        return Resolution::verified(['original-unit-refund-receipts:' . $result['receiptsSha256']]);
    }
}
