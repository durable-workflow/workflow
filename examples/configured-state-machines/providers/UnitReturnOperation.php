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
 * A customer unit return request or its restocking, a damaged-parcel recovery, a carrier claim or the release
 * of an unused replacement reservation. All are monotonic physical, stock or claim facts:
 * compensation fences an unadmitted command or verifies the committed one, never reverses goods movement.
 */
final readonly class UnitReturnOperation implements ScopedOperation
{
    /**
     * @param Closure(OperationContext, string): void $authorize
     */
    public function __construct(
        private Journal $provider,
        private Closure $authorize,
        private string $action,
    ) {
        if (! in_array(
            $action,
            [
                UnitReturnTerms::REQUEST,
                UnitReturnTerms::RESTOCK,
                UnitReturnTerms::RECOVER,
                ShipmentLedger::CLAIM,
                UnitReturnTerms::RELEASE,
            ],
            true
        )) {
            throw new InvalidArgumentException('The trusted unit return stage is unavailable.');
        }
    }

    public function assertInput(array $input): void
    {
        $this->request($input);
    }

    public function execute(OperationContext $context): ForwardResult
    {
        $request = $this->request($context->obligation->input);
        ($this->authorize)($context, 'forward');
        $observed = $context->uncertainForward ? $this->provider->lookup($request) : $this->provider->attempt($request);
        if ($observed->status === Status::Unknown || (($observed->status === Status::Confirmed)
            !== ($observed->effectId !== null))) {
            return ForwardResult::unknown('unit-return-forward-unknown');
        }
        return ForwardResult::observed([
            'operation' => $request->identity->key(),
            'status' => $observed->status->value,
            'effectId' => $observed->effectId,
            'admissionClosed' => $observed->admissionClosed,
        ], [$observed->effectId ?? 'unit-return-status:' . $request->identity->key() . ':' . $observed->status->value]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        $request = $this->request($context->obligation->input);
        ($this->authorize)($context, 'compensate');
        $observed = $this->provider->closeAdmission($request);
        if ($observed->admissionClosed && $observed->effectId === null
            && in_array($observed->status, [Status::NotExecuted, Status::Refused], true)) {
            return Resolution::verified(['provider-fenced:' . $request->identity->key()], true);
        }
        if ($observed->status !== Status::Confirmed || $observed->effectId === null) {
            return Resolution::unknown('unit-return-' . $observed->status->value);
        }
        return Resolution::verified([$observed->effectId], true);
    }

    private function request(array $input): Request
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['request', 'version'] || $input['version'] !== 1 || ! is_array($input['request'])) {
            throw new InvalidArgumentException('The unit return descriptor is malformed.');
        }
        $request = Request::fromArray($input['request']);
        if ($request->action !== $this->action) {
            throw new InvalidArgumentException('The unit return handler received another stage.');
        }
        return $request;
    }
}
