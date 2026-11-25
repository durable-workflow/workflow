<?php

declare(strict_types=1);

namespace Workflow\V2\Interceptors;

use RuntimeException;
use Throwable;

/**
 * A workflow-task interceptor threw, skipped `$next` or called it more than once.
 *
 * The task's transaction rolls back, so nothing is recorded, and the task is
 * released for a retry with repair backoff.
 */
final class WorkflowTaskInterceptorFailed extends RuntimeException
{
    public function __construct(
        public readonly ?string $interceptor,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function threw(Throwable $failure): self
    {
        return new self(null, 'Workflow task interceptor failed: ' . $failure->getMessage(), $failure);
    }

    public static function skippedNext(string $interceptor): self
    {
        return new self($interceptor, sprintf(
            'Workflow task interceptor %s returned without calling $next.',
            $interceptor
        ));
    }

    public static function calledNextTwice(string $interceptor): self
    {
        return new self($interceptor, sprintf(
            'Workflow task interceptor %s called $next more than once.',
            $interceptor
        ));
    }
}
