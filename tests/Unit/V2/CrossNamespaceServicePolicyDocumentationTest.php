<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\PayloadEnvelopeResolver;

/**
 * Keeps the policy document discoverable while pinning its machine-owned
 * identifiers to enums, models, tables, casts, and runtime APIs. The Markdown
 * body is intentionally not parsed so editorial changes remain independent.
 */
final class CrossNamespaceServicePolicyDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/cross-namespace-service-policy.md';

    public function testContractDocumentExists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 3) . '/' . self::DOCUMENT);
    }

    public function testRuntimeReusesCodecAndDataConverterAuthorities(): void
    {
        $this->assertTrue(method_exists(PayloadEnvelopeResolver::class, 'resolveToArray'));
        $this->assertTrue(method_exists(CodecRegistry::class, 'resolve'));
        $this->assertTrue(interface_exists(ExternalPayloadStorageDriver::class));
    }

    public function testServiceCallOutcomeEnumMatchesPolicyOutcomeTaxonomy(): void
    {
        $this->assertSame(
            [
                'accepted',
                'completed',
                'cancelled',
                'timed_out',
                'rejected_not_found',
                'rejected_forbidden',
                'rejected_throttled',
                'rejected_concurrency_limited',
                'rejected_circuit_open',
                'degraded',
                'handler_failed',
            ],
            array_map(static fn (ServiceCallOutcome $outcome): string => $outcome->value, ServiceCallOutcome::cases()),
        );
        $this->assertTrue(ServiceCallOutcome::RejectedForbidden->isBoundaryRejection());
        $this->assertFalse(ServiceCallOutcome::HandlerFailed->isBoundaryRejection());
    }

    public function testServiceModelsUseTheirContractTables(): void
    {
        $this->assertSame('workflow_service_endpoints', (new WorkflowServiceEndpoint())->getTable());
        $this->assertSame('workflow_services', (new WorkflowService())->getTable());
        $this->assertSame('workflow_service_operations', (new WorkflowServiceOperation())->getTable());
        $this->assertSame('workflow_service_calls', (new WorkflowServiceCall())->getTable());
    }

    public function testServiceCallModelCastsPolicySnapshotColumns(): void
    {
        $casts = (new WorkflowServiceCall())->getCasts();

        $this->assertSame(ServiceCallOutcome::class, $casts['outcome'] ?? null);

        foreach (
            [
                'deadline_policy',
                'idempotency_policy',
                'cancellation_policy',
                'retry_policy',
                'boundary_policy',
                'metadata',
            ] as $policyColumn
        ) {
            $this->assertArrayHasKey($policyColumn, $casts);
        }
    }

    public function testCatalogModelsCastBoundaryPolicyColumns(): void
    {
        foreach (
            [
                WorkflowServiceEndpoint::class,
                WorkflowService::class,
                WorkflowServiceOperation::class,
            ] as $modelClass
        ) {
            $this->assertArrayHasKey('boundary_policy', (new $modelClass())->getCasts());
        }
    }
}
