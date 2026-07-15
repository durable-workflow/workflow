<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\HostedControlPlaneContract;
use Workflow\V2\Support\SurfaceStabilityContract;

/**
 * Pins the hosted-control-plane manifest mirrored by
 * `Workflow\V2\Support\HostedControlPlaneContract`.
 */
final class HostedControlPlaneContractTest extends TestCase
{
    public function testManifestAdvertisesAuthorityIdentity(): void
    {
        $manifest = HostedControlPlaneContract::manifest();

        $this->assertSame('durable-workflow.v2.hosted-control-plane.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame(
            'https://github.com/durable-workflow/workflow/blob/v2/docs/architecture/hosted-control-plane.md',
            $manifest['authority_doc'],
        );
        $this->assertSame('X-Durable-Workflow-Hosted-Control-Plane-Version', $manifest['protocol_header']);
    }

    public function testDeploymentLadderKeepsManagedCloudAdditive(): void
    {
        $ladder = HostedControlPlaneContract::manifest()['deployment_ladder'];

        $this->assertSame(['embedded_package', 'standalone_server', 'managed_cloud'], array_keys($ladder));
        $this->assertTrue($ladder['embedded_package']['runtime_authority']);
        $this->assertTrue($ladder['standalone_server']['runtime_authority']);
        $this->assertFalse(
            $ladder['managed_cloud']['runtime_authority'],
            'managed cloud owns hosted control-plane semantics, not runtime workflow facts',
        );
        $this->assertContains('worker_protocol', $ladder['managed_cloud']['must_not_change']);
        $this->assertContains('history_event_wire_formats', $ladder['managed_cloud']['must_not_change']);
    }

    public function testTenantHierarchyPinsNamespaceAsRuntimeBoundary(): void
    {
        $manifest = HostedControlPlaneContract::manifest();
        $hierarchy = $manifest['tenant_hierarchy'];

        $this->assertSame(
            ['organization', 'project', 'environment', 'namespace'],
            HostedControlPlaneContract::tenantHierarchyLevels(),
        );
        $this->assertSame(HostedControlPlaneContract::tenantHierarchyLevels(), $hierarchy['levels']);
        $this->assertSame('runtime_execution_boundary', $hierarchy['namespace_role']);
        $this->assertSame('one_namespace_belongs_to_one_runtime_target_at_a_time', $hierarchy['placement_rule']);
        $this->assertContains('runtime_target_base_url', $hierarchy['runtime_target_identity']);
        $this->assertContains('region', $hierarchy['runtime_target_identity']);
        $this->assertContains('residency_profile', $hierarchy['runtime_target_identity']);
    }

    public function testHostedIdentityRequiresRuntimeAttribution(): void
    {
        $identity = HostedControlPlaneContract::manifest()['identity_boundary'];

        foreach ([
            'hosted_user',
            'service_account',
            'worker_credential',
            'runtime_target_credential',
            'provider_support_actor',
        ] as $class) {
            $this->assertContains($class, $identity['identity_classes']);
        }

        foreach ([
            'actor_type',
            'capability',
            'target_namespace',
            'hosted_audit_id_or_request_fingerprint',
            'command_outcome',
        ] as $field) {
            $this->assertContains($field, $identity['runtime_attribution_fields']);
        }

        $this->assertContains('runtime_credentials_are_role_scoped', $identity['guarantees']);
    }

    public function testQuotaMeteringAndFairnessHaveMachineReadableRefusals(): void
    {
        $quota = HostedControlPlaneContract::manifest()['quota_metering_fairness'];

        $this->assertContains('namespace_command_rate', $quota['budget_names']);
        $this->assertContains('namespace_worker_poll_rate', $quota['budget_names']);
        $this->assertContains('task_queue_active_leases', $quota['budget_names']);
        $this->assertSame(
            ['quota_exceeded', 'metering_unavailable', 'fair_share_throttled', 'tenant_suspended', 'residency_blocked'],
            HostedControlPlaneContract::quotaRefusalReasons(),
        );

        foreach (['reason', 'budget', 'scope', 'retry_allowed'] as $field) {
            $this->assertContains($field, $quota['refusal_required_fields']);
        }
    }

    public function testRegionResidencyAndDrKeepOneActiveRuntimeTarget(): void
    {
        $region = HostedControlPlaneContract::manifest()['region_residency_dr'];

        $this->assertSame(
            'one_active_runtime_target_per_namespace_for_durable_writes',
            $region['active_writer_rule'],
        );
        $this->assertSame('residency_blocked', $region['residency_refusal_reason']);
        $this->assertContains('active_active_multi_region_execution', $region['deferred_guarantees']);
        $this->assertContains('automatic_cross_region_runtime_failover', $region['deferred_guarantees']);
    }

