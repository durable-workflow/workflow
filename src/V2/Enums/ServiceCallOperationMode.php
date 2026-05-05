<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

/**
 * Operation mode of a cross-namespace service call. Determines whether
 * the caller receives a terminal result inline or only an in-flight
 * durable reference.
 *
 * Pinned by docs/architecture/workflow-service-calls-architecture.md and
 * tests/Unit/V2/WorkflowServiceCallsArchitectureDocumentationTest.php.
 */
enum ServiceCallOperationMode: string
{
    /**
     * The caller blocks until the call reaches a terminal state and
     * receives the terminal result (or terminal failure) directly.
     */
    case Sync = 'sync';

    /**
     * The caller receives a durable reference to the in-flight call as
     * soon as the call is admitted. The terminal outcome is observed
     * later through the service-call id and linked target references.
     */
    case Async = 'async';

    /**
     * The caller is willing to receive an early durable reference but
     * will block on the terminal outcome up to the deadline. After the
     * deadline elapses the caller observes the call asynchronously
     * through the service-call id.
     */
    case SyncWithDurableReference = 'sync_with_durable_reference';
}
