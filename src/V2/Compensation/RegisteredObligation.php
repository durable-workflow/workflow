<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation;

use LogicException;
use Workflow\V2\Compensation\Activities\CompensateObligationActivity;
use Workflow\V2\Compensation\Activities\ForwardOperationActivity;
use Workflow\V2\Compensation\Activities\RequestScopeActivity;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\WorkflowFiberContext;
use Workflow\V2\Workflow;

/**
 * A recorded obligation whose forward operation has not necessarily been dispatched.
 * Engine-owned activity authority and the original scope gate still apply to every execution.
 *
 * Public authoring API: DurableScope::register() returns it, and workflow code
 * calls execute() and compensate() on it. Only its constructor is internal.
 * It is part of the API planned to become stable in a later minor release (#1063).
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final readonly class RegisteredObligation
{
    /**
     * @internal Obtain this value from DurableScope::register() inside a native workflow.
     */
    public function __construct(
        private ScopeIdentity $identity,
        private string $id,
        private string $principal,
        private bool $valueLimits = false,
    ) {
        if (! WorkflowFiberContext::active() || preg_match('/\A[0-9a-f]{64}\z/D', $id) !== 1) {
            throw new LogicException('A registered obligation requires its recorded native workflow context.');
        }
    }

    public function execute(?ActivityOptions $options = null): array
    {
        if (! WorkflowFiberContext::active()) {
            throw new LogicException('The registered forward operation must be authored inside its native workflow.');
        }
        return Workflow::activity(
            ForwardOperationActivity::class,
            $options ?? new ActivityOptions(),
            $this->identity->toArray(),
            $this->id,
            $this->principal,
            // Recorded only under the registration-checks patch, so earlier histories replay unchanged.
            ...($this->valueLimits ? [true] : []),
        );
    }

    /**
     * Resolve this original obligation through the same native boundary as its registered Saga callback.
     * Later callbacks observe the verified result. Native reverse ordering and unknown-outcome refusal still apply.
     */
    public function compensate(?ActivityOptions $options = null): void
    {
        if (! WorkflowFiberContext::active()) {
            throw new LogicException('Registered compensation must be authored inside its native workflow.');
        }
        Workflow::activity(RequestScopeActivity::class, $this->identity->toArray(), $this->principal);
        if ($options === null) {
            Workflow::activity(CompensateObligationActivity::class, $this->identity->toArray(), $this->id);
        } else {
            Workflow::activity(CompensateObligationActivity::class, $options, $this->identity->toArray(), $this->id);
        }
    }
}
