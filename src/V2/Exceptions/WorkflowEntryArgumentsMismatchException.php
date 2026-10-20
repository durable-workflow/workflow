<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;
use Throwable;

final class WorkflowEntryArgumentsMismatchException extends LogicException
{
    public function __construct(
        public readonly string $workflowType,
        public readonly string $entryMethod,
        public readonly string $bindingReason,
        public readonly ?string $parameterName = null,
        public readonly ?string $expectedType = null,
        public readonly ?string $actualType = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Workflow [%s] entry [%s] cannot bind its recorded arguments: %s%s. Restore compatible entry code and repair this run; its recorded input has not been replaced.',
            $workflowType,
            $entryMethod,
            $bindingReason,
            $parameterName === null ? '' : sprintf(
                ' at parameter [%s] (expected %s, received %s)',
                $parameterName,
                $expectedType ?? 'argument',
                $actualType ?? 'missing'
            ),
        ), previous: $previous);
    }
}
