<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

/**
 * The kind of target the service-call handler resolves to. Stored on
 * the service-call row at admission time so observability surfaces can
 * link to the actual durable execution without re-resolving the
 * operation contract.
 *
 * Pinned by docs/architecture/workflow-service-calls-architecture.md and
 * tests/Unit/V2/WorkflowServiceCallsArchitectureDocumentationTest.php.
 */
enum ServiceCallBindingKind: string
{
    /**
     * The handler resolves to a fresh workflow run (start workflow).
     * The linked target reference is the workflow_run_id.
     */
    case WorkflowRun = 'workflow_run';

    /**
     * The handler resolves to a workflow update against an existing
     * workflow instance. The linked target reference is the
     * workflow_update_id (and the parent workflow_run_id is recorded
     * alongside it).
     */
    case WorkflowUpdate = 'workflow_update';

    /**
     * The handler resolves to an activity execution. The linked target
     * reference is the activity_execution_id.
     */
    case ActivityExecution = 'activity_execution';

    /**
     * The handler resolves to an invocable carrier request (an external
     * worker task envelope produced by InvocableActivityHandler or an
     * equivalent carrier). The linked target reference is the carrier
     * request id.
     */
    case InvocableCarrierRequest = 'invocable_carrier_request';
}
