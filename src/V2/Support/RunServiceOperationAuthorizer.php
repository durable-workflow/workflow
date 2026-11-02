<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\WorkflowServiceOperationAuthorizer;
use Workflow\V2\Exceptions\ServiceOperationAuthorizationException;
use Workflow\V2\Models\WorkflowRun;

/** Opt-in policy for trusted embedded hosts: same namespace, unprivileged workload. */
final class RunServiceOperationAuthorizer implements WorkflowServiceOperationAuthorizer
{
    public function authorize(WorkflowRun $run, array $command): ServiceCallPrincipal
    {
        $target = $command['namespace'] ?? $run->namespace;
        if ($target !== $run->namespace) {
            throw new ServiceOperationAuthorizationException();
        }

        return new ServiceCallPrincipal(subject: 'workflow:' . $run->id, method: 'workflow');
    }
}
