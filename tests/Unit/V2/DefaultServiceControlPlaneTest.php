<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
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
        $this->assertSame(ServiceCallFailureReason::ResolutionFailure->value, $call->outcome_metadata['failure_reason']);
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
                    'caller_namespaces' => ['allow' => ['shipping']],
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
            'arguments' => ['approved' => true],
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
        $this->assertSame(['approved' => true], $fakeWorkflow->signals[0]['options']['arguments']);
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

    public function testCancelCallHonorsCancellationPolicy(): void
    {
        $controlPlane = new DefaultServiceControlPlane(
            new FakeServiceWorkflowControlPlane(),
            new DefaultServiceBoundaryPolicy(),
        );
        [$endpoint, $service, $operation] = $this->catalogWithOperation('billing');

        $blocked = $this->serviceCall($endpoint, $service, $operation, [
            'cancellation_policy' => ['allow_cancel' => false],
        ]);

        $blockedResult = $controlPlane->cancelCall($blocked->id, ['namespace' => 'billing']);

        $this->assertFalse($blockedResult['accepted']);
        $this->assertSame('cancellation_not_allowed', $blockedResult['reason']);
        $this->assertSame(ServiceCallStatus::Started->value, $blocked->refresh()->status);

        $allowed = $this->serviceCall($endpoint, $service, $operation, [
            'cancellation_policy' => ['allow_cancel' => true],
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
        $this->queries[] = [
            'instance_id' => $instanceId,
            'name' => $name,
            'options' => $options,
        ];

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
