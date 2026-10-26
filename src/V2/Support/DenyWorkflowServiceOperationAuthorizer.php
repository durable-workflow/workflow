<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\WorkflowServiceOperationAuthorizer;
use Workflow\V2\Exceptions\ServiceOperationAuthorizationException;
use Workflow\V2\Models\WorkflowRun;

/** Hosts must explicitly provide trusted service authority. */
final class DenyWorkflowServiceOperationAuthorizer implements WorkflowServiceOperationAuthorizer
{
    public function authorize(WorkflowRun $run, array $command): ServiceCallPrincipal
    {
        throw new ServiceOperationAuthorizationException();
    }
}
