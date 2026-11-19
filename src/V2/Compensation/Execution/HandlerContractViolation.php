<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Execution;

use InvalidArgumentException;
use Throwable;

/**
 * A ScopedOperation built an outcome that breaks the handler contract.
 *
 * ForwardResult and Resolution factories throw it for invalid reasons, evidence or values. When a
 * handler throws it, or its observed value exceeds the forward value limit, the engine records the
 * outcome as unknown and fails the activity with a non-retryable `scope_handler_contract_violation`
 * error carrying this message. It is never reported as an unknown provider outcome. The message is
 * diagnostic text and is not machine-stable.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class HandlerContractViolation extends InvalidArgumentException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
