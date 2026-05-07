<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the hosted-control-plane contract documented in
 * docs/architecture/hosted-control-plane.md.
 */
final class HostedControlPlaneDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/hosted-control-plane.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Hosted Control-Plane Contract',
        '## Scope',
        '## Terminology',
        '## Deployment Ladder',
        '## Tenant Hierarchy Above Namespaces',
        '## Hosted IAM And Machine Identity',
        '## Quota, Metering, And Fairness',
        '## Region, Residency, And Disaster Recovery',
        '## Worker Connectivity Modes',
        '## Endpoint Routing Rules',
        '## Provider Support And Admin Actions',
        '## Control-Plane API Lifecycle',
        '## Audit And Export Boundaries',
        '## Test Strategy Alignment',
        '## What This Contract Does Not Yet Guarantee',
        '## Changing This Contract',
    ];

    private const REQUIRED_TERMS = [
        'Embedded package',
        'Standalone server',
        'Managed cloud',
        'Runtime target',
        'Tenant hierarchy',
        'Namespace endpoint',
        'Hosted control-plane endpoint',
        'Machine identity',
        'Quota budget',
        'Runtime placement',
        'Connectivity mode',
        'Provider admin action',
        'Audit export boundary',
    ];

    private const REQUIRED_LADDER_LEVELS = [
        'embedded_package',
        'standalone_server',
        'managed_cloud',
    ];

    private const REQUIRED_ENDPOINT_CLASSES = [
        'hosted_control_plane',
        'runtime_namespace_endpoint',
        'runtime_worker_endpoint',
    ];

    private const REQUIRED_QUOTA_REASONS = [
        'quota_exceeded',
        'metering_unavailable',
        'fair_share_throttled',
        'tenant_suspended',
        'residency_blocked',
    ];

    private const REQUIRED_CONNECTIVITY_MODES = [
        'direct_runtime',
        'customer_private_network',
        'provider_managed_workers',
        'cloud_relay',
    ];

    private const REQUIRED_ADMIN_ACTIONS = [
        'provision_namespace',
        'attach_runtime_target',
        'move_namespace_target',
        'suspend_principal',
        'resume_principal',
        'rotate_machine_identity',
        'change_quota_budget',
        'export_audit_log',
        'support_access_session',
    ];

    private const REQUIRED_AUDIT_BOUNDARIES = [
        'hosted_audit_log',
        'runtime_history_export',
        'support_bundle',
    ];

    public function testDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Hosted control-plane contract is missing heading %s.', $heading),
            );
        }
    }

    public function testDocumentDefinesEveryNamedTerm(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_TERMS as $term) {
            $this->assertStringContainsString(
                sprintf('**%s**', $term),
                $contents,
                sprintf('Hosted control-plane contract must define term %s.', $term),
            );
        }
    }

    public function testDocumentPinsDeploymentLadder(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_LADDER_LEVELS as $level) {
            $this->assertStringContainsString(
                sprintf('`%s`', $level),
                $contents,
                sprintf('Hosted control-plane contract must name ladder level %s.', $level),
            );
        }
        $this->assertStringContainsString(
            'The ladder is additive.',
            $contents,
            'Managed cloud must be documented as an additive packaging layer, not a runtime fork.',
        );
    }

    public function testDocumentPinsTenantHierarchyAboveNamespaces(): void
    {
        $contents = $this->documentContents();

        foreach (['organization', 'project', 'environment', 'namespace'] as $level) {
            $this->assertStringContainsString($level, $contents);
        }
        $this->assertMatchesRegularExpression(
            '/A namespace belongs to exactly one runtime target at a time/i',
            $contents,
            'Namespace placement must stay explicit.',
        );
    }

    public function testDocumentPinsEndpointClassesAndExplicitNamespaceRule(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_ENDPOINT_CLASSES as $endpointClass) {
            $this->assertStringContainsString(
                sprintf('`%s`', $endpointClass),
                $contents,
                sprintf('Hosted control-plane contract must name endpoint class %s.', $endpointClass),
            );
        }
        $this->assertMatchesRegularExpression(
            '/Runtime namespace and worker endpoints require explicit namespace\s+identity/i',
            $contents,
            'Runtime endpoints must not infer tenant scope from base URL alone.',
        );
    }

    public function testDocumentPinsQuotaReasonsConnectivityModesAdminActionsAndAuditBoundaries(): void
    {
        $contents = $this->documentContents();

        foreach (array_merge(
            self::REQUIRED_QUOTA_REASONS,
            self::REQUIRED_CONNECTIVITY_MODES,
            self::REQUIRED_ADMIN_ACTIONS,
            self::REQUIRED_AUDIT_BOUNDARIES,
        ) as $token) {
            $this->assertStringContainsString(
                sprintf('`%s`', $token),
                $contents,
                sprintf('Hosted control-plane contract must pin token %s.', $token),
            );
        }
    }

    public function testDocumentStatesWorkersUseRuntimeProtocolNotASecondWorkerApi(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Workers speak the runtime worker protocol/i',
            $contents,
            'Hosted control plane must not define a second worker API.',
        );
        $this->assertMatchesRegularExpression(
            '/MUST NOT become the authority\s+for task claims or task completion/i',
            $contents,
            'Hosted endpoints may not take over runtime task authority.',
        );
    }

    public function testDocumentPinsApiLifecycleAndManifestIdentity(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'Workflow\V2\Support\HostedControlPlaneContract',
            $contents,
        );
        $this->assertStringContainsString(
            'durable-workflow.v2.hosted-control-plane.contract',
            $contents,
        );
        $this->assertStringContainsString(
            'X-Durable-Workflow-Hosted-Control-Plane-Version',
            $contents,
        );
        $this->assertStringContainsString(
            'hosted_control_plane_contract',
            $contents,
        );
    }

    public function testDocumentSeparatesHostedAuditFromRuntimeHistoryExport(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/A hosted audit log never replaces runtime history/i',
            $contents,
            'Runtime history must remain the workflow-fact authority.',
        );
        $this->assertMatchesRegularExpression(
            '/Support bundles are redacted by default/i',
            $contents,
            'Support export default must be redacted.',
        );
    }

    public function testDocumentPinsItsOwnPinningTests(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'tests/Unit/V2/HostedControlPlaneContractTest.php',
            $contents,
        );
        $this->assertStringContainsString(
            'tests/Unit/V2/HostedControlPlaneDocumentationTest.php',
            $contents,
        );
    }

    private function documentContents(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::DOCUMENT;
        $contents = file_get_contents($path);

        $this->assertIsString($contents, sprintf('Could not read %s.', self::DOCUMENT));

        return $contents;
    }
}
