<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Execution;

/**
 * Host-owned provider semantics. Reconcile uncertainForward before any unsafe reissue.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
interface ScopedOperation
{
    /**
     * Input compatibility is checked outside native SQL locks, before forward admission.
     */
    public function assertInput(array $input): void;

    public function execute(OperationContext $context): ForwardResult;

    /**
     * Return verified only with authoritative compensation AND settled-forward evidence.
     */
    public function compensate(OperationContext $context): Resolution;
}
