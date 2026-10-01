<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use Error;
use Throwable;
use Workflow\V2\CancellationContext;

final class WorkflowCancellationRequestedException extends Error
{
    public function __construct(
        string $message = 'Cooperative cancellation requested.',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?CancellationContext $cancellation = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
