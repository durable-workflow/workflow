<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
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
use Workflow\V2\TaskWatchdog;

final class ServiceBoundaryAuditRecorderTest extends TestCase
{
    public function testAdmissionPersistsTheOriginalDeadlineBeforeHandlerDispatchAndRepairExpiresIt(): void
    {
        Carbon::setTestNow('2026-10-11T00:00:00.123456Z');
        try {
            [, , $operation] = $this->seedCatalog();
            $request = $this->request($operation, deadlinePolicy: [
                'timeout_seconds' => 1,
            ]);
            $call = (new ServiceBoundaryAuditRecorder())->record($request, ServiceBoundaryDecision::allow())->refresh();
            $this->assertSame('accepted', $call->status);
            $this->assertSame('2026-10-11T00:00:00.123456Z', $call->accepted_at?->toJSON());
            $this->assertSame('2026-10-11T00:00:01.123456Z', $call->call_deadline_at?->toJSON());
            $this->assertNull($call->linked_workflow_run_id);
            Carbon::setTestNow('2026-10-11T00:00:01.123456Z');
            $report = TaskWatchdog::runPass();
            $this->assertSame(1, $report['service_call_timeouts_enforced']);
            $this->assertSame([], $report['service_call_timeout_failures']);
            $this->assertSame(ServiceCallOutcome::TimedOut, $call->refresh()->outcome);
            $this->assertSame('2026-10-11T00:00:01.123456Z', $call->call_deadline_at?->toJSON());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAnInvalidDeadlineCannotCreateAnAcceptedAuditRow(): void
    {
        [, , $operation] = $this->seedCatalog();
        $request = $this->request($operation, deadlinePolicy: [
            'timeout_seconds' => '1',
        ]);
        $this->expectException(InvalidArgumentException::class);
        try {
            (new ServiceBoundaryAuditRecorder())->record($request, ServiceBoundaryDecision::allow());
        } finally {
            $this->assertSame(0, WorkflowServiceCall::query()->count());
        }
    }

    public function testPersistsAnAdmittedCallWithFullPrincipalAttribution(): void
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
        $this->assertNull($call->call_deadline_at);
        $this->assertNull($call->failed_at);
        $this->assertSame($operation->id, $call->workflow_service_operation_id);
    }

    public function testPersistsARejectedCallWithTheSameAuditShape(): void
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

    public function testPersistsDecisionMetadataAndRetryAdvice(): void
    {
        [, , $operation] = $this->seedCatalog();

        $decision = ServiceBoundaryDecision::denyRateLimit(
            retryAfterSeconds: 7,
            message: 'rate limited',
            metadata: [
                'observed_window_count' => 12,
                'requests_per_minute' => 10,
            ],
        );

        $call = (new ServiceBoundaryAuditRecorder())
            ->record($this->request($operation), $decision);

        $this->assertSame(7, $call->retry_after_seconds);
        $this->assertSame(12, $call->outcome_metadata['observed_window_count']);
        $this->assertSame(10, $call->outcome_metadata['requests_per_minute']);
    }

    public function testUnresolvedTargetIsRecordedWithUniformNotFoundOutcome(): void
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
        ?array $deadlinePolicy = null,
    ): ServiceBoundaryRequest {
        return new ServiceBoundaryRequest(
            principal: new ServiceCallPrincipal(
                subject: 'user:tester',
                method: 'token',
                roles: ['service.call'],
                tenant: 'acme',
                claims: [
                    'scope' => 'invoices.write',
                ],
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
            deadlinePolicy: $deadlinePolicy,
            operationBoundaryPolicy: [
                'authorization' => [
                    'caller_namespaces' => [
                        'allow' => ['analytics'],
                    ],
                ],
            ],
        );
    }
}
