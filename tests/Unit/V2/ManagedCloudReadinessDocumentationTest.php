<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the managed-cloud readiness contract documented in
 * docs/workflow/plan.md. The section keeps the package, standalone
 * server, and managed cloud on one product ladder while naming the
 * hosted tenancy, IAM, networking, quota, lifecycle, and provider
 * operations contracts that must preserve the v2 durable kernel.
 */
final class ManagedCloudReadinessDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/workflow/plan.md';

    private const REQUIRED_PRODUCT_LADDER_PHRASES = [
        'Package, standalone server, and managed cloud are one product ladder.',
        'The package owns the durable kernel and the namespace-scoped runtime contract.',
        'The standalone server exposes that kernel over HTTP',
        'Managed cloud adds hosted tenancy, IAM, regional placement, private connectivity, quota, metering, and provider operations',
        'without replacing the durable homes or changing the worker semantics',
    ];

    private const REQUIRED_CONTRACT_HEADINGS = [
        '**Tenancy hierarchy.**',
        '**Control plane and data plane.**',
        '**Hosted IAM and machine identity.**',
        '**Reachability boundaries.**',
        '**Namespace connectivity rules.**',
        '**Namespace endpoint versus regional endpoint behavior.**',
        '**Quota, fairness, and metering.**',
        '**APS/OPS throttling versus API rate limits.**',
        '**Region, residency, replication, backup, restore, and DR.**',
        '**Private connectivity and HA/DR networking.**',
        '**Worker connectivity modes.**',
        '**Provider-side support and admin plane.**',
        '**Hosted control-plane API lifecycle.**',
        '**Namespace lifecycle controls.**',
    ];

    private const REQUIRED_TENANCY_AND_IDENTITY_PHRASES = [
        'organization -> project -> environment -> namespace',
        'The package persists and routes by namespace.',
        'The standalone server authenticates namespace-scoped API and worker requests.',
        'Organization users, service accounts, API keys, SSO, and SCIM',
        'stable principal subjects, scopes, roles, and audit facts',
        'MUST NOT bypass namespace authorization',
    ];

    private const REQUIRED_NETWORKING_AND_ENDPOINT_PHRASES = [
        'Control-plane APIs are reachable through hosted regional or global control endpoints.',
        'Namespace data-plane APIs are reachable only through the namespace endpoint',
        'A namespace connectivity rule is an allow-list policy',
        'SNI value',
        'mTLS and certificate-filter policy',
        'matches the namespace\'s configured certificate filters',
        'Private connectivity is namespace or environment scoped',
        'A failover cannot silently widen reachability.',
        'hosted workers',
        'customer-managed workers over public TLS',
        'private-link workers',
        'outbound tunnel or agent-based workers',
        'No worker-connectivity mode grants direct database access',
    ];

    private const REQUIRED_OPERATIONS_PHRASES = [
        'Quotas may attach at organization, project, environment, and namespace levels',
        'Metering records starts, tasks, activity attempts, worker minutes, history bytes, payload storage, API calls, and retained data',
        'APS/OPS throttles are worker-plane dispatch and operations budgets',
        'Control-plane API rate limits protect hosted API ingress.',
        'A control-plane `429` MUST NOT consume worker dispatch capacity',
        'An environment declares its allowed regions and residency boundary.',
        'Workflow history, payload references, backups, and metering data MUST NOT replicate outside the declared residency boundary',
        'The provider admin plane may inspect health, topology, quota, metering, audit, and support bundle metadata',
        'provider access requires an explicit support grant or documented emergency procedure',
        'Hosted control-plane APIs are versioned independently from package internals',
        'Deletion protection blocks destructive operations until explicitly disabled.',
        'Tags are non-authoritative metadata',
        'Certificate filters are namespace lifecycle state',
        'docs/deployment/ha-failover.md',
        'docs/deployment/multi-region.md',
        'tests/Unit/V2/ManagedCloudReadinessDocumentationTest.php',
    ];

    public function testPlanDocumentPinsManagedCloudProductLadder(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString('## Managed Cloud Readiness', $contents);

        foreach (self::REQUIRED_PRODUCT_LADDER_PHRASES as $phrase) {
            $this->assertStringContainsString(
                $phrase,
                $contents,
                sprintf('Managed-cloud readiness contract must pin product ladder phrase: %s.', $phrase),
            );
        }
    }

    public function testPlanDocumentNamesEveryManagedCloudContractHeading(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CONTRACT_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Managed-cloud readiness contract must include heading %s.', $heading),
            );
        }
    }

    public function testPlanDocumentPinsTenancyAndIdentityContracts(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_TENANCY_AND_IDENTITY_PHRASES as $phrase) {
            $this->assertStringContainsString(
                $phrase,
                $contents,
                sprintf('Managed-cloud readiness contract must pin tenancy or identity phrase: %s.', $phrase),
            );
        }
    }

    public function testPlanDocumentPinsReachabilityAndEndpointContracts(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_NETWORKING_AND_ENDPOINT_PHRASES as $phrase) {
            $this->assertStringContainsString(
                $phrase,
                $contents,
                sprintf('Managed-cloud readiness contract must pin networking phrase: %s.', $phrase),
            );
        }
    }

    public function testPlanDocumentPinsQuotaRegionAdminAndLifecycleContracts(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_OPERATIONS_PHRASES as $phrase) {
            $this->assertStringContainsString(
                $phrase,
                $contents,
                sprintf('Managed-cloud readiness contract must pin operations phrase: %s.', $phrase),
            );
        }
    }

    private function documentContents(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::DOCUMENT;
        $contents = file_get_contents($path);

        $this->assertIsString($contents, sprintf('Could not read %s.', self::DOCUMENT));

        return $contents;
    }
}
