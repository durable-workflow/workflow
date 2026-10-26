<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ServiceCallPrincipal;

/**
 * Server-owned authority for workflow service operations.
 *
 * The command supplies routing intent only. Implementations must authorize
 * that intent against trusted host/run context and return its authenticated
 * principal, never a principal reconstructed from command fields. Throw
 * ServiceOperationAuthorizationException to refuse the entire worker turn.
 * This check must not reserve admission budgets or perform external effects;
 * preflight and transaction retries can invoke it more than once.
 */
interface WorkflowServiceOperationAuthorizer
{
    /**
     * @param array<string, mixed> $command
     */
    public function authorize(WorkflowRun $run, array $command): ServiceCallPrincipal;
}
