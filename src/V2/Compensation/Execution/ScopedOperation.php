<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Execution;

/**
 * Host-owned provider semantics. Reconcile by operation ID (see OperationContext) before any unsafe reissue.
 *
 * @api Stable compensation-scope API (#1063). See docs/api-stability.md#compensation-scopes.
 */
interface ScopedOperation
{
    public function assertInput(array $input): void;

    public function execute(OperationContext $context): ForwardResult;

    /**
     * Return verified only with authoritative compensation AND settled-forward evidence.
     */
    public function compensate(OperationContext $context): Resolution;
}
