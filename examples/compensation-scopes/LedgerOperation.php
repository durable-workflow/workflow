<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\CompensationScopes;

use InvalidArgumentException;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

/**
 * Handler for one action. Both directions are idempotent by operation ID.
 */
final readonly class LedgerOperation implements ScopedOperation
{
    public function __construct(
        private EffectLedger $ledger,
        private string $action,
    ) {
    }

    public function assertInput(array $input): void
    {
        if (array_keys($input) !== ['reference'] || ! is_string($input['reference'])) {
            throw new InvalidArgumentException('A reference is required.');
        }
    }

    public function execute(OperationContext $context): ForwardResult
    {
        // apply() is idempotent, so reconcileBeforeForward needs no separate lookup here.
        $status = $this->ledger->apply($context->operationId, $this->action);
        if ($status !== 'active') {
            // Compensation already recorded this operation as absent: the effect will not happen.
            return ForwardResult::rejected('compensated-before-forward');
        }
        return ForwardResult::observed([
            'reference' => $context->obligation->input['reference'],
        ], [$this->action . ':' . $context->operationId]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        $status = $this->ledger->undo($context->operationId, $this->action);
        return Resolution::verified([$this->action . '-' . $status . ':' . $context->operationId]);
    }
}
