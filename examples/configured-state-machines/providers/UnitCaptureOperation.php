<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use InvalidArgumentException;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

/**
 * Captured authorized money with an explicit original unit quote. Cancellation repays only the original
 * units that no earlier confirmed repayment of the same capture already covered.
 */
final readonly class UnitCaptureOperation implements ScopedOperation
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
        self::decode($input);
    }

    public function execute(OperationContext $context): ForwardResult
    {
        [$financial, $command] = self::decode($context->obligation->input);
        ($this->authorize)($context, 'forward');
        // The quote is pinned in the independent payment service before capture admission.
        $this->refunds->register($command->original);
        $capture = $financial->capture;
        $observed = $context->reconcileBeforeForward ? $this->provider->lookup($capture) : $this->provider->attempt(
            $capture
        );
        if ($observed->status === Status::Unknown || (($observed->status === Status::Confirmed)
            !== ($observed->effectId !== null))) {
            return ForwardResult::unknown('financial-forward-unknown');
        }
        return ForwardResult::observed([
            'operation' => $capture->identity->key(),
            'status' => $observed->status->value,
            'effectId' => $observed->effectId,
            'admissionClosed' => $observed->admissionClosed,
            'allocationFingerprint' => $command->original->fingerprint(),
        ], [$observed->effectId ?? 'financial-status:' . $capture->identity->key() . ':' . $observed->status->value]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        [$financial, $command] = self::decode($context->obligation->input);
        ($this->authorize)($context, 'compensate');
        $capture = $this->provider->closeAdmission($financial->capture);
        if ($capture->admissionClosed && $capture->effectId === null
            && in_array($capture->status, [Status::NotExecuted, Status::Refused], true)) {
            return Resolution::verified(['provider-fenced:' . $financial->capture->identity->key()]);
        }
        if ($capture->status !== Status::Confirmed || $capture->effectId === null) {
            return Resolution::unknown('financial-capture-' . $capture->status->value);
        }
        $result = $this->refunds->attempt($command);
        if ($result['confirmedUnits'] !== $command->units
            || $result['confirmedAmountMinor'] !== $financial->capture->terms['amountMinor']) {
            return Resolution::unknown('unit-capture-refund-' . $result['status']);
        }
        return Resolution::verified([$capture->effectId, 'unit-refund-receipts:' . $result['receiptsSha256']]);
    }

    /**
     * @return array{FinancialDescriptor, UnitRefundCommand}
     */
    public static function decode(array $input): array
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['financial', 'refund', 'version'] || $input['version'] !== 1
            || ! is_array($input['financial']) || ! is_array($input['refund'])) {
            throw new InvalidArgumentException('The unit-accounted capture descriptor is malformed.');
        }
        $financial = FinancialDescriptor::fromArray($input['financial']);
        $command = UnitRefundCommand::fromArray($input['refund']);
        if ($command->original->capture->fingerprint() !== $financial->capture->fingerprint()
            || $command->units !== array_keys($command->original->amounts)) {
            throw new InvalidArgumentException('Capture compensation must retain its original capture and every unit.');
        }
        return [$financial, $command];
    }
}
