<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use LogicException;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

/** A genuine no-effect ownership obligation opens the scope before any transition can start. */
final readonly class OwnerOperation implements ScopedOperation
{
    public function __construct(
        private MachineAuthority $store
    ) {
    }

    public function assertInput(array $input): void
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['entry', 'nativeRun', 'role', 'rootRun', 'subject']) {
            throw new LogicException('The original owner descriptor is malformed.');
        }
        foreach ($input as $part) {
            if (! is_string($part)) {
                throw new LogicException('Owner descriptor fields must be strings.');
            }
        }
    }

    public function execute(OperationContext $context): ForwardResult
    {
        $this->assertInput($context->obligation->input);
        $fenced = $this->store->owner($context);
        return ForwardResult::observed([
            'status' => $fenced ? 'fenced' : 'open',
        ], ['owner:' . $context->operationId]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        $this->assertInput($context->obligation->input);
        return $this->store->owner($context) ? Resolution::verified(['owner-fenced:' . $context->operationId])
            : Resolution::unknown('business_cancellation_required');
    }
}
