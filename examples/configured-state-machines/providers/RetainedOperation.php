<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use InvalidArgumentException;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

/** Provider I/O belongs in native retained activities; portable descriptors contain no executable callback. */
final readonly class RetainedOperation implements ScopedOperation
{
    /**
     * @param Closure(OperationContext, string): void $authorize
     * Host must assert the complete original scope/run/descriptor/business-owner relationship before provider SQL.
     */
    public function __construct(
        private Journal $provider,
        private Closure $authorize,
    ) {
    }

    public function assertInput(array $input): void
    {
        $this->requests($input);
    }

    public function execute(OperationContext $context): ForwardResult
    {
        [$forward] = $this->requests($context->obligation->input);
        ($this->authorize)($context, 'forward');
        $result = $context->reconcileBeforeForward ? $this->provider->lookup($forward) : $this->provider->attempt(
            $forward
        );
        if ($result->status === Status::Unknown || (($result->status === Status::Confirmed) !== ($result->effectId !== null))) {
            return ForwardResult::unknown('provider-forward-unknown');
        }
        // An observed processing/refused outcome is knowledge, not a successful payment or shipment milestone.
        return ForwardResult::observed([
            'operation' => $forward->identity->key(),
            'status' => $result->status->value,
            'effectId' => $result->effectId,
            'admissionClosed' => $result->admissionClosed,
        ], [$result->effectId ?? 'provider-status:' . $forward->identity->key() . ':' . $result->status->value]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        [$forward, $undo] = $this->requests($context->obligation->input);
        ($this->authorize)($context, 'compensate');
        // Closing admission is an authoritative provider fence, not an inference from a missing lookup.
        $original = $this->provider->closeAdmission($forward);
        if (in_array($original->status, [Status::NotExecuted, Status::Refused], true) && $original->effectId === null) {
            return Resolution::verified(['provider-fenced:' . $forward->identity->key()]);
        }
        if ($original->status !== Status::Confirmed || $original->effectId === null) {
            return Resolution::unknown('provider-forward-' . $original->status->value);
        }
        // This simulator guarantees same-key idempotency; a real provider adapter must supply that contract.
        $result = $this->provider->attempt($undo);
        if ($result->status !== Status::Confirmed || $result->effectId === null) {
            return Resolution::unknown('provider-compensation-' . $result->status->value);
        }
        return Resolution::verified([$original->effectId, $result->effectId]);
    }

    /**
     * @return array{Request, Request}
     */
    private function requests(array $input): array
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['compensation', 'forward'] || ! is_array($input['forward']) || ! is_array(
            $input['compensation']
        )) {
            throw new InvalidArgumentException('The retained provider descriptor is malformed.');
        }
        $forward = Request::fromArray($input['forward']);
        $undo = Request::fromArray($input['compensation']);
        $expected = match ($forward->action) {
            'capture' => 'refund',
            'book' => 'cancel-booking',
            'reserve' => 'release',
            default => throw new InvalidArgumentException('The retained forward action is unavailable.'),
        };
        if ($forward->target !== null || $undo->action !== $expected
            || $undo->target?->toArray() !== $forward->identity->toArray()) {
            throw new InvalidArgumentException('Compensation must target this exact original forward command.');
        }
        return [$forward, $undo];
    }
}
