<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\ServiceBoundaryAuditRecorder;
use Workflow\V2\Support\ServiceBoundaryDecision;
use Workflow\V2\Support\ServiceBoundaryRequest;
use Workflow\V2\Support\ServiceCallPrincipal;

final class ServiceBoundaryAuditRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_an_admitted_call_with_full_principal_attribution(): void
    {
        [, , $operation] = $this->seedCatalog();

        $request = $this->request($operation);
        $decision = ServiceBoundaryDecision::allow();

        $call = (new ServiceBoundaryAuditRecorder())->record($request, $decision);

        $this->assertInstanceOf(WorkflowServiceCall::class, $call);
        $this->assertSame('accepted', $call->status);
        $this->assertSame(ServiceCallOutcome::Accepted, $call->outcome);
        $this->assertSame('accepted', $call->outcome_category);
        $this->assertSame('accepted', $call->outcome_reason);
        $this->assertSame('user:tester', $call->caller_principal_subject);
        $this->assertSame('token', $call->caller_principal_method);
        $this->assertSame(['service.call'], $call->caller_principal_roles);
        $this->assertSame('acme', $call->caller_principal_tenant);
        $this->assertSame(ServiceCallBindingKind::WorkflowUpdate->value, $call->resolved_binding_kind);
        $this->assertNotNull($call->accepted_at);
        $this->assertNull($call->failed_at);
        $this->assertSame($operation->id, $call->workflow_service_operation_id);
    }

    public function test_persists_a_rejected_call_with_the_same_audit_shape(): void
    {
        [, , $operation] = $this->seedCatalog();

        $request = $this->request($operation, callerNamespace: 'untrusted');
        $decision = ServiceBoundaryDecision::denyNamespacePolicy(
            reason: 'caller_namespace_denied',
            message: 'Caller [untrusted] denied.',
        );

        $call = (new ServiceBoundaryAuditRecorder())->record($request, $decision);

        $this->assertSame('failed', $call->status);
        $this->assertSame(ServiceCallOutcome::RejectedForbidden, $call->outcome);
        $this->assertSame('rejected', $call->outcome_category);
        $this->assertSame('caller_namespace_denied', $call->outcome_reason);
        $this->assertSame('Caller [untrusted] denied.', $call->outcome_message);
        $this->assertSame('Caller [untrusted] denied.', $call->failure_message);
        $this->assertNotNull($call->failed_at);
        $this->assertNull($call->accepted_at);
        $this->assertSame('user:tester', $call->caller_principal_subject);
    }

    public function test_persists_decision_metadata_and_retry_advice(): void
    {
        [, , $operation] = $this->seedCatalog();

        $decision = ServiceBoundaryDecision::denyRateLimit(
            retryAfterSeconds: 7,
            message: 'rate limited',
            metadata: ['observed_window_count' => 12, 'requests_per_minute' => 10],
        );

        $call = (new ServiceBoundaryAuditRecorder())
            ->record($this->request($operation), $decision);

        $this->assertSame(7, $call->retry_after_seconds);
        $this->assertSame(12, $call->outcome_metadata['observed_window_count']);
        $this->assertSame(10, $call->outcome_metadata['requests_per_minute']);
    }

    public function test_unresolved_target_is_recorded_with_uniform_not_found_outcome(): void
    {
        $request = new ServiceBoundaryRequest(
            principal: ServiceCallPrincipal::system(),
            callerNamespace: 'analytics',
            targetNamespace: 'finance',
            endpointName: 'missing',
            serviceName: 'invoicing',
            operationName: 'create',
            operationMode: ServiceCallOperationMode::Async,
            resolvedBindingKind: 'unresolved',
        );

        $call = (new ServiceBoundaryAuditRecorder())->record(
            $request,
            ServiceBoundaryDecision::denyUnknownTarget('endpoint'),
        );

        $this->assertSame('unresolved', $call->workflow_service_endpoint_id);
        $this->assertSame('failed', $call->status);
        $this->assertSame(ServiceCallOutcome::RejectedNotFound, $call->outcome);
        $this->assertSame('endpoint', $call->metadata['resolution_failed_at']);
    }

    /**
     * @return array{0: WorkflowServiceEndpoint, 1: WorkflowService, 2: WorkflowServiceOperation}
     */
    private function seedCatalog(): array
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'finance',
            'endpoint_name' => 'billing',
        ]);

        $service = WorkflowService::query()->create([
            'namespace' => 'finance',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoicing',
        ]);

        $operation = WorkflowServiceOperation::query()->create([
            'namespace' => 'finance',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'create',
            'operation_mode' => 'sync',
            'handler_binding_kind' => 'update_workflow',
            'handler_target_reference' => 'updates.invoice.create',
        ]);

        return [$endpoint, $service, $operation];
    }

    private function request(
        WorkflowServiceOperation $operation,
        ?string $callerNamespace = 'analytics',
    ): ServiceBoundaryRequest {
        return new ServiceBoundaryRequest(
            principal: new ServiceCallPrincipal(
                subject: 'user:tester',
                method: 'token',
                roles: ['service.call'],
                tenant: 'acme',
                claims: ['scope' => 'invoices.write'],
            ),
            callerNamespace: $callerNamespace,
            targetNamespace: 'finance',
            endpointName: 'billing',
            serviceName: 'invoicing',
            operationName: $operation->operation_name,
            operationMode: ServiceCallOperationMode::Sync,
            resolvedBindingKind: ServiceCallBindingKind::WorkflowUpdate->value,
            resolvedTargetReference: 'updates.invoice.create',
            callerWorkflowInstanceId: 'caller-wf-1',
            callerWorkflowRunId: '01HRR3M8GXXS0M5KGFFXQ4S6V0',
            idempotencyKey: 'invoice-1',
            operationBoundaryPolicy: [
                'authorization' => [
                    'caller_namespaces' => ['allow' => ['analytics']],
                ],
            ],
        );
    }
}
