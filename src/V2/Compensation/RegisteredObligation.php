<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation;

use LogicException;
use Workflow\V2\Compensation\Activities\ForwardOperationActivity;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\WorkflowFiberContext;
use Workflow\V2\Workflow;

/**
 * A recorded obligation whose forward operation has not necessarily been dispatched.
 * Engine-owned activity authority and the original scope gate still apply to every execution.
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
        );
    }
}
