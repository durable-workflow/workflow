<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

/**
 * Lifecycle status of a cross-namespace service call.
 *
 * Pinned by docs/architecture/workflow-service-calls-architecture.md and
 * tests/Unit/V2/WorkflowServiceCallsArchitectureDocumentationTest.php.
 */
enum ServiceCallStatus: string
{
    /**
     * The call record exists, but resolution to a concrete handler binding
     * has not completed yet.
     */
    case Pending = 'pending';

    /**
     * The call has been admitted by the target namespace and the handler
     * binding is resolved, but the handler has not produced an outcome yet.
     */
    case Accepted = 'accepted';

    /**
     * The handler has begun executing the resolved target reference (a
     * workflow run, workflow update, activity execution, or invocable
     * carrier request).
     */
    case Started = 'started';

    /**
     * The handler produced a successful terminal result.
     */
    case Completed = 'completed';

    /**
     * The handler produced a terminal failure (handler exception, policy
     * rejection, resolution failure, timeout, or terminal cancellation).
     */
    case Failed = 'failed';

    /**
     * The call was cancelled before reaching a non-cancellation terminal
     * state. Cancellation is a terminal outcome of its own.
     */
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            self::Pending, self::Accepted, self::Started => false,
        };
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }
}