    public function testWorkerConnectivityModesNeverIntroduceASecondWorkerProtocol(): void
    {
        $modes = HostedControlPlaneContract::manifest()['worker_connectivity_modes'];

        $this->assertSame(
            ['direct_runtime', 'customer_private_network', 'provider_managed_workers', 'cloud_relay'],
            HostedControlPlaneContract::connectivityModes(),
        );
        $this->assertSame('stable', $modes['direct_runtime']['self_serve_status']);
        $this->assertSame('support_led', $modes['customer_private_network']['self_serve_status']);
        $this->assertSame('support_led', $modes['provider_managed_workers']['self_serve_status']);
        $this->assertSame('support_led', $modes['cloud_relay']['self_serve_status']);

        foreach ($modes as $name => $mode) {
            $this->assertSame('worker_protocol', $mode['protocol'], "{$name} must keep the standard worker protocol");
        }
    }

    public function testEndpointRoutingKeepsWorkerTrafficOnRuntimeWorkerEndpoint(): void
    {
        $routing = HostedControlPlaneContract::manifest()['endpoint_routing_rules'];

        $this->assertSame(
            ['hosted_control_plane', 'runtime_namespace_endpoint', 'runtime_worker_endpoint'],
            HostedControlPlaneContract::endpointClasses(),
        );
        $this->assertFalse($routing['hosted_control_plane']['owns_worker_traffic']);
        $this->assertFalse($routing['runtime_namespace_endpoint']['owns_worker_traffic']);
        $this->assertTrue($routing['runtime_worker_endpoint']['owns_worker_traffic']);
        $this->assertSame('always', $routing['runtime_namespace_endpoint']['namespace_required']);
        $this->assertSame('always', $routing['runtime_worker_endpoint']['namespace_required']);
    }

    public function testProviderAdminActionsAreAuditedAndCannotEditRuntimeHistory(): void
    {
        $actions = HostedControlPlaneContract::manifest()['provider_admin_actions'];

        $this->assertSame(
            [
                'provision_namespace',
                'attach_runtime_target',
                'move_namespace_target',
                'suspend_principal',
                'resume_principal',
                'rotate_machine_identity',
                'change_quota_budget',
                'export_audit_log',
                'support_access_session',
            ],
            HostedControlPlaneContract::providerAdminActionNames(),
        );

        foreach ($actions as $name => $action) {
            $this->assertTrue($action['audit_required'], "{$name} must be audited");
            $this->assertFalse(
                $action['runtime_history_edit_allowed'],
                "{$name} must not edit runtime history directly"
            );
        }
        $this->assertSame('disabled', $actions['support_access_session']['support_access']['default']);
        $this->assertSame('additive_overlap_before_revoke', $actions['rotate_machine_identity']['rotation_rule']);
    }

    public function testAuditExportBoundariesSeparateHostedAuditFromRuntimeHistory(): void
    {
        $boundaries = HostedControlPlaneContract::manifest()['audit_export_boundaries'];

        $this->assertSame('hosted_control_plane', $boundaries['hosted_audit_log']['authority']);
        $this->assertContains('provider_admin_actions', $boundaries['hosted_audit_log']['contains']);

        $this->assertSame('runtime_target', $boundaries['runtime_history_export']['authority']);
        $this->assertContains('workflow_history_events', $boundaries['runtime_history_export']['contains']);
        $this->assertContains('command_outcomes', $boundaries['runtime_history_export']['contains']);

        $this->assertTrue($boundaries['support_bundle']['redacted_by_default']);
    }

    public function testApiLifecycleDefersRuntimeTrafficToRuntimeProtocolAuthorities(): void
    {
        $lifecycle = HostedControlPlaneContract::manifest()['api_lifecycle'];

        $this->assertSame(HostedControlPlaneContract::SCHEMA, $lifecycle['schema']);
        $this->assertSame(HostedControlPlaneContract::VERSION, $lifecycle['version']);
        $this->assertSame(HostedControlPlaneContract::PROTOCOL_HEADER, $lifecycle['protocol_header']);
        $this->assertContains('new_connectivity_modes', $lifecycle['additive_minor_changes']);
        $this->assertContains('remove_or_rename_endpoint_class', $lifecycle['major_changes']);
        $this->assertContains('control_plane', $lifecycle['runtime_protocol_authorities']);
        $this->assertContains('worker_protocol', $lifecycle['runtime_protocol_authorities']);
        $this->assertContains(SurfaceStabilityContract::SCHEMA, $lifecycle['runtime_protocol_authorities']);
    }
}
