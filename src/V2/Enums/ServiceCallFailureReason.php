<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

/**
 * Failure taxonomy for cross-namespace service calls. Distinguishes
 * the durable cause of a terminal Failed (or Cancelled) outcome so
 * observability surfaces can explain the result without inspecting raw
 * transport logs.
 *
 * Pinned by docs/architecture/workflow-service-calls-architecture.md and
 * tests/Unit/V2/WorkflowServiceCallsArchitectureDocumentationTest.php.
 */
enum ServiceCallFailureReason: string
{
    /**
     * The endpoint, service, or operation requested by the caller could
     * not be resolved against the target namespace contract registry.
     * No handler ever started.
     */
    case ResolutionFailure = 'resolution_failure';

    /**
     * The target namespace's policy layer rejected the call before the
     * handler started (authorization, quota, idempotency conflict,
     * structural-limit guard, namespace closed).
     */
    case PolicyRejection = 'policy_rejection';

    /**
     * The deadline_policy elapsed before the handler produced a
     * terminal outcome.
     */
    case Timeout = 'timeout';

    /**
     * The call was cancelled by the caller, by an inherited
     * cancellation scope, or by the cancellation_policy on the
     * operation.
     */
    case Cancellation = 'cancellation';

    /**
     * The handler started, executed, and produced a terminal failure
     * (the linked workflow run failed, the linked update was rejected
     * or failed, the linked activity execution failed terminally, or
     * the invocable carrier reported a terminal error).
     */
    case HandlerFailure = 'handler_failure';
}
