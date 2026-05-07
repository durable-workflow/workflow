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
            'docs/architecture/workflow-service-calls-architecture.md',
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

    public function testControlPlaneNamesServiceControlPlaneInterfaceAndMethods(): void
    {
        $controlPlane = ServiceExecutionContract::manifest()['control_plane'];

        $this->assertSame(ServiceControlPlane::class, $controlPlane['interface']);
        $this->assertSame(
            ['execute', 'describeCall', 'cancelCall'],
            array_keys($controlPlane['methods']),
        );
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
        $this->assertSame(
            'cancelled',
            $manifest['failure_reasons']['cancellation']['terminal_status'],
        );
    }

    public function testManifestNamesDurableServiceRecordsAndGuaranteedObservabilityFields(): void
    {
        $manifest = ServiceExecutionContract::manifest();

        $this->assertSame(
            ['endpoints', 'services', 'operations', 'calls'],
            array_keys($manifest['durable_records']),
        );
        $this->assertSame(
            ['handler_binding_kind', 'handler_target_reference', 'handler_binding'],
            $manifest['durable_records']['operations']['binding_fields'],
        );
        $this->assertContains(
            'resolved_binding_kind',
            $manifest['durable_records']['calls']['link_fields'],
        );
        $this->assertContains(
            'resolved_target_reference',
            $manifest['observability']['guaranteed_fields'],
        );
    }

    public function testExecutionRulesRequireDurableCallIdAndFailClosedUnknownBindings(): void
    {
        $rules = ServiceExecutionContract::manifest()['execution_rules'];

        $this->assertStringContainsString('durable service-call id', $rules['durable_call_id']);
        $this->assertStringContainsString('fail closed', $rules['unknown_binding_kind']);
        $this->assertStringContainsString('Raw transport logs are diagnostic only', $rules['transport_logs']);
    }
}
