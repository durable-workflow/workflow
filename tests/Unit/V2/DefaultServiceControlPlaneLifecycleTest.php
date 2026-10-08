<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Workflow\V2\Support\ServiceCallPrincipal;

final class DefaultServiceControlPlaneLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08T03:00:00Z');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('missingCatalog')]
    public function testPartialCatalogResolutionRecordsTheKnownTargetAndPrincipalWithoutDispatch(
        string $missing,
        string $principalShape,
        bool $configuredNamespace,
    ): void {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'billing',
            'endpoint_name' => 'billing',
        ]);
        $service = $missing === 'operation' ? WorkflowService::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoices',
        ]) : null;
        config([
            'workflows.v2.namespace' => $configuredNamespace ? ' billing ' : null,
        ]);
        $options = [
            'caller_namespace' => 'finance',
            'idempotency_key' => 'missing-invoice-42',
        ];
        if (! $configuredNamespace) {
            $options['target_namespace'] = ' billing ';
            $options['namespace'] = 'ignored-namespace';
        }
        $options += match ($principalShape) {
            'object' => [
                'principal' => new ServiceCallPrincipal(
                    subject: 'user:invoice-admin',
                    method: 'token',
                    roles: ['invoice-admin'],
                    tenant: 'tenant-42',
                    claims: [
                        'approved' => false,
                        'amount' => 0,
                    ],
                ),
            ],
            'array' => [
                'principal' => [
                    'subject' => 'user:invoice-admin',
                    'method' => 'token',
                    'roles' => ['invoice-admin', '', false, 42],
                    'tenant' => 'tenant-42',
                    'claims' => [
                        'approved' => false,
                        'amount' => 0,
                    ],
                ],
            ],
            default => [
                'principal_subject' => 'user:invoice-admin',
                'principal_method' => 'token',
                'principal_roles' => 'ignored scalar roles',
                'principal_tenant' => 'tenant-42',
                'principal_claims' => [
                    'approved' => false,
                    'amount' => 0,
                ],
            ],
        };
        $control = $this->control();
        $result = $control->execute('Billing', 'Invoices', 'Approve', $options);
        $call = WorkflowServiceCall::query()->sole();
        $message = 'Service contract component [' . $missing . '] did not resolve.';

        $this->assertFalse($result['accepted']);
        $this->assertFalse($result['idempotent_replay']);
        $this->assertSame($missing . '_not_found', $result['reason']);
        $this->assertSame($call->id, $result['service_call_id']);
        $this->assertSame(ServiceCallStatus::Failed->value, $call->status);
        $this->assertSame(ServiceCallOutcome::RejectedNotFound, $call->outcome);
        $this->assertSame('unknown_target', $call->outcome_reason);
        $this->assertSame($message, $call->failure_message);
        $this->assertSame($message, $result['message']);
        $this->assertSame('billing', $call->namespace);
        $this->assertSame('billing', $call->target_namespace);
        $this->assertSame('finance', $call->caller_namespace);
        $this->assertSame($endpoint->id, $call->workflow_service_endpoint_id);
        $this->assertSame($service?->id ?? 'unresolved', $call->workflow_service_id);
        $this->assertSame('unresolved', $call->workflow_service_operation_id);
        $this->assertSame('unresolved', $call->resolved_binding_kind);
        $this->assertNull($call->resolved_target_reference);
        $this->assertNull($call->accepted_at);
        $this->assertNull($call->started_at);
        $this->assertNotNull($call->failed_at);
        $this->assertSameJsonObject([
            'failure_reason' => ServiceCallFailureReason::ResolutionFailure->value,
            'resolution_failed_at' => $missing,
        ], $call->outcome_metadata);
        $this->assertSame('user:invoice-admin', $call->caller_principal_subject);
        $this->assertSame('token', $call->caller_principal_method);
        $this->assertSame($principalShape === 'options' ? null : ['invoice-admin'], $call->caller_principal_roles);
        $this->assertSame('tenant-42', $call->caller_principal_tenant);
        $this->assertSameJsonObject([
            'approved' => false,
            'amount' => 0,
        ], $call->caller_principal_claims);
        $before = $call->getRawOriginal();
        $this->beginReadObservation();
        $replay = $control->execute('billing', 'invoices', 'approve', $options);
        $this->assertTrue($replay['idempotent_replay']);
        $this->assertFalse($replay['accepted']);
        $this->assertSame($call->id, $replay['service_call_id']);
        $this->assertSame('unknown_target', $replay['outcome_reason']);
        $this->assertSame($message, $replay['message']);
        $this->assertSame($before, $call->fresh()->getRawOriginal());
        $this->assertSame(1, WorkflowServiceCall::query()->count());
        $this->assertNoDatabaseWrites();
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function missingCatalog(): iterable
    {
        foreach (['service', 'operation'] as $missing) {
            foreach (['object', 'array', 'options'] as $principal) {
                foreach ([true, false] as $configured) {
                    yield $missing . ' ' . $principal . ' ' . ($configured ? 'configured namespace' : 'target namespace')
                        => [$missing, $principal, $configured];
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('inspectionScopes')]
    public function testDescribeCallRespectsNamespaceSelectionWithoutChangingDurableState(
        array $options,
        ?string $configuredNamespace,
        bool $existingId,
        bool $found,
    ): void {
        $call = $this->serviceCall();
        config([
            'workflows.v2.namespace' => $configuredNamespace,
        ]);
        $id = $existingId ? $call->id : 'missing-service-call';
        $before = $call->fresh()
            ->getRawOriginal();
        $control = $this->control();
        $this->beginReadObservation();
        for ($repeat = 0; $repeat < 2; $repeat++) {
            $result = $control->describeCall(' ' . $id . ' ', $options);
            $this->assertSame($found, $result['found']);
            $this->assertSame($found ? $call->id : ' ' . $id . ' ', $result['service_call_id']);
            if ($found) {
                $this->assertSame('billing', $result['namespace']);
                $this->assertSame('finance', $result['caller_namespace']);
                $this->assertSame(ServiceCallStatus::Started->value, $result['status']);
                $this->assertSame(ServiceCallOutcome::Accepted->value, $result['outcome']);
                $this->assertSame('invoice-owner', $result['linked_workflow_instance_id']);
                $this->assertSame('invoice-run', $result['linked_workflow_run_id']);
                $this->assertSame([], $result['service_call_attempts']);
                $this->assertSame(0, $result['retry_attempt_count']);
            } else {
                $this->assertSame('service_call_not_found', $result['reason']);
                $this->assertNull($result['namespace']);
                $this->assertNull($result['caller_namespace']);
                $this->assertNull($result['status']);
                $this->assertNull($result['outcome']);
                $this->assertNull($result['linked_workflow_instance_id']);
            }
            $this->assertSame($before, $call->fresh()->getRawOriginal());
            $this->assertSame(1, WorkflowServiceCall::query()->count());
        }
        $this->assertNoDatabaseWrites();
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?string, bool, bool}>
     */
    public static function inspectionScopes(): iterable
    {
        yield 'explicit namespace' => [[
            'namespace' => ' billing ',
        ], 'finance', true, true];
        yield 'target namespace wins' => [[
            'target_namespace' => 'billing',
            'namespace' => 'finance',
        ], null, true, true];
        yield 'wrong target wins' => [[
            'target_namespace' => 'finance',
            'namespace' => 'billing',
        ], null, true, false];
        yield 'configured namespace' => [[], ' billing ', true, true];
        yield 'wrong configured namespace' => [[], 'finance', true, false];
        yield 'unscoped inspection' => [[], null, true, true];
        yield 'missing call id' => [[
            'namespace' => 'billing',
        ], null, false, false];
    }

    #[DataProvider('terminalStatuses')]
    public function testTerminalCallsRejectCancellationAndReplayWithoutChangingTheOutcome(
        ServiceCallStatus $status,
        ServiceCallOutcome $outcome,
    ): void {
        $terminalTimestamp = match ($status) {
            ServiceCallStatus::Completed => 'completed_at',
            ServiceCallStatus::Failed => 'failed_at',
            ServiceCallStatus::Cancelled => 'cancelled_at',
            default => throw new \LogicException('Expected a terminal service-call status.'),
        };
        $call = $this->serviceCall([
            'status' => $status->value,
            'outcome' => $outcome->value,
            $terminalTimestamp => now(),
        ]);
        $before = $call->fresh()
            ->getRawOriginal();
        $control = $this->control();
        $this->beginReadObservation();
        for ($repeat = 0; $repeat < 2; $repeat++) {
            $result = $control->cancelCall($call->id, [
                'namespace' => 'billing',
                'reason' => 'late cancellation',
            ]);
            $this->assertFalse($result['accepted']);
            $this->assertSame('service_call_terminal', $result['reason']);
            $this->assertSame($status->value, $result['status']);
            $this->assertSame($outcome->value, $result['outcome']);
            $replay = $control->execute('billing', 'invoices', 'approve', [
                'namespace' => 'billing',
                'service_call_id' => $call->id,
            ]);
            $this->assertSame($status === ServiceCallStatus::Completed, $replay['accepted']);
            $this->assertSame($status->value, $replay['status']);
            $this->assertSame($outcome->value, $replay['outcome']);
            $this->assertSame($before, $call->fresh()->getRawOriginal());
        }
        $this->assertNoDatabaseWrites();
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{ServiceCallStatus, ServiceCallOutcome}>
     */
    public static function terminalStatuses(): iterable
    {
        yield 'completed' => [ServiceCallStatus::Completed, ServiceCallOutcome::Completed];
        yield 'failed' => [ServiceCallStatus::Failed, ServiceCallOutcome::HandlerFailed];
        yield 'cancelled' => [ServiceCallStatus::Cancelled, ServiceCallOutcome::Cancelled];
    }

    /**
     * @param array<string, mixed> $policy
     */
    #[DataProvider('blockedCancellation')]
    public function testCancellationPolicyDenialPreservesAnOpenCallWithoutLinkedDispatch(array $policy): void
    {
        $call = $this->serviceCall([
            'cancellation_policy' => $policy + [
                'propagate_to_linked_workflow' => true,
            ],
        ]);
        $before = $call->fresh()
            ->getRawOriginal();
        $control = $this->control();
        $this->beginReadObservation();
        for ($repeat = 0; $repeat < 2; $repeat++) {
            $result = $control->cancelCall($call->id, [
                'namespace' => 'billing',
            ]);
            $this->assertFalse($result['accepted']);
            $this->assertSame('cancellation_not_allowed', $result['reason']);
            $this->assertSame(ServiceCallStatus::Started->value, $result['status']);
            $this->assertSame(ServiceCallOutcome::Accepted->value, $result['outcome']);
            $this->assertNull($result['cancelled_at']);
            $this->assertSame($before, $call->fresh()->getRawOriginal());
        }
        $this->assertNoDatabaseWrites();
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function blockedCancellation(): iterable
    {
        yield 'allow_cancel false' => [[
            'allow_cancel' => false,
        ]];
        yield 'cancellable false' => [[
            'cancellable' => false,
        ]];
        yield 'allow_external_cancel false' => [[
            'allow_external_cancel' => false,
        ]];
        yield 'mode none' => [[
            'mode' => 'none',
        ]];
    }

    #[DataProvider('missingCancellation')]
    public function testCancellationCannotMutateACallOutsideTheSelectedNamespace(bool $existingId): void
    {
        $call = $this->serviceCall();
        $before = $call->fresh()
            ->getRawOriginal();
        $id = $existingId ? $call->id : 'missing-service-call';
        $control = $this->control();
        $this->beginReadObservation();
        $result = $control->cancelCall($id, [
            'namespace' => 'finance',
            'reason' => 'wrong namespace',
        ]);
        $this->assertFalse($result['accepted']);
        $this->assertSame($id, $result['service_call_id']);
        $this->assertSame('finance', $result['namespace']);
        $this->assertSame('service_call_not_found', $result['reason']);
        $this->assertNull($result['status']);
        $this->assertNull($result['linked_workflow_instance_id']);
        $this->assertNull($result['linked_workflow_run_id']);
        $this->assertNull($result['linked_workflow_update_id']);
        $this->assertSame($before, $call->fresh()->getRawOriginal());
        $this->assertSame(1, WorkflowServiceCall::query()->count());
        $this->assertNoDatabaseWrites();
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function missingCancellation(): iterable
    {
        yield 'unknown id' => [false];
        yield 'known id in another namespace' => [true];
    }

    #[DataProvider('propagatedCancellation')]
    public function testCancellationPropagatesOnceAndRetainsOnlyPublicLinkedOutcome(
        ?string $reason,
        bool $linkedAccepted,
    ): void {
        $call = $this->serviceCall([
            'cancellation_policy' => [
                'propagate_to_linked_workflow' => true,
            ],
            'metadata' => [
                'invoice' => 42,
                'approved' => false,
            ],
        ]);
        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $forwarded = [];
        $surface = [
            'accepted' => $linkedAccepted,
            'reason' => $linkedAccepted ? null : 'already_terminal',
            'command_id' => 'cancel-invoice-42',
            'result' => [
                'private' => 'result material',
            ],
            'payload_blob' => 'private payload material',
            'arguments' => [42, false],
        ];
        $workflows->shouldReceive('cancel')
            ->once()
            ->andReturnUsing(
                static function (string $instanceId, array $options) use (&$forwarded, $surface): array {
                    $forwarded = [$instanceId, $options];
                    return $surface;
                }
            );
        $control = new DefaultServiceControlPlane($workflows, new DefaultServiceBoundaryPolicy());
        $options = [
            'target_namespace' => 'billing',
            'namespace' => 'finance',
        ];
        if ($reason !== null) {
            $options['reason'] = $reason;
        }
        $result = $control->cancelCall($call->id, $options);
        $call->refresh();
        $this->assertTrue($result['accepted']);
        $this->assertNull($result['reason']);
        $this->assertSame([
            'invoice-owner', [
                'namespace' => 'billing',
                'reason' => $reason === null
                            ? 'service_call_cancelled' : trim($reason),
            ]], $forwarded);
        $this->assertSame(ServiceCallStatus::Cancelled->value, $call->status);
        $this->assertSame(ServiceCallOutcome::Cancelled, $call->outcome);
        $this->assertSame(ServiceCallOutcome::Cancelled->category(), $call->outcome_category);
        $this->assertSame('cancelled_by_request', $call->outcome_reason);
        $this->assertSame($reason === null ? null : trim($reason), $call->outcome_message);
        $this->assertSame($reason === null ? null : trim($reason), $call->failure_message);
        $this->assertSameJsonObject([
            'failure_reason' => ServiceCallFailureReason::Cancellation->value,
        ], $call->outcome_metadata);
        $this->assertSameJsonObject([
            'invoice' => 42,
            'approved' => false,
            'linked_cancel' => [
                'accepted' => $linkedAccepted,
                'reason' => $linkedAccepted ? null : 'already_terminal',
                'command_id' => 'cancel-invoice-42',
            ],
        ], $call->metadata);
        $this->assertNotNull($call->cancelled_at);
        $this->assertNull($call->completed_at);
        $this->assertNull($call->failed_at);
        $before = $call->getRawOriginal();
        Carbon::setTestNow('2026-10-08T03:05:00Z');
        $this->beginReadObservation();
        $repeated = $control->cancelCall($call->id, $options);
        $this->assertFalse($repeated['accepted']);
        $this->assertSame('service_call_terminal', $repeated['reason']);
        $this->assertSame($result['cancelled_at'], $repeated['cancelled_at']);
        $this->assertSame($before, $call->fresh()->getRawOriginal());
        $this->assertNoDatabaseWrites();
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function propagatedCancellation(): iterable
    {
        foreach ([null, ' caller withdrew invoice approval '] as $reason) {
            foreach ([true, false] as $accepted) {
                yield ($reason === null ? 'default reason' : 'caller reason') . ' ' . ($accepted ? 'accepted' : 'terminal linked run')
                    => [$reason, $accepted];
            }
        }
    }

    public function testExistingPendingCallInfersItsTargetNamespaceAndPreservesTheOriginalPrincipal(): void
    {
        $call = $this->serviceCall([
            'status' => ServiceCallStatus::Pending->value,
            'outcome' => null,
            'accepted_at' => null,
            'started_at' => null,
            'resolved_binding_kind' => 'unresolved',
            'resolved_target_reference' => null,
            'linked_workflow_instance_id' => null,
            'linked_workflow_run_id' => null,
            'caller_principal_subject' => 'user:original',
            'caller_principal_method' => 'token',
            'caller_principal_roles' => ['invoice-admin'],
        ]);
        config([
            'workflows.v2.namespace' => null,
        ]);
        $result = $this->control()
            ->execute('billing', 'invoices', 'approve', [
                'service_call_id' => $call->id,
                'dispatch_handler' => false,
            ]);
        $call->refresh();
        $this->assertTrue($result['accepted']);
        $this->assertFalse($result['idempotent_replay']);
        $this->assertSame($call->id, $result['service_call_id']);
        $this->assertSame('billing', $call->namespace);
        $this->assertSame('billing', $call->target_namespace);
        $this->assertSame('finance', $call->caller_namespace);
        $this->assertSame('user:original', $call->caller_principal_subject);
        $this->assertSame('token', $call->caller_principal_method);
        $this->assertSame(['invoice-admin'], $call->caller_principal_roles);
        $this->assertSame(ServiceCallStatus::Accepted->value, $call->status);
        $this->assertSame(ServiceCallOutcome::Accepted, $call->outcome);
        $this->assertNotNull($call->accepted_at);
        $this->assertNull($call->started_at);
        $this->assertSame(1, WorkflowServiceCall::query()->count());
        Queue::assertNothingPushed();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function serviceCall(array $attributes = []): WorkflowServiceCall
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'billing',
            'endpoint_name' => 'billing',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoices',
        ]);
        $operation = WorkflowServiceOperation::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'approve',
            'operation_mode' => ServiceCallOperationMode::Async->value,
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
            'handler_target_reference' => 'invoice-workflow',
        ]);

        return WorkflowServiceCall::query()->create($attributes + [
            'namespace' => 'billing',
            'target_namespace' => 'billing',
            'caller_namespace' => 'finance',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'workflow_service_operation_id' => $operation->id,
            'endpoint_name' => 'billing',
            'service_name' => 'invoices',
            'operation_name' => 'approve',
            'operation_mode' => ServiceCallOperationMode::Async->value,
            'status' => ServiceCallStatus::Started->value,
            'outcome' => ServiceCallOutcome::Accepted->value,
            'resolved_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
            'resolved_target_reference' => 'invoice-run',
            'linked_workflow_instance_id' => 'invoice-owner',
            'linked_workflow_run_id' => 'invoice-run',
            'accepted_at' => now(),
            'started_at' => now(),
        ]);
    }

    private function control(): DefaultServiceControlPlane
    {
        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        return new DefaultServiceControlPlane($workflows, new DefaultServiceBoundaryPolicy());
    }

    private function beginReadObservation(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
    }

    private function assertNoDatabaseWrites(): void
    {
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression(
                '/\A\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i',
                $query['query']
            );
        }
    }
}
