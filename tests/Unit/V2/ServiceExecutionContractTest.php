<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallFailureReason;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowServiceCallDispatch;
use Workflow\V2\Support\ServiceExecutionContract;

/**
 * Pins the service execution manifest exported by workflow-server under
 * `service_execution_contract` from `GET /api/cluster/info`.
 */
final class ServiceExecutionContractTest extends TestCase
{
    public function testManifestAdvertisesAuthorityIdentity(): void
    {
        $manifest = ServiceExecutionContract::manifest();

        $this->assertSame('durable-workflow.v2.service-execution.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('service_execution_contract', $manifest['cluster_info_key']);
        $this->assertSame('service_execution', $manifest['capability_flag']);
        $this->assertSame(
            'https://github.com/durable-workflow/workflow/blob/main/docs/architecture/workflow-service-calls-architecture.md',
            $manifest['authority_document'],
        );
    }

    public function testManifestMethodIsPublicStatic(): void
    {
        $reflection = new ReflectionClass(ServiceExecutionContract::class);
        $method = $reflection->getMethod('manifest');

        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    public function testManifestPublishesTheOriginalCallDeadlineContract(): void
    {
        $call = ServiceExecutionContract::manifest()['durable_records']['calls'];
        $this->assertSame(['accepted_at', 'call_deadline_at'], $call['deadline_fields']);
        $deadline = $call['deadline_contract'];
        $this->assertSame(['timeout_seconds', 'deadline_at'], $deadline['policy_forms']);
        $this->assertSame([1, 2_147_483_647], $deadline['timeout_seconds_range']);
        $this->assertTrue($deadline['resolved_deadline_immutable']);
        $this->assertTrue($deadline['null_policy_inherits_linked_target']);
        $this->assertFalse($deadline['api_poll_required_for_expiry']);
        $this->assertTrue($deadline['expiry_wins_at_boundary']);
        $this->assertSame('failed', $deadline['terminal_status']);
        $this->assertSame('timed_out', $deadline['terminal_outcome']);
        $this->assertTrue($deadline['target_cancellation_is_separate']);
    }

    public function testControlPlaneNamesServiceControlPlaneInterfaceAndMethods(): void
    {
        $controlPlane = ServiceExecutionContract::manifest()['control_plane'];

        $this->assertSame(ServiceControlPlane::class, $controlPlane['interface']);
        $this->assertSame(['execute', 'describeCall', 'cancelCall'], array_keys($controlPlane['methods']));
        $this->assertSame(
            ['endpoint_name', 'service_name', 'operation_name'],
            $controlPlane['methods']['execute']['input_address_fields'],
        );
    }

    public function testHandlerBindingKindsMatchFrozenCallerFacingAdapterCodes(): void
    {
        $manifest = ServiceExecutionContract::manifest();

        $this->assertSame(
            [
                'start_workflow',
                'signal_workflow',
                'update_workflow',
                'query_workflow',
                'activity_execution',
                'invocable_http',
            ],
            $manifest['handler_binding_kinds'],
        );
    }

    public function testResolvedTargetBindingKindsFollowRuntimeEnumValues(): void
    {
        $manifest = ServiceExecutionContract::manifest();
        $expected = array_map(
            static fn (ServiceCallBindingKind $kind): string => $kind->value,
            ServiceCallBindingKind::cases(),
        );

        $this->assertSame($expected, array_keys($manifest['resolved_target_binding_kinds']));
        $this->assertSame($expected, ServiceExecutionContract::resolvedTargetBindingKindValues());
    }

    public function testRuntimeBindingKindEnumNormalizesHandlerAdapterAliases(): void
    {
        $this->assertSame(
            ServiceCallBindingKind::WorkflowRun,
            ServiceCallBindingKind::tryFromHandlerBindingKind('start_workflow'),
        );
        $this->assertSame(
            ServiceCallBindingKind::WorkflowRun,
            ServiceCallBindingKind::tryFromHandlerBindingKind('workflow_class'),
        );
        $this->assertSame(
            ServiceCallBindingKind::WorkflowUpdate,
            ServiceCallBindingKind::tryFromHandlerBindingKind('update_workflow'),
        );
        $this->assertSame(
            ServiceCallBindingKind::WorkflowSignal,
            ServiceCallBindingKind::tryFromHandlerBindingKind('signal_workflow'),
        );
        $this->assertSame(
            ServiceCallBindingKind::WorkflowQuery,
            ServiceCallBindingKind::tryFromHandlerBindingKind('query_workflow'),
        );
        $this->assertSame(
            ServiceCallBindingKind::InvocableCarrierRequest,
            ServiceCallBindingKind::tryFromHandlerBindingKind('invocable_http'),
        );
        $this->assertNull(ServiceCallBindingKind::tryFromHandlerBindingKind('unknown_handler'));
    }

    public function testOperationModesFollowRuntimeEnumValues(): void
    {
        $manifest = ServiceExecutionContract::manifest();
        $expected = array_map(
            static fn (ServiceCallOperationMode $mode): string => $mode->value,
            ServiceCallOperationMode::cases(),
        );

        $this->assertSame($expected, array_keys($manifest['operation_modes']));
        $this->assertSame($expected, ServiceExecutionContract::operationModeValues());
        $this->assertTrue($manifest['operation_modes']['sync']['blocks_for_terminal_result']);
        $this->assertFalse($manifest['operation_modes']['sync']['returns_durable_reference']);
        $this->assertTrue(
            $manifest['operation_modes']['sync_with_durable_reference']['may_return_terminal_result_inline'],
        );
        $this->assertTrue($manifest['operation_modes']['async']['returns_durable_reference']);
    }

    public function testLifecycleStatusesFollowRuntimeEnumValuesAndBuckets(): void
    {
        $manifest = ServiceExecutionContract::manifest();
        $expected = array_map(
            static fn (ServiceCallStatus $status): string => $status->value,
            ServiceCallStatus::cases(),
        );

        $this->assertSame($expected, array_keys($manifest['lifecycle_statuses']));
        $this->assertSame($expected, ServiceExecutionContract::lifecycleStatusValues());
        $this->assertFalse($manifest['lifecycle_statuses']['pending']['terminal']);
        $this->assertTrue($manifest['lifecycle_statuses']['completed']['terminal']);
        $this->assertSame('failed', $manifest['lifecycle_statuses']['failed']['bucket']);
    }

    public function testOutcomesFollowRuntimeEnumValuesAndCategories(): void
    {
        $manifest = ServiceExecutionContract::manifest();
        $expected = array_map(
            static fn (ServiceCallOutcome $outcome): string => $outcome->value,
            ServiceCallOutcome::cases(),
        );

        $this->assertSame($expected, array_keys($manifest['outcomes']));
        $this->assertSame($expected, ServiceExecutionContract::outcomeValues());
        $this->assertFalse($manifest['outcomes']['accepted']['terminal']);
        $this->assertTrue($manifest['outcomes']['rejected_forbidden']['boundary_rejection']);
        $this->assertSame('handler', $manifest['outcomes']['handler_failed']['category']);
    }

    public function testFailureReasonsFollowRuntimeEnumValues(): void
    {
        $manifest = ServiceExecutionContract::manifest();
        $expected = array_map(
            static fn (ServiceCallFailureReason $reason): string => $reason->value,
            ServiceCallFailureReason::cases(),
        );

        $this->assertSame($expected, array_keys($manifest['failure_reasons']));
        $this->assertSame($expected, ServiceExecutionContract::failureReasonValues());
        $this->assertSame('cancelled', $manifest['failure_reasons']['cancellation']['terminal_status']);
    }

    public function testManifestNamesDurableServiceRecordsAndGuaranteedObservabilityFields(): void
    {
        $manifest = ServiceExecutionContract::manifest();

        $this->assertSame(
            ['endpoints', 'services', 'operations', 'calls', 'dispatches'],
            array_keys($manifest['durable_records']),
        );
        $this->assertSame(
            ['handler_binding_kind', 'handler_target_reference', 'handler_binding'],
            $manifest['durable_records']['operations']['binding_fields'],
        );
        $this->assertContains('resolved_binding_kind', $manifest['durable_records']['calls']['link_fields']);
        $this->assertContains('resolved_target_reference', $manifest['observability']['guaranteed_fields']);
        $dispatch = $manifest['durable_records']['dispatches'];
        $this->assertSame(WorkflowServiceCallDispatch::class, $dispatch['model']);
        $this->assertSame('workflow_service_call_dispatches', $dispatch['table']);
        $this->assertSame(['scheduled', 'claimed', 'completed'], $dispatch['states']);
        $this->assertSame(
            ['claim_generation', 'claim_token', 'claimed_at', 'claim_expires_at'],
            $dispatch['claim_fence_fields']
        );
    }

    public function testExecutionRulesRequireDurableCallIdAndFailClosedUnknownBindings(): void
    {
        $rules = ServiceExecutionContract::manifest()['execution_rules'];

        $this->assertStringContainsString('durable service-call id', $rules['durable_call_id']);
        $this->assertStringContainsString('fail closed', $rules['unknown_binding_kind']);
        $this->assertStringContainsString('does not execute a handler', $rules['transaction_dispatch']);
        $this->assertStringContainsString('current catalog', $rules['claim_authority']);
        $this->assertStringContainsString('generation and token', $rules['attempt_fence']);
        $this->assertStringContainsString('at least once', $rules['repair_delivery']);
        $this->assertStringContainsString('Raw transport logs are diagnostic only', $rules['transport_logs']);
    }
}
