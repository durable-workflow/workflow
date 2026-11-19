<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

/** Recorded no-effect registration opens a genuine scope; a host lookup never fabricates one. */
final readonly class OwnerOperation implements ScopedOperation
{
    public function __construct(
        private Authority $authority
    ) {
    }

    public function assertInput(array $input): void
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['key', 'nativeRun', 'role', 'rootRun']) {
            throw new LogicException('The original owner descriptor is malformed.');
        }
        foreach ($input as $part) {
            if (! is_string($part)) {
                throw new LogicException('Original owner descriptor fields must be strings.');
            }
        }
    }

    public function execute(OperationContext $context): ForwardResult
    {
        $this->assertInput($context->obligation->input);
        [$root] = $this->authority->authorizeOperation($context, 'forward', true);
        return ForwardResult::observed([
            'fenced' => (bool) $root->cancellation_requested,
        ], ['owner:' . $context->operationId]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        $this->assertInput($context->obligation->input);
        [$root] = $this->authority->authorizeOperation($context, 'compensate', true);
        return (bool) $root->cancellation_requested ? Resolution::verified(
            ['owner-fenced:' . $context->operationId]
        )
            : Resolution::unknown('original-business-cancellation-required');
    }
}
