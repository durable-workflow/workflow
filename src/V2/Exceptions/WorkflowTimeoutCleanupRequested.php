<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

/**
 * Delivered to workflow code when its work deadline passes and the run opted
 * in to deadline-triggered cleanup (#863). It is a cooperative cancellation
 * request, so existing cancellation handlers (saga compensation) run; the
 * run still ends timed out. Its context's `cause` is `timeout`.
 *
 * @experimental
 */
final class WorkflowTimeoutCleanupRequested extends WorkflowCancellationRequestedException
{
    public function __construct(
        string $message = 'Workflow work deadline passed: timeout cleanup requested.',
        int $code = 0,
        ?\Throwable $previous = null,
        ?\Workflow\V2\CancellationContext $cancellation = null,
    ) {
        parent::__construct($message, $code, $previous, $cancellation);
    }
}
