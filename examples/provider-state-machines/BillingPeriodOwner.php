<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

/** Genuine recorded no-effect owner registration; no missing-scope construction by the application. */
final readonly class BillingPeriodOwner implements ScopedOperation
{
    public function __construct(
        private BillingPeriodStore $store
    ) {
    }

    public function assertInput(array $input): void
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['period', 'run'] || ! is_string($input['period']) || ! is_string($input['run'])) {
            throw new LogicException('The original billing owner input is malformed.');
        }
    }

    public function execute(OperationContext $context): ForwardResult
    {
        $this->assertInput($context->obligation->input);
        [, $subject] = $this->store->authorizeEffect($context, 'forward', true);
        return ForwardResult::observed([
            'fenced' => (bool) $subject->cancellation_requested,
        ], ['billing-owner:' . $context->operationId]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        $this->assertInput($context->obligation->input);
        [, $subject] = $this->store->authorizeEffect($context, 'compensate', true);
        return (bool) $subject->cancellation_requested
            ? Resolution::verified(['billing-owner-fenced:' . $context->operationId])
            : Resolution::unknown('original-period-cancellation-required');
    }
}
