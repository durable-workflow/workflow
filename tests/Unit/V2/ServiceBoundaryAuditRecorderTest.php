<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Workflow\V2\Support\ServiceCallIdempotency;
use Workflow\V2\Support\ServiceCallPrincipal;
use Workflow\V2\TaskWatchdog;

final class ServiceBoundaryAuditRecorderTest extends TestCase
{
    public function testPreparedAdmissionDoesNotPersistBeforeItsHostClaimsTheCanonicalKey(): void
    {
        [, , $operation] = $this->seedCatalog();
        $call = (new ServiceBoundaryAuditRecorder())->make(
            $this->request($operation),
            ServiceBoundaryDecision::allow()
        );
        $this->assertFalse($call->exists);
        $this->assertSame(0, WorkflowServiceCall::query()->count());
        $this->assertSame($operation->id, $call->workflow_service_operation_id);
        $this->assertSame('analytics', $call->caller_namespace);
    }

    #[DataProvider('finalAdmissionDecisions')]
    public function testTheCanonicalPendingRowIsFinalizedWithoutAnotherAuditIdentity(bool $allowed): void
    {
        [, , $operation] = $this->seedCatalog();
        $request = $this->request($operation);
        $recorder = new ServiceBoundaryAuditRecorder();
        $deny = ServiceBoundaryDecision::denyNamespacePolicy(reason: 'caller_namespace_denied', message: 'Denied.');
        DB::transaction(function () use ($request, $recorder, $deny, $allowed): void {
            $call = $recorder->make($request, $allowed ? $deny : ServiceBoundaryDecision::allow());
            $call->status = 'pending';
            $call = ServiceCallIdempotency::save($call, ['finance', 'billing', 'invoicing', 'create', 'invoice-1']);
            $id = $call->id;
            $digest = $call->idempotency_scope_hash;
            $result = $recorder->record($request, $allowed ? ServiceBoundaryDecision::allow() : $deny, $call);
            $this->assertSame($id, $result->id);
            $this->assertSame($digest, $result->idempotency_scope_hash);
            $this->assertSame('invoice-1', $result->idempotency_key);
            $this->assertSame($allowed ? 'accepted' : 'failed', $result->status);
            $this->assertSame($allowed, $result->accepted_at !== null);
            $this->assertSame(! $allowed, $result->failed_at !== null);
            $this->assertSame($allowed ? null : 'Denied.', $result->failure_message);
            $this->assertSame(1, WorkflowServiceCall::query()->count());
        });
        $this->assertSame(1, WorkflowServiceCall::query()->count());
    }

    public static function finalAdmissionDecisions(): iterable
    {
        yield 'allow' => [true];
        yield 'deny' => [false];
    }

    public function testACommittedAdmissionCannotBeRewrittenThroughThePreparedRowApi(): void
    {
        [, , $operation] = $this->seedCatalog();
        $request = $this->request($operation);
        $recorder = new ServiceBoundaryAuditRecorder();
        $call = $recorder->record($request, ServiceBoundaryDecision::allow())->refresh();
        $before = $call->getRawOriginal();
        $this->expectException(LogicException::class);
        try {
            $recorder->record($request, ServiceBoundaryDecision::denyAuthorization(reason: 'denied'), $call);
        } finally {
            $this->assertSame($before, $call->fresh()->getRawOriginal());
        }
    }

    public function testAnotherCallerCannotFinalizeThePreparedCanonicalRow(): void
    {
        [, , $operation] = $this->seedCatalog();
        $recorder = new ServiceBoundaryAuditRecorder();
        $this->expectException(LogicException::class);
        try {
            DB::transaction(function () use ($recorder, $operation): void {
                $call = $recorder->make($this->request($operation), ServiceBoundaryDecision::allow());
                $call->status = 'pending';
                $call = ServiceCallIdempotency::save($call, ['finance', 'billing', 'invoicing', 'create', 'invoice-1']);
                $recorder->record(
                    $this->request($operation, callerNamespace: 'foreign'),
                    ServiceBoundaryDecision::allow(),
                    $call
                );
            });
        } finally {
            $this->assertSame(0, WorkflowServiceCall::query()->count());
        }
    }

    #[DataProvider('committedPendingRows')]
    public function testACommittedPendingRowCannotBeAdoptedAsANewAdmission(bool $reload): void
    {
        [, , $operation] = $this->seedCatalog();
        $recorder = new ServiceBoundaryAuditRecorder();
        $request = $this->request($operation);
        $call = $recorder->make($request, ServiceBoundaryDecision::allow());
        $call->status = 'pending';
        $call->save();
        $call->refresh();
        $before = $call->getRawOriginal();
        $this->expectException(LogicException::class);
        try {
            if ($reload) {
                DB::transaction(static fn () => $recorder->record($request, ServiceBoundaryDecision::allow(), $call->fresh()));
            } else {
                $recorder->record($request, ServiceBoundaryDecision::allow(), $call);
            }
        } finally {
            $this->assertSame($before, $call->fresh()->getRawOriginal());
        }
    }

    public static function committedPendingRows(): iterable
    {
        yield 'retained model after commit' => [false];
        yield 'reloaded in a later transaction' => [true];
    }

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
