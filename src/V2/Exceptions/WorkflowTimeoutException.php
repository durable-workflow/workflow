<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

/**
 * Thrown when a workflow run exceeds its execution or run deadline.
 *
 * Carries the timeout kind (execution_timeout or run_timeout) and the
 * expired deadline so failure classification and projections can surface
 * the timeout cause without parsing the message.
 */
final class WorkflowTimeoutException extends RuntimeException
{
    public function __construct(
        public readonly string $timeoutKind,
        public readonly string $deadlineAt,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function executionTimeout(string $deadlineAt): self
    {
        return new self(
            'execution_timeout',
            $deadlineAt,
            sprintf('Workflow execution deadline expired at %s.', $deadlineAt),
        );
    }

    public static function runTimeout(string $deadlineAt): self
    {
        return new self('run_timeout', $deadlineAt, sprintf('Workflow run deadline expired at %s.', $deadlineAt));
    }
}
