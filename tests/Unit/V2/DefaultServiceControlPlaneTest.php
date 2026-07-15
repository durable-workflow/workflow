<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallFailureReason;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceBoundaryPolicy;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceBoundaryDecision;
use Workflow\V2\Support\ServiceBoundaryRequest;

final class DefaultServiceControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    public function testExecuteRecordsDurableResolutionFailureWhenEndpointIsMissing(): void
    {
        $controlPlane = new DefaultServiceControlPlane(
            new FakeServiceWorkflowControlPlane(),
            new DefaultServiceBoundaryPolicy(),
        );

        $result = $controlPlane->execute('Billing', 'Invoices', 'Create', [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
            'principal_subject' => 'user:operator',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('endpoint_not_found', $result['reason']);

        $call = WorkflowServiceCall::query()->firstOrFail();

        $this->assertSame(ServiceCallStatus::Failed->value, $call->status);
        $this->assertSame(ServiceCallOutcome::RejectedNotFound, $call->outcome);
        $this->assertSame('billing', $call->target_namespace);
        $this->assertSame('finance', $call->caller_namespace);
        $this->assertSame(
            ServiceCallFailureReason::ResolutionFailure->value,
            $call->outcome_metadata['failure_reason']
        );
        $this->assertSame('endpoint', $call->outcome_metadata['resolution_failed_at']);
    }

    public function testExecuteRejectsMissingExplicitServiceCallIdWithoutCreatingReplacementCall(): void
    {
        $controlPlane = new DefaultServiceControlPlane(
            new FakeServiceWorkflowControlPlane(),
            new DefaultServiceBoundaryPolicy(),
        );

        $result = $controlPlane->execute('Billing', 'Invoices', 'Create', [
            'namespace' => 'billing',
            'service_call_id' => '01J_SERVICE_CALL_NOT_FOUND',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('service_call_not_found', $result['reason']);
        $this->assertSame('01J_SERVICE_CALL_NOT_FOUND', $result['service_call_id']);
        $this->assertSame(0, WorkflowServiceCall::query()->count());
    }

    public function testExecuteRecordsPolicyDenialBeforeDispatch(): void
    {
        $fakeWorkflow = new FakeServiceWorkflowControlPlane();
        $controlPlane = new DefaultServiceControlPlane($fakeWorkflow, new DefaultServiceBoundaryPolicy());
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'boundary_policy' => [
                'authorization' => [
                    'caller_namespaces' => [
                        'allow' => ['shipping'],
                    ],
                ],
            ],
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
            'principal_subject' => 'user:operator',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('caller_namespace_not_allowed', $result['reason']);
        $this->assertSame([], $fakeWorkflow->starts);

        $call = WorkflowServiceCall::query()->firstOrFail();

        $this->assertSame(ServiceCallStatus::Failed->value, $call->status);
        $this->assertSame(ServiceCallOutcome::RejectedForbidden, $call->outcome);
        $this->assertSame(ServiceCallFailureReason::PolicyRejection->value, $call->outcome_metadata['failure_reason']);
        $this->assertSame('operation', $call->outcome_metadata['forbidden_axis']);
    }

    public function testWorkflowRunBindingDispatchesThroughWorkflowControlPlaneAndLinksRun(): void
    {
        $fakeWorkflow = new FakeServiceWorkflowControlPlane();
        $controlPlane = new DefaultServiceControlPlane($fakeWorkflow, new DefaultServiceBoundaryPolicy());
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
            'handler_target_reference' => 'billing.invoice.create',
            'handler_binding' => [
                'connection' => 'redis',
                'queue' => 'critical',
                'business_key' => 'invoice-42',
            ],
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
            'principal_subject' => 'user:operator',
            'payload_codec' => 'avro',
            'payload_blob' => 'encoded-arguments',
            'connection' => 'caller-supplied-queue-is-ignored',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(ServiceCallStatus::Started->value, $result['status']);
        $this->assertSame('run-service-1', $result['linked_workflow_run_id']);
        $this->assertSame('instance-service-1', $result['linked_workflow_instance_id']);
        $this->assertSame(ServiceCallBindingKind::WorkflowRun->value, $result['resolved_binding_kind']);

        $this->assertCount(1, $fakeWorkflow->starts);
        $this->assertSame('billing.invoice.create', $fakeWorkflow->starts[0]['workflow_type']);
        $this->assertSame('billing', $fakeWorkflow->starts[0]['options']['namespace']);
        $this->assertSame('redis', $fakeWorkflow->starts[0]['options']['connection']);
        $this->assertSame('critical', $fakeWorkflow->starts[0]['options']['queue']);
        $this->assertSame('encoded-arguments', $fakeWorkflow->starts[0]['options']['arguments']);
        $this->assertNotSame(
            'caller-supplied-queue-is-ignored',
            $fakeWorkflow->starts[0]['options']['connection'],
        );

        $call = WorkflowServiceCall::query()->firstOrFail();

        $this->assertSame(ServiceCallStatus::Started->value, $call->status);
        $this->assertSame(ServiceCallOutcome::Accepted, $call->outcome);
        $this->assertSame('run-service-1', $call->resolved_target_reference);
    }

    public function testAcceptedLegacyHandlerBindingAliasSnapshotsRuntimeResolvedBindingKind(): void
    {
        $controlPlane = new DefaultServiceControlPlane(
            new FakeServiceWorkflowControlPlane(),
            new DefaultServiceBoundaryPolicy(),
        );
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'handler_binding_kind' => 'start_workflow',
            'handler_target_reference' => 'billing.invoice.create',
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'dispatch_handler' => false,
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(ServiceCallStatus::Accepted->value, $result['status']);
        $this->assertSame(ServiceCallBindingKind::WorkflowRun->value, $result['resolved_binding_kind']);
        $this->assertSame('billing.invoice.create', $result['resolved_target_reference']);
    }

    public function testUnknownHandlerBindingKindFailsClosedBeforeAcceptance(): void
    {
        $fakeWorkflow = new FakeServiceWorkflowControlPlane();
        $controlPlane = new DefaultServiceControlPlane($fakeWorkflow, new DefaultServiceBoundaryPolicy());
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'handler_binding_kind' => 'unsupported_handler',
            'handler_target_reference' => 'billing.invoice.create',
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('unknown_binding_kind', $result['reason']);
        $this->assertSame(ServiceCallStatus::Failed->value, $result['status']);
        $this->assertSame(ServiceCallOutcome::RejectedNotFound->value, $result['outcome']);
        $this->assertSame('unknown_binding_kind', $result['outcome_reason']);
        $this->assertSame('unresolved', $result['resolved_binding_kind']);
        $this->assertNull($result['resolved_target_reference']);
        $this->assertCount(0, $fakeWorkflow->starts);
    }

    public function testWorkflowSignalBindingDispatchesThroughWorkflowControlPlaneAndLinksCommand(): void
    {
        $fakeWorkflow = new FakeServiceWorkflowControlPlane();
        $controlPlane = new DefaultServiceControlPlane($fakeWorkflow, new DefaultServiceBoundaryPolicy());
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowSignal->value,
            'handler_binding' => [
                'workflow_instance_id' => 'invoice-42',
                'signal_name' => 'approve',
            ],
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
            'arguments' => [
                'approved' => true,
            ],
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(ServiceCallStatus::Started->value, $result['status']);
        $this->assertSame(ServiceCallBindingKind::WorkflowSignal->value, $result['resolved_binding_kind']);
        $this->assertSame('command-signal-1', $result['resolved_target_reference']);
        $this->assertSame('invoice-42', $result['linked_workflow_instance_id']);
        $this->assertSame('run-service-1', $result['linked_workflow_run_id']);

        $this->assertCount(1, $fakeWorkflow->signals);
        $this->assertSame('invoice-42', $fakeWorkflow->signals[0]['instance_id']);
        $this->assertSame('approve', $fakeWorkflow->signals[0]['name']);
        $this->assertSame('billing', $fakeWorkflow->signals[0]['options']['namespace']);
        $this->assertSame([
            'approved' => true,
        ], $fakeWorkflow->signals[0]['options']['arguments']);
    }

    public function testWorkflowQueryBindingDispatchesThroughWorkflowControlPlaneAndCompletesCall(): void
    {
        $fakeWorkflow = new FakeServiceWorkflowControlPlane();
        $controlPlane = new DefaultServiceControlPlane($fakeWorkflow, new DefaultServiceBoundaryPolicy());
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'operation_mode' => ServiceCallOperationMode::Sync->value,
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowQuery->value,
            'handler_binding' => [
                'workflow_instance_id' => 'invoice-42',
                'query_name' => 'status',
            ],
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(ServiceCallStatus::Completed->value, $result['status']);
        $this->assertSame(ServiceCallOutcome::Completed->value, $result['outcome']);
        $this->assertSame(ServiceCallBindingKind::WorkflowQuery->value, $result['resolved_binding_kind']);
        $this->assertSame('run-service-1', $result['resolved_target_reference']);
        $this->assertArrayNotHasKey('result', $result['handler']);

        $this->assertCount(1, $fakeWorkflow->queries);
        $this->assertSame('invoice-42', $fakeWorkflow->queries[0]['instance_id']);
        $this->assertSame('status', $fakeWorkflow->queries[0]['name']);
        $this->assertSame('billing', $fakeWorkflow->queries[0]['options']['namespace']);
    }

    public function testWorkflowQueryBindingUsesConfiguredHostQueryHandler(): void
    {
        $fakeWorkflow = new FakeServiceWorkflowControlPlane();
        $hostQueryHandler = new FakeServiceWorkflowQueryHandler([
            [
                'success' => true,
                'workflow_instance_id' => 'invoice-42',
                'run_id' => 'run-routed-1',
                'result' => 'routed query result',
                'reason' => null,
            ],
        ]);
        $this->app->instance(
            'workflow.v2.service_control_plane.workflow_query_handler',
            [$hostQueryHandler, 'query'],
        );
        $controlPlane = new DefaultServiceControlPlane($fakeWorkflow, new DefaultServiceBoundaryPolicy());
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'operation_mode' => ServiceCallOperationMode::Sync->value,
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowQuery->value,
            'handler_binding' => [
                'workflow_instance_id' => 'invoice-42',
                'query_name' => 'status',
            ],
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(ServiceCallStatus::Completed->value, $result['status']);
        $this->assertSame(ServiceCallOutcome::Completed->value, $result['outcome']);
        $this->assertSame('run-routed-1', $result['resolved_target_reference']);
        $this->assertCount(0, $fakeWorkflow->queries);
        $this->assertCount(1, $hostQueryHandler->queries);
        $this->assertSame('invoice-42', $hostQueryHandler->queries[0]['instance_id']);
        $this->assertSame('status', $hostQueryHandler->queries[0]['name']);
        $this->assertSame('billing', $hostQueryHandler->queries[0]['options']['namespace']);
        $this->assertSame(1, $hostQueryHandler->queries[0]['options']['service_call_attempt']);
        $this->assertSame($result['service_call_id'], $hostQueryHandler->queries[0]['options']['service_call_id']);
    }

    public function testExecuteRetriesTransientHandlerFailuresAndRecordsAttempts(): void
    {
        $fakeWorkflow = new FakeServiceWorkflowControlPlane();
        $fakeWorkflow->queryResults = [
            [
                'success' => false,
                'reason' => 'transient_greeter_failure',
                'message' => 'first transient failure',
                'error_type' => 'TransientGreetingFailure',
            ],
            [
                'success' => false,
                'reason' => 'transient_greeter_failure',
                'message' => 'second transient failure',
                'error_type' => 'TransientGreetingFailure',
            ],
            [
                'success' => true,
                'workflow_instance_id' => 'invoice-42',
                'run_id' => 'run-service-1',
                'result' => 'hello, world',
                'reason' => null,
            ],
        ];
        $controlPlane = new DefaultServiceControlPlane($fakeWorkflow, new DefaultServiceBoundaryPolicy());
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'operation_mode' => ServiceCallOperationMode::Sync->value,
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowQuery->value,
            'handler_binding' => [
                'workflow_instance_id' => 'invoice-42',
                'query_name' => 'greet',
            ],
            'retry_policy' => [
                'max_attempts' => 3,
                'backoff_seconds' => [0, 0],
            ],
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(ServiceCallStatus::Completed->value, $result['status']);
        $this->assertSame(ServiceCallOutcome::Completed->value, $result['outcome']);
        $this->assertCount(3, $fakeWorkflow->queries);
        $this->assertCount(3, $result['service_call_attempts']);
        $this->assertSame(3, $result['retry_attempt_count']);
        $this->assertTrue($result['service_call_attempts'][0]['retry_scheduled']);
        $this->assertEquals(0, $result['service_call_attempts'][0]['scheduled_backoff_seconds']);
        $this->assertSame(ServiceCallStatus::Started->value, $result['service_call_attempts'][0]['status']);
        $this->assertSame(ServiceCallOutcome::Accepted->value, $result['service_call_attempts'][0]['outcome']);
        $this->assertSame('transient_greeter_failure', $result['service_call_attempts'][0]['outcome_reason']);
        $this->assertArrayNotHasKey('failed_at', $result['service_call_attempts'][0]);
        $this->assertSame('TransientGreetingFailure', $result['service_call_attempts'][0]['failure_type']);
        $this->assertSame('first transient failure', $result['service_call_attempts'][0]['failure_message']);
        $this->assertSame(ServiceCallStatus::Completed->value, $result['service_call_attempts'][2]['status']);
        $this->assertSame(
            [
                ServiceCallStatus::Accepted->value,
                ServiceCallStatus::Started->value,
                ServiceCallStatus::Started->value,
            ],
            $fakeWorkflow->queryObservedCallStatuses,
        );
        $this->assertSame(
            [
                ServiceCallOutcome::Accepted->value,
                ServiceCallOutcome::Accepted->value,
                ServiceCallOutcome::Accepted->value,
            ],
            $fakeWorkflow->queryObservedCallOutcomes,
        );

        $call = WorkflowServiceCall::query()->firstOrFail();
        $this->assertCount(3, $call->metadata['service_call_attempts']);
        $this->assertSame(ServiceCallStatus::Completed->value, $call->status);
        $this->assertSame(ServiceCallOutcome::Completed, $call->outcome);
        $this->assertNull($call->failed_at);
        $this->assertNull($call->failure_message);
    }

    public function testRetryExhaustionPersistsFailedOnlyAfterFinalAttempt(): void
    {
        $fakeWorkflow = new FakeServiceWorkflowControlPlane();
        $fakeWorkflow->queryResults = [
            [
                'success' => false,
                'reason' => 'transient_greeter_failure',
                'message' => 'first transient failure',
                'error_type' => 'TransientGreetingFailure',
            ],
            [
                'success' => false,
                'reason' => 'transient_greeter_failure',
                'message' => 'second transient failure',
                'error_type' => 'TransientGreetingFailure',
            ],
        ];
        $controlPlane = new DefaultServiceControlPlane($fakeWorkflow, new DefaultServiceBoundaryPolicy());
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'operation_mode' => ServiceCallOperationMode::Sync->value,
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowQuery->value,
            'handler_binding' => [
                'workflow_instance_id' => 'invoice-42',
                'query_name' => 'greet',
            ],
            'retry_policy' => [
                'max_attempts' => 2,
                'backoff_seconds' => [0],
            ],
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame(ServiceCallStatus::Failed->value, $result['status']);
        $this->assertSame(ServiceCallOutcome::HandlerFailed->value, $result['outcome']);
        $this->assertCount(2, $fakeWorkflow->queries);
        $this->assertSame(
            [ServiceCallStatus::Accepted->value, ServiceCallStatus::Started->value],
            $fakeWorkflow->queryObservedCallStatuses,
        );
        $this->assertSame(ServiceCallStatus::Started->value, $result['service_call_attempts'][0]['status']);
        $this->assertSame(ServiceCallOutcome::Accepted->value, $result['service_call_attempts'][0]['outcome']);
        $this->assertSame('transient_greeter_failure', $result['service_call_attempts'][0]['outcome_reason']);
        $this->assertTrue($result['service_call_attempts'][0]['retry_scheduled']);
        $this->assertArrayNotHasKey('failed_at', $result['service_call_attempts'][0]);
        $this->assertSame(ServiceCallStatus::Failed->value, $result['service_call_attempts'][1]['status']);
        $this->assertSame(ServiceCallOutcome::HandlerFailed->value, $result['service_call_attempts'][1]['outcome']);
        $this->assertFalse($result['service_call_attempts'][1]['retry_scheduled']);

        $call = WorkflowServiceCall::query()->firstOrFail();
        $this->assertSame(ServiceCallStatus::Failed->value, $call->status);
        $this->assertSame(ServiceCallOutcome::HandlerFailed, $call->outcome);
        $this->assertNotNull($call->failed_at);
        $this->assertSame('TransientGreetingFailure', $call->outcome_metadata['service_error_type']);
        $this->assertCount(2, $call->outcome_metadata['service_call_attempts']);
    }

    public function testRetriesKeepBoundaryAdmissionHeldUntilTerminalOutcome(): void
    {
        $fakeWorkflow = new FakeServiceWorkflowControlPlane();
        $fakeWorkflow->queryResults = [
            [
                'success' => false,
                'reason' => 'transient_greeter_failure',
                'message' => 'first transient failure',
                'error_type' => 'TransientGreetingFailure',
            ],
            [
                'success' => true,
                'workflow_instance_id' => 'invoice-42',
                'run_id' => 'run-service-1',
                'result' => 'hello, world',
                'reason' => null,
            ],
        ];
        $boundaryPolicy = new RetryReleaseAssertingBoundaryPolicy();
        $controlPlane = new DefaultServiceControlPlane($fakeWorkflow, $boundaryPolicy);
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'operation_mode' => ServiceCallOperationMode::Sync->value,
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowQuery->value,
            'handler_binding' => [
                'workflow_instance_id' => 'invoice-42',
                'query_name' => 'greet',
            ],
            'boundary_policy' => [
                'concurrency_limit' => [
                    'max_in_flight' => 1,
                ],
            ],
            'retry_policy' => [
                'max_attempts' => 2,
                'backoff_seconds' => [0],
            ],
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(ServiceCallStatus::Completed->value, $result['status']);
        $this->assertCount(2, $fakeWorkflow->queries);
        $this->assertSame([ServiceCallStatus::Completed->value], $boundaryPolicy->releaseStatuses);
        $this->assertFalse($boundaryPolicy->releasedWhileCallWasFailed);
        $this->assertFalse($boundaryPolicy->admittedCompetingCallDuringRetry);
    }

    public function testExecutePreservesTypedPermanentFailureAndDoesNotRetryNonRetryableTypes(): void
    {
        $fakeWorkflow = new FakeServiceWorkflowControlPlane();
        $fakeWorkflow->queryResults = [
            [
                'success' => false,
                'reason' => 'service_error',
                'message' => 'shared greeter is permanently unavailable',
                'error_type' => 'SharedGreeterUnavailable',
            ],
        ];
        $controlPlane = new DefaultServiceControlPlane($fakeWorkflow, new DefaultServiceBoundaryPolicy());
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'operation_mode' => ServiceCallOperationMode::Sync->value,
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowQuery->value,
            'handler_binding' => [
                'workflow_instance_id' => 'invoice-42',
                'query_name' => 'greet',
            ],
            'retry_policy' => [
                'max_attempts' => 3,
                'non_retryable_error_types' => ['SharedGreeterUnavailable'],
            ],
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('SharedGreeterUnavailable', $result['error_type']);
        $this->assertSame('SharedGreeterUnavailable', $result['service_error_type']);
        $this->assertSame('shared greeter is permanently unavailable', $result['message']);
        $this->assertCount(1, $fakeWorkflow->queries);
        $this->assertCount(1, $result['service_call_attempts']);

        $call = WorkflowServiceCall::query()->firstOrFail();
        $this->assertSame('SharedGreeterUnavailable', $call->outcome_metadata['service_error_type']);
        $this->assertSame('SharedGreeterUnavailable', $call->outcome_metadata['caller_observed_error_type']);
        $this->assertSame('shared greeter is permanently unavailable', $call->outcome_metadata['typed_error_message']);
        $this->assertCount(1, $call->outcome_metadata['service_call_attempts']);
    }

    public function testExplicitPreAdmittedCallStillDispatchesWhenIdempotencyKeyIsPresent(): void
    {
        $fakeWorkflow = new FakeServiceWorkflowControlPlane();
        $controlPlane = new DefaultServiceControlPlane($fakeWorkflow, new DefaultServiceBoundaryPolicy());
        [$endpoint, $service, $operation] = $this->catalogWithOperation('billing');

        $admitted = $this->serviceCall($endpoint, $service, $operation, [
            'status' => ServiceCallStatus::Accepted->value,
            'resolved_target_reference' => $operation->handler_target_reference,
            'linked_workflow_instance_id' => null,
            'linked_workflow_run_id' => null,
            'caller_principal_subject' => 'user:server',
            'idempotency_key' => 'idem-1',
            'started_at' => null,
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'service_call_id' => $admitted->id,
            'boundary_policy_outcome' => ServiceCallOutcome::Accepted->value,
            'idempotency_key' => 'idem-1',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertFalse($result['idempotent_replay']);
        $this->assertCount(1, $fakeWorkflow->starts);
        $this->assertSame(ServiceCallStatus::Started->value, $admitted->refresh()->status);
        $this->assertSame('user:server', $admitted->caller_principal_subject);

        $again = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'service_call_id' => $admitted->id,
            'boundary_policy_outcome' => ServiceCallOutcome::Accepted->value,
            'idempotency_key' => 'idem-1',
        ]);

        $this->assertTrue($again['accepted']);
        $this->assertSame(ServiceCallStatus::Started->value, $again['status']);
        $this->assertCount(1, $fakeWorkflow->starts);
    }

    public function testActivityBindingCommitsBindingResolutionAtAcceptanceWithoutFalselyMarkingStarted(): void
    {
        $controlPlane = new DefaultServiceControlPlane(
            new FakeServiceWorkflowControlPlane(),
            new DefaultServiceBoundaryPolicy(),
        );
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'handler_binding_kind' => ServiceCallBindingKind::ActivityExecution->value,
            'handler_target_reference' => 'billing.invoice.activity',
            'handler_binding' => [
                'activity_class' => 'App\\Activities\\IssueInvoice',
                'activity_type' => 'billing.invoice.issue',
                'queue' => 'invoices-priority',
            ],
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
            'principal_subject' => 'user:operator',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(ServiceCallStatus::Accepted->value, $result['status']);
        $this->assertSame(ServiceCallOutcome::Accepted->value, $result['outcome']);
        $this->assertNotNull($result['accepted_at']);
        $this->assertNull($result['started_at']);
        $this->assertSame(ServiceCallBindingKind::ActivityExecution->value, $result['resolved_binding_kind']);
        $this->assertNotNull($result['resolved_target_reference']);
        $this->assertSame($result['resolved_target_reference'], $result['handler']['activity_execution_id']);
        $this->assertSame('App\\Activities\\IssueInvoice', $result['handler']['activity_class']);
        $this->assertSame('billing.invoice.issue', $result['handler']['activity_type']);
        $this->assertSame(ServiceCallBindingKind::ActivityExecution->value, $result['handler']['kind']);

        $call = WorkflowServiceCall::query()->firstOrFail();

        $this->assertSame(ServiceCallStatus::Accepted->value, $call->status);
        $this->assertSame(ServiceCallOutcome::Accepted, $call->outcome);
        $this->assertNotNull($call->accepted_at);
        $this->assertNull($call->started_at);
        $this->assertSame($result['resolved_target_reference'], $call->resolved_target_reference);
        $this->assertSame($result['resolved_target_reference'], $call->metadata['activity_execution_id']);
        $this->assertSame('App\\Activities\\IssueInvoice', $call->metadata['activity_class']);
        $this->assertSame('billing.invoice.issue', $call->metadata['activity_type']);
        $this->assertSame('invoices-priority', $call->metadata['queue']);
    }

    public function testActivityBindingFailsWhenActivityClassAndReferenceMissing(): void
    {
        $controlPlane = new DefaultServiceControlPlane(
            new FakeServiceWorkflowControlPlane(),
            new DefaultServiceBoundaryPolicy(),
        );
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'handler_binding_kind' => ServiceCallBindingKind::ActivityExecution->value,
            'handler_target_reference' => null,
            'handler_binding' => [],
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('handler_target_missing', $result['reason']);
        $this->assertSame(ServiceCallStatus::Failed->value, $result['status']);
        $this->assertSame(ServiceCallOutcome::HandlerFailed->value, $result['outcome']);
    }

    public function testInvocableCarrierBindingCommitsBindingResolutionAtAcceptanceWithoutFalselyMarkingStarted(): void
    {
        $controlPlane = new DefaultServiceControlPlane(
            new FakeServiceWorkflowControlPlane(),
            new DefaultServiceBoundaryPolicy(),
        );
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'handler_binding_kind' => 'invocable_http',
            'handler_target_reference' => 'https://carrier.billing.example/handle',
            'handler_binding' => [
                'carrier' => 'php-invocable',
                'carrier_handler' => 'billing.invoice.issue',
                'workflow_instance_id' => 'invoice-42',
            ],
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
            'principal_subject' => 'user:operator',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(ServiceCallStatus::Accepted->value, $result['status']);
        $this->assertSame(ServiceCallOutcome::Accepted->value, $result['outcome']);
        $this->assertNotNull($result['accepted_at']);
        $this->assertNull($result['started_at']);
        $this->assertSame(ServiceCallBindingKind::InvocableCarrierRequest->value, $result['resolved_binding_kind']);
        $this->assertNotNull($result['resolved_target_reference']);
        $this->assertSame($result['resolved_target_reference'], $result['handler']['carrier_request_id']);
        $this->assertSame('https://carrier.billing.example/handle', $result['handler']['carrier_endpoint']);
        $this->assertSame('billing.invoice.issue', $result['handler']['carrier_handler']);
        $this->assertSame('php-invocable', $result['handler']['carrier']);
        $this->assertSame('invoice-42', $result['linked_workflow_instance_id']);

        $call = WorkflowServiceCall::query()->firstOrFail();

        $this->assertSame(ServiceCallStatus::Accepted->value, $call->status);
        $this->assertSame(ServiceCallOutcome::Accepted, $call->outcome);
        $this->assertNotNull($call->accepted_at);
        $this->assertNull($call->started_at);
        $this->assertSame($result['resolved_target_reference'], $call->metadata['carrier_request_id']);
        $this->assertSame('https://carrier.billing.example/handle', $call->metadata['carrier_endpoint']);
        $this->assertSame('billing.invoice.issue', $call->metadata['carrier_handler']);
        $this->assertSame('php-invocable', $call->metadata['carrier']);
    }

    public function testInvocableCarrierBindingFailsWhenEndpointAndReferenceMissing(): void
    {
        $controlPlane = new DefaultServiceControlPlane(
            new FakeServiceWorkflowControlPlane(),
            new DefaultServiceBoundaryPolicy(),
        );
        [$endpoint, $service] = $this->catalog('billing');

        $this->operation($endpoint, $service, [
            'handler_binding_kind' => 'invocable_http',
            'handler_target_reference' => null,
            'handler_binding' => [],
        ]);

        $result = $controlPlane->execute('billing', 'invoices', 'create', [
            'namespace' => 'billing',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('handler_target_missing', $result['reason']);
        $this->assertSame(ServiceCallStatus::Failed->value, $result['status']);
        $this->assertSame(ServiceCallOutcome::HandlerFailed->value, $result['outcome']);
    }

    public function testCancelCallHonorsCancellationPolicy(): void
    {
        $controlPlane = new DefaultServiceControlPlane(
            new FakeServiceWorkflowControlPlane(),
            new DefaultServiceBoundaryPolicy(),
        );
        [$endpoint, $service, $operation] = $this->catalogWithOperation('billing');

        $blocked = $this->serviceCall($endpoint, $service, $operation, [
            'cancellation_policy' => [
                'allow_cancel' => false,
            ],
        ]);

        $blockedResult = $controlPlane->cancelCall($blocked->id, [
            'namespace' => 'billing',
        ]);

        $this->assertFalse($blockedResult['accepted']);
        $this->assertSame('cancellation_not_allowed', $blockedResult['reason']);
        $this->assertSame(ServiceCallStatus::Started->value, $blocked->refresh()->status);

        $allowed = $this->serviceCall($endpoint, $service, $operation, [
            'cancellation_policy' => [
                'allow_cancel' => true,
            ],
        ]);

        $allowedResult = $controlPlane->cancelCall($allowed->id, [
            'namespace' => 'billing',
            'reason' => 'caller withdrew the request',
        ]);

        $this->assertTrue($allowedResult['accepted']);
        $this->assertSame(ServiceCallStatus::Cancelled->value, $allowedResult['status']);
        $this->assertSame(ServiceCallOutcome::Cancelled->value, $allowedResult['outcome']);
        $this->assertSame(ServiceCallStatus::Cancelled->value, $allowed->refresh()->status);
    }

    /**
     * @return array{WorkflowServiceEndpoint, WorkflowService}
     */
    private function catalog(string $namespace): array
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => $namespace,
            'endpoint_name' => 'billing',
        ]);

        $service = WorkflowService::query()->create([
            'workflow_service_endpoint_id' => $endpoint->id,
            'namespace' => $namespace,
            'service_name' => 'invoices',
        ]);

        return [$endpoint, $service];
    }

    /**
     * @return array{WorkflowServiceEndpoint, WorkflowService, WorkflowServiceOperation}
     */
    private function catalogWithOperation(string $namespace): array
    {
        [$endpoint, $service] = $this->catalog($namespace);

        return [$endpoint, $service, $this->operation($endpoint, $service)];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function operation(
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        array $overrides = [],
    ): WorkflowServiceOperation {
        return WorkflowServiceOperation::query()->create(array_merge([
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'namespace' => $endpoint->namespace,
            'operation_name' => 'create',
            'operation_mode' => ServiceCallOperationMode::Async->value,
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
            'handler_target_reference' => 'billing.invoice.create',
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function serviceCall(
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        WorkflowServiceOperation $operation,
        array $overrides = [],
    ): WorkflowServiceCall {
        return WorkflowServiceCall::query()->create(array_merge([
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'workflow_service_operation_id' => $operation->id,
            'namespace' => $endpoint->namespace,
            'endpoint_name' => $endpoint->endpoint_name,
            'service_name' => $service->service_name,
            'operation_name' => $operation->operation_name,
            'caller_namespace' => 'finance',
            'target_namespace' => $endpoint->namespace,
            'status' => ServiceCallStatus::Started->value,
            'outcome' => ServiceCallOutcome::Accepted->value,
            'operation_mode' => ServiceCallOperationMode::Async->value,
            'resolved_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
            'resolved_target_reference' => 'run-service-1',
            'linked_workflow_instance_id' => 'instance-service-1',
            'linked_workflow_run_id' => 'run-service-1',
            'accepted_at' => now(),
            'started_at' => now(),
        ], $overrides));
    }
}

final class RetryReleaseAssertingBoundaryPolicy implements ServiceBoundaryPolicy
{
    /**
     * @var list<string|null>
     */
    public array $releaseStatuses = [];

    public bool $releasedWhileCallWasFailed = false;

    public bool $admittedCompetingCallDuringRetry = false;

    /**
     * @var array<string, int>
     */
    private array $inFlight = [];

    public function evaluate(ServiceBoundaryRequest $request): ServiceBoundaryDecision
    {
        $maxInFlight = $this->maxInFlight($request);
        $key = $request->boundaryKey();
        $current = $this->inFlight[$key] ?? 0;

        if ($maxInFlight !== null && $current >= $maxInFlight) {
            return ServiceBoundaryDecision::denyConcurrency(
                message: 'Concurrency limit reached while service call is retrying.',
                metadata: [
                    'observed_in_flight' => $current,
                    'max_in_flight' => $maxInFlight,
                    'boundary_key' => $key,
                ],
            );
        }

        if ($maxInFlight !== null) {
            $this->inFlight[$key] = $current + 1;
        }

        return ServiceBoundaryDecision::allow(metadata: [
            'boundary_key' => $key,
        ]);
    }

    public function release(ServiceBoundaryRequest $request): void
    {
        $key = $request->boundaryKey();

        if (isset($this->inFlight[$key])) {
            if (--$this->inFlight[$key] <= 0) {
                unset($this->inFlight[$key]);
            }
        }

        $status = WorkflowServiceCall::query()
            ->where('target_namespace', $request->targetNamespace)
            ->where('endpoint_name', $request->endpointName)
            ->where('service_name', $request->serviceName)
            ->where('operation_name', $request->operationName)
            ->oldest('created_at')
            ->oldest('id')
            ->value('status');

        $this->releaseStatuses[] = is_string($status) ? $status : null;

        if ($status !== ServiceCallStatus::Failed->value) {
            return;
        }

        $this->releasedWhileCallWasFailed = true;
        $this->admittedCompetingCallDuringRetry = $this->evaluate($request)
            ->isAllowed();
    }

    private function maxInFlight(ServiceBoundaryRequest $request): ?int
    {
        $policy = $request->effectiveBoundaryPolicy();
        $rules = [];

        foreach (['concurrency_limit', 'concurrency'] as $key) {
            if (isset($policy[$key]) && is_array($policy[$key])) {
                $rules = $policy[$key];
                break;
            }
        }

        $max = isset($rules['max_in_flight']) ? (int) $rules['max_in_flight'] : null;

        return $max !== null && $max > 0 ? $max : null;
    }
}

final class FakeServiceWorkflowQueryHandler
{
    /**
     * @var list<array{instance_id: string, name: string, options: array<string, mixed>, call_id: string, operation_id: string}>
     */
    public array $queries = [];

    /**
     * @param list<array<string, mixed>> $results
     */
    public function __construct(
        private array $results
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function query(
        string $instanceId,
        string $name,
        array $options,
        WorkflowServiceCall $call,
        WorkflowServiceOperation $operation,
    ): array {
        $this->queries[] = [
            'instance_id' => $instanceId,
            'name' => $name,
            'options' => $options,
            'call_id' => $call->id,
            'operation_id' => $operation->id,
        ];

        if ($this->results !== []) {
            return array_shift($this->results);
        }

        return [
            'success' => true,
            'workflow_instance_id' => $instanceId,
            'run_id' => 'run-routed-1',
            'result' => null,
            'reason' => null,
        ];
    }
}

final class FakeServiceWorkflowControlPlane implements WorkflowControlPlane
{
    /**
     * @var list<array{workflow_type: string, instance_id: string|null, options: array<string, mixed>}>
     */
    public array $starts = [];

    /**
     * @var list<array{instance_id: string, name: string, options: array<string, mixed>}>
     */
    public array $signals = [];

    /**
     * @var list<array{instance_id: string, name: string, options: array<string, mixed>}>
     */
    public array $queries = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $queryResults = [];

    /**
     * @var list<string|null>
     */
    public array $queryObservedCallStatuses = [];

    /**
     * @var list<string|null>
     */
    public array $queryObservedCallOutcomes = [];

    /**
     * @var array<string, mixed>
     */
    public array $startResult = [
        'started' => true,
        'workflow_instance_id' => 'instance-service-1',
        'workflow_run_id' => 'run-service-1',
        'workflow_type' => 'billing.invoice.create',
        'outcome' => 'started_new',
        'task_id' => 'task-service-1',
        'reason' => null,
    ];

    public function start(string $workflowType, ?string $instanceId = null, array $options = []): array
    {
        $this->starts[] = [
            'workflow_type' => $workflowType,
            'instance_id' => $instanceId,
            'options' => $options,
        ];

        return $this->startResult;
    }

    public function signal(string $instanceId, string $name, array $options = []): array
    {
        $this->signals[] = [
            'instance_id' => $instanceId,
            'name' => $name,
            'options' => $options,
        ];

        return [
            'accepted' => true,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => 'command-signal-1',
            'run_id' => 'run-service-1',
            'reason' => null,
        ];
    }

    public function query(string $instanceId, string $name, array $options = []): array
    {
        $call = WorkflowServiceCall::query()->oldest('created_at')->oldest('id')->first();
        $outcome = $call?->outcome;

        $this->queryObservedCallStatuses[] = $call instanceof WorkflowServiceCall
            ? (string) $call->status
            : null;
        $this->queryObservedCallOutcomes[] = $outcome instanceof ServiceCallOutcome
            ? $outcome->value
            : (is_string($outcome) ? $outcome : null);

        $this->queries[] = [
            'instance_id' => $instanceId,
            'name' => $name,
            'options' => $options,
        ];

        if ($this->queryResults !== []) {
            return array_shift($this->queryResults);
        }

        return [
            'success' => true,
            'workflow_instance_id' => $instanceId,
            'run_id' => 'run-service-1',
            'result' => null,
            'reason' => null,
        ];
    }

    public function update(string $instanceId, string $name, array $options = []): array
    {
        return [
            'accepted' => true,
            'workflow_instance_id' => $instanceId,
            'update_id' => 'update-service-1',
            'run_id' => 'run-service-1',
            'update_status' => 'accepted',
            'reason' => null,
        ];
    }

    public function cancel(string $instanceId, array $options = []): array
    {
        return [
            'accepted' => true,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => 'command-cancel-1',
            'reason' => null,
        ];
    }

    public function terminate(string $instanceId, array $options = []): array
    {
        return $this->commandResult($instanceId);
    }

    public function repair(string $instanceId, array $options = []): array
    {
        return $this->commandResult($instanceId);
    }

    public function archive(string $instanceId, array $options = []): array
    {
        return $this->commandResult($instanceId);
    }

    public function describe(string $instanceId, array $options = []): array
    {
        return [
            'found' => true,
            'workflow_instance_id' => $instanceId,
            'workflow_type' => 'billing.invoice.create',
            'workflow_class' => null,
            'namespace' => 'billing',
            'business_key' => null,
            'run' => null,
            'run_count' => 1,
            'actions' => [
                'can_signal' => true,
                'can_query' => true,
                'can_update' => true,
                'can_cancel' => true,
                'can_terminate' => true,
                'can_repair' => true,
                'can_archive' => false,
            ],
            'reason' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commandResult(string $instanceId): array
    {
        return [
            'accepted' => true,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => 'command-service-1',
            'reason' => null,
        ];
    }
}
