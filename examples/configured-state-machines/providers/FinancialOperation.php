<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use InvalidArgumentException;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

/** Separate original obligations own authorization permission and captured money. */
final readonly class FinancialOperation implements ScopedOperation
{
    /**
     * @param Closure(OperationContext, string): void $authorize
     * Host validates the original scope, descriptor and paired financial obligations before provider SQL.
     */
    public function __construct(
        private Journal $provider,
        private Closure $authorize,
        private string $stage,
    ) {
        if (! in_array($stage, ['authorize', 'capture'], true)) {
            throw new InvalidArgumentException('The trusted financial handler stage is unavailable.');
        }
    }

    public function assertInput(array $input): void
    {
        $this->requests($input);
    }

    public function execute(OperationContext $context): ForwardResult
    {
        $requests = $this->requests($context->obligation->input);
        ($this->authorize)($context, 'forward');
        $forward = $requests[$this->stage];
        $observed = $context->reconcileBeforeForward ? $this->provider->lookup($forward) : $this->provider->attempt(
            $forward
        );
        if ($observed->status === Status::Unknown || (($observed->status === Status::Confirmed)
            !== ($observed->effectId !== null))) {
            return ForwardResult::unknown('financial-forward-unknown');
        }
        return ForwardResult::observed([
            'operation' => $forward->identity->key(),
            'status' => $observed->status->value,
            'effectId' => $observed->effectId,
            'admissionClosed' => $observed->admissionClosed,
        ], [$observed->effectId ?? 'financial-status:' . $forward->identity->key() . ':' . $observed->status->value]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        $requests = $this->requests($context->obligation->input);
        ($this->authorize)($context, 'compensate');
        return $this->stage === 'capture' ? $this->settleCapture($requests) : $this->settleAuthorization($requests);
    }

    private function settleAuthorization(array $requests): Resolution
    {
        // Also fence the exact future capture when permission was granted but the trigger never arrived.
        $authorization = $this->provider->closeAdmission($requests['authorize']);
        $capture = $this->provider->closeAdmission($requests['capture']);
        if ($capture->status === Status::Confirmed && $capture->effectId !== null
            && $authorization->status === Status::Confirmed && $authorization->effectId !== null) {
            // Full capture consumed this permission. The distinct capture obligation still owns its refund.
            return Resolution::verified([$authorization->effectId, $capture->effectId,
                'authorization-consumed:' . $requests['authorize']->identity->key()]);
        }
        if (! $this->absent($capture)) {
            return Resolution::unknown('financial-capture-' . $capture->status->value);
        }
        return $this->settleUnusedAuthorization($requests, $authorization);
    }

    private function settleUnusedAuthorization(array $requests, Observation $authorization): Resolution
    {
        if ($this->absent($authorization)) {
            return Resolution::verified([
                'provider-fenced:' . $requests['authorize']->identity->key(),
                'provider-fenced:' . $requests['capture']->identity->key(),
            ]);
        }
        if ($authorization->status !== Status::Confirmed || $authorization->effectId === null) {
            return Resolution::unknown('financial-authorization-' . $authorization->status->value);
        }
        return $this->undo($requests['void'], [$authorization->effectId,
            'provider-fenced:' . $requests['capture']->identity->key()]);
    }

    private function settleCapture(array $requests): Resolution
    {
        $capture = $this->provider->closeAdmission($requests['capture']);
        if ($this->absent($capture)) {
            return Resolution::verified(['provider-fenced:' . $requests['capture']->identity->key()]);
        }
        if ($capture->status !== Status::Confirmed || $capture->effectId === null) {
            return Resolution::unknown('financial-capture-' . $capture->status->value);
        }
        return $this->undo($requests['refund'], [$capture->effectId]);
    }

    private function undo(Request $request, array $originalEvidence): Resolution
    {
        // Only this simulator's proven same-key idempotency permits a repeated command request.
        $observed = $this->provider->attempt($request);
        if ($observed->status !== Status::Confirmed || $observed->effectId === null) {
            return Resolution::unknown('financial-compensation-' . $observed->status->value);
        }
        return Resolution::verified([...$originalEvidence, $observed->effectId]);
    }

    private function absent(Observation $observed): bool
    {
        return $observed->admissionClosed && $observed->effectId === null
            && in_array($observed->status, [Status::NotExecuted, Status::Refused], true);
    }

    /**
     * @return array{authorize: Request, capture: Request, void: Request, refund: Request}
     */
    private function requests(array $input): array
    {
        return FinancialDescriptor::fromArray($input)->requests();
    }
}
